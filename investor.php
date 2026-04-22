<?php
// ============================================================
// قِنوان — investor.php  (US-Inv-01 to US-20)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'investor') {
    header('Location: login.php'); exit;
}

$pdo         = getDB();
$investor_id = (int)$_SESSION['investor_id'];
$first_name  = $_SESSION['first_name'];
$last_name   = $_SESSION['last_name'] ?? '';

// ── Handle AJAX / POST actions ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    // US-09: Toggle wishlist
    if ($act === 'toggle_wishlist') {
        $farm_id = (int)($_POST['farm_id'] ?? 0);
        $exists  = $pdo->prepare("SELECT wishlist_id FROM qw_wishlist WHERE investor_id=? AND farm_id=?");
        $exists->execute([$investor_id, $farm_id]);
        if ($exists->fetch()) {
            $pdo->prepare("DELETE FROM qw_wishlist WHERE investor_id=? AND farm_id=?")->execute([$investor_id, $farm_id]);
            echo json_encode(['status'=>'removed']); exit;
        } else {
            $pdo->prepare("INSERT INTO qw_wishlist (investor_id, farm_id) VALUES (?,?)")->execute([$investor_id, $farm_id]);
            echo json_encode(['status'=>'added']); exit;
        }
    }

    // US-14: Submit investment request
    if ($act === 'submit_request') {
        $offer_id        = (int)($_POST['offer_id'] ?? 0);
        $area_sqm        = (float)($_POST['area_sqm'] ?? 0);
        $duration        = $_POST['duration']        ?? '';
        $harvest_method  = $_POST['harvest_method']  ?? '';
        $delivery_addr   = trim($_POST['delivery_address'] ?? '');

        $validDurations = ['1_season','1_year','2_years'];
        $validHarvest   = ['receive','sell','donate'];

        if ($offer_id && $area_sqm > 0 && in_array($duration, $validDurations) && in_array($harvest_method, $validHarvest)) {
            // Check available area (US-10)
            $offerChk = $pdo->prepare("
                SELECT fo.offer_id, fo.area_size, fo.price,
                       COALESCE(SUM(ir2.area_sqm),0) AS used_area
                FROM qw_farm_offer fo
                LEFT JOIN qw_investment_request ir2 ON ir2.offer_id=fo.offer_id AND ir2.req_status IN ('pending','accepted')
                WHERE fo.offer_id=?
                GROUP BY fo.offer_id
            ");
            $offerChk->execute([$offer_id]);
            $offer = $offerChk->fetch();

            if ($offer && ($offer['area_size'] - $offer['used_area']) >= $area_sqm) {
                $pdo->prepare("
                    INSERT INTO qw_investment_request
                        (investor_id, offer_id, area_sqm, duration, harvest_method, delivery_address)
                    VALUES (?,?,?,?,?,?)
                ")->execute([$investor_id, $offer_id, $area_sqm, $duration, $harvest_method, $delivery_addr]);
                logActivity($_SESSION['user_id'], 'submit_request', 'investment_request', (int)$pdo->lastInsertId());
                echo json_encode(['status'=>'ok']); exit;
            } else {
                echo json_encode(['status'=>'error','msg'=>'المساحة المطلوبة تتجاوز ما هو متاح.']); exit;
            }
        }
        echo json_encode(['status'=>'error','msg'=>'بيانات غير صحيحة.']); exit;
    }

    // US-20: Submit review
    if ($act === 'submit_review') {
        $request_id    = (int)($_POST['request_id'] ?? 0);
        $farm_id       = (int)($_POST['farm_id']    ?? 0);
        $rating        = (int)($_POST['rating']     ?? 0);
        $review_text   = trim($_POST['review_text'] ?? '');

        if ($request_id && $farm_id && $rating >= 1 && $rating <= 5) {
            // One review per request (US-20)
            $dup = $pdo->prepare("SELECT review_id FROM qw_review WHERE request_id=?");
            $dup->execute([$request_id]);
            if (!$dup->fetch()) {
                $pdo->prepare("INSERT INTO qw_review (farm_id, investor_id, request_id, rating, review_text) VALUES (?,?,?,?,?)")
                    ->execute([$farm_id, $investor_id, $request_id, $rating, $review_text]);
                echo json_encode(['status'=>'ok']); exit;
            }
        }
        echo json_encode(['status'=>'error','msg'=>'لم يمكن حفظ التقييم.']); exit;
    }

    header('Location: investor.php'); exit;
}

// ── Fetch data ────────────────────────────────────────────
// US-05: All approved farms with offers and ratings
$farmsStmt = $pdo->query("
    SELECT f.farm_id, f.name, f.region, f.palm_type, f.date_type,
           f.total_area_sqm, f.description,
           MIN(fo.price) AS min_price,
           ROUND(AVG(rv.rating),1) AS avg_rating,
           COUNT(DISTINCT rv.review_id) AS review_count,
           COALESCE(SUM(ir2.area_sqm),0) AS leased_area
    FROM qw_farm f
    LEFT JOIN qw_farm_offer fo ON fo.farm_id = f.farm_id
    LEFT JOIN qw_review rv     ON rv.farm_id  = f.farm_id
    LEFT JOIN qw_investment_request ir2 ON ir2.offer_id = fo.offer_id AND ir2.req_status IN ('pending','accepted')
    WHERE f.farm_status = 'approved'
    GROUP BY f.farm_id
    ORDER BY f.created_at DESC
");
$farms = $farmsStmt->fetchAll();

// Farm offers per farm
$offersStmt = $pdo->query("
    SELECT fo.offer_id, fo.farm_id, fo.area_size, fo.price,
           COALESCE(SUM(ir2.area_sqm),0) AS used_area
    FROM qw_farm_offer fo
    LEFT JOIN qw_investment_request ir2 ON ir2.offer_id=fo.offer_id AND ir2.req_status IN ('pending','accepted')
    GROUP BY fo.offer_id
");
$offersRaw = $offersStmt->fetchAll();
$offersByFarm = [];
foreach ($offersRaw as $o) {
    $o['available_area'] = $o['area_size'] - $o['used_area'];
    $offersByFarm[$o['farm_id']][] = $o;
}

// US-15: My investment requests
$reqStmt = $pdo->prepare("
    SELECT ir.request_id, ir.area_sqm, ir.duration, ir.harvest_method,
           ir.req_status, ir.submitted_at, ir.rejection_reason,
           f.name AS farm_name, f.region, f.palm_type, f.farm_id,
           fo.price,
           (ir.area_sqm * fo.price) AS amount,
           tx.payment_status, tx.paid_at,
           rv.rating AS my_rating, rv.review_id
    FROM qw_investment_request ir
    JOIN qw_farm_offer fo ON ir.offer_id   = fo.offer_id
    JOIN qw_farm f        ON fo.farm_id    = f.farm_id
    LEFT JOIN qw_transaction tx ON tx.request_id = ir.request_id
    LEFT JOIN qw_review rv      ON rv.request_id  = ir.request_id
    WHERE ir.investor_id = ?
    ORDER BY ir.submitted_at DESC
");
$reqStmt->execute([$investor_id]);
$myRequests = $reqStmt->fetchAll();

// US-09: Wishlist (persisted in DB)
$wlStmt = $pdo->prepare("
    SELECT w.farm_id, f.name, f.region, f.palm_type, MIN(fo.price) AS min_price
    FROM qw_wishlist w
    JOIN qw_farm f ON w.farm_id = f.farm_id
    LEFT JOIN qw_farm_offer fo ON fo.farm_id = f.farm_id
    WHERE w.investor_id = ?
    GROUP BY w.farm_id
");
$wlStmt->execute([$investor_id]);
$myWishlist  = $wlStmt->fetchAll();
$wishlistIds = array_column($myWishlist, 'farm_id');

// US-17: Farm updates (activity feed — only farms with accepted investment)
$updStmt = $pdo->prepare("
    SELECT fu.content, fu.created_at, f.name AS farm_name
    FROM qw_farm_update fu
    JOIN qw_farm f ON fu.farm_id = f.farm_id
    WHERE f.farm_id IN (
        SELECT DISTINCT fo2.farm_id FROM qw_investment_request ir2
        JOIN qw_farm_offer fo2 ON ir2.offer_id = fo2.offer_id
        WHERE ir2.investor_id = ? AND ir2.req_status = 'accepted'
    )
    ORDER BY fu.created_at DESC LIMIT 10
");
$updStmt->execute([$investor_id]);
$farmUpdates = $updStmt->fetchAll();

// US-16: Portfolio summary
$portfolioStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT ir.request_id)          AS active_count,
        COALESCE(SUM(tx.amount),0)             AS total_invested,
        COALESCE(SUM(ir.area_sqm),0)           AS total_area,
        COUNT(CASE WHEN ir.req_status='pending' THEN 1 END) AS pending_count
    FROM qw_investment_request ir
    LEFT JOIN qw_transaction tx ON tx.request_id = ir.request_id AND tx.payment_status='paid'
    WHERE ir.investor_id = ? AND ir.req_status IN ('accepted','pending')
");
$portfolioStmt->execute([$investor_id]);
$portfolio = $portfolioStmt->fetch();

// Distinct palm types and regions for filters (US-06, US-07, US-08)
$palmTypes = array_unique(array_column($farms, 'palm_type'));
$regions   = array_unique(array_column($farms, 'region'));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>قِنوان | لوحة المستثمر</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
  <style>
    /* Investor-specific extras */
    .offers-page { position:fixed; inset:0; background:#f5f2ed; z-index:500; overflow-y:auto; display:none; }
    .offers-page.active { display:block; }
    .offers-page-header { background:var(--green-dark); padding:20px 24px; position:sticky; top:0; z-index:10; }
    .offers-back-btn { background:rgba(255,255,255,0.2); border:none; color:#fff; padding:8px 16px; border-radius:30px; font-family:'Noto Naskh Arabic',serif; font-size:14px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; margin-bottom:14px; }
    .offers-back-btn:hover { background:rgba(255,255,255,0.3); }
    .offers-page-title { font-family:'Amiri',serif; font-size:26px; font-weight:700; color:#fff; }
    .offers-page-meta  { font-size:13px; color:var(--gold-light); margin-top:4px; }
    .offers-container  { padding:24px; max-width:800px; margin:0 auto; }
    .offer-card { background:#fff; border-radius:18px; padding:20px; margin-bottom:18px; box-shadow:var(--shadow-md); border:1px solid var(--border-light); transition:transform 0.2s; }
    .offer-card:hover { transform:translateY(-2px); }
    .offer-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; padding-bottom:10px; border-bottom:2px solid var(--gold-light); }
    .offer-title  { font-family:'Amiri',serif; font-size:20px; font-weight:700; color:var(--green-dark); }
    .offer-badge  { background:var(--green-dark); color:var(--gold-light); padding:4px 12px; border-radius:30px; font-size:12px; font-weight:600; }
    .offer-details-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-bottom:14px; }
    .offer-detail strong { color:var(--green-dark); display:block; font-size:11px; margin-bottom:3px; }
    .offer-detail { font-size:14px; color:var(--brown-light); }
    .offer-price  { font-size:22px; font-weight:700; color:var(--gold); margin:10px 0; }
    .offer-avail  { font-size:13px; color:var(--text-muted); margin-bottom:12px; }
    .area-input-wrap { display:flex; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap; }
    .area-input-wrap input { width:100px; padding:9px 12px; border:1.5px solid var(--border); border-radius:var(--radius); font-size:14px; text-align:center; font-family:'Noto Naskh Arabic',serif; }
    .cost-preview { font-size:15px; font-weight:700; color:var(--green-dark); }
    .btn-invest-offer { background:var(--green-dark); color:#fff; border:none; border-radius:var(--radius); padding:11px 24px; font-family:'Noto Naskh Arabic',serif; font-size:14px; font-weight:700; cursor:pointer; transition:all 0.2s; }
    .btn-invest-offer:hover { background:var(--green-mid); transform:translateY(-1px); }
    .btn-invest-offer:disabled { background:var(--text-faint); cursor:not-allowed; }
    #leaflet-map { width:100%; height:460px; border-radius:var(--radius); border:1.5px solid var(--border-light); }
    .star-rating-input { display:flex; flex-direction:row-reverse; gap:4px; justify-content:flex-end; }
    .star-rating-input span { font-size:30px; cursor:pointer; color:#d9c8b0; transition:color 0.15s; }
    .star-rating-input span.on { color:var(--gold); }
    /* Filter tags */
    .palm-filter-tags { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
    .palm-tag { padding:5px 14px; border-radius:20px; border:1.5px solid var(--border); font-size:13px; cursor:pointer; background:var(--bg-white); transition:all 0.2s; font-family:'Noto Naskh Arabic',serif; }
    .palm-tag.active { background:var(--green-mid); color:#fff; border-color:var(--green-mid); }
    /* Size filter */
    .size-filter-select { padding:8px 14px; border:1.5px solid var(--border); border-radius:var(--radius); font-family:'Noto Naskh Arabic',serif; font-size:13px; background:var(--bg-white); cursor:pointer; }
  </style>
</head>
<body>

<!-- ============================================================
     صفحة 1 — لوحة تحكم المستثمر (US-16)
     ============================================================ -->
<div class="page active" id="page-dashboard">
  <nav>
    <button class="nav-back" onclick="window.location.href='index.php'">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link active" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link" onclick="showPage('browse')">استكشاف المزارع</button>
      <button class="nav-link" onclick="showPage('wishlist')">قائمة الرغبات</button>
      <button class="nav-link" onclick="showPage('requests')">طلباتي</button>
      <a href="logout.php" class="nav-link nav-logout">تسجيل الخروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المستثمر</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">لوحة تحكم المستثمر — مرحباً <?= htmlspecialchars($first_name) ?></h1>
      <div class="title-ornament"><div class="orn-line" style="width:60px"></div><div class="orn-diamond"></div><div class="orn-dot"></div><div class="orn-diamond"></div><div class="orn-line" style="width:24px"></div></div>
    </div>

    <!-- Portfolio Stats (US-16) -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">إجمالي الاستثمار</div>
        <div class="stat-value"><?= number_format($portfolio['total_invested'], 0) ?> ر.س</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">الاستثمارات النشطة</div>
        <div class="stat-value"><?= $portfolio['active_count'] ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">الطلبات المعلقة</div>
        <div class="stat-value"><?= $portfolio['pending_count'] ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">المساحة المؤجرة</div>
        <div class="stat-value"><?= number_format($portfolio['total_area'], 0) ?> م²</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">قائمة الرغبات</div>
        <div class="stat-value"><?= count($myWishlist) ?></div>
      </div>
    </div>

    <div class="two-columns">
      <!-- Active investments -->
      <div class="section-card">
        <div class="section-title" style="padding:18px 22px 0;margin:0;">استثماراتي الحالية</div>
        <div class="investment-list">
          <?php
          $accepted = array_filter($myRequests, fn($r) => $r['req_status'] === 'accepted');
          if (empty($accepted)): ?>
            <div style="padding:24px;text-align:center;color:var(--text-faint);">لا توجد استثمارات نشطة بعد.</div>
          <?php else: foreach (array_slice($accepted, 0, 5) as $r): ?>
            <div class="investment-item">
              <div class="investment-info">
                <div class="investment-name"><?= htmlspecialchars($r['farm_name']) ?></div>
                <div class="investment-meta"><?= number_format($r['amount'], 0) ?> ر.س · <?= date('Y-m-d', strtotime($r['submitted_at'])) ?></div>
              </div>
              <span class="status-active">نشط</span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- Activity feed (US-17) -->
      <div class="section-card">
        <div class="section-title" style="padding:18px 22px 0;margin:0;">آخر التحديثات</div>
        <div class="updates-list">
          <?php if (empty($farmUpdates)): ?>
            <div style="padding:24px;text-align:center;color:var(--text-faint);">لا توجد تحديثات حتى الآن.</div>
          <?php else: foreach ($farmUpdates as $u): ?>
            <div class="update-item">
              <div class="update-farm">🌴 <?= htmlspecialchars($u['farm_name']) ?></div>
              <p class="update-text"><?= htmlspecialchars(mb_substr($u['content'], 0, 120)) ?>...</p>
              <div class="update-date"><?= date('Y-m-d H:i', strtotime($u['created_at'])) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     صفحة 2 — استكشاف المزارع (US-04 to US-08)
     ============================================================ -->
<div class="page" id="page-browse">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">← العودة</button>
    <div class="nav-links">
      <button class="nav-link" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link active" onclick="showPage('browse')">استكشاف المزارع</button>
      <button class="nav-link" onclick="showPage('wishlist')">قائمة الرغبات</button>
      <button class="nav-link" onclick="showPage('requests')">طلباتي</button>
      <a href="logout.php" class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المستثمر</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap"><h1 class="page-title">استكشاف المزارع</h1></div>

    <!-- Filters (US-05 to US-08) -->
    <div class="search-bar">
      <div class="search-input-wrapper">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#8A6F5A" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" class="search-input" id="farmSearch" placeholder="ابحث عن مزرعة..." oninput="applyFilters()">
      </div>
      <!-- US-06: Region filter -->
      <select class="filter-select" id="regionFilter" onchange="applyFilters()">
        <option value="all">جميع المناطق</option>
        <?php foreach ($regions as $reg): ?>
          <option value="<?= htmlspecialchars($reg) ?>"><?= htmlspecialchars($reg) ?></option>
        <?php endforeach; ?>
      </select>
      <!-- US-07: Size filter -->
      <select class="size-filter-select" id="sizeFilter" onchange="applyFilters()">
        <option value="all">جميع الأحجام</option>
        <option value="0-500">0–500 م²</option>
        <option value="500-2000">500–2000 م²</option>
        <option value="2000+">2000+ م²</option>
      </select>
      <button class="filter-btn" onclick="clearFilters()" style="background:var(--red-light);color:var(--red);border-color:rgba(139,42,42,0.3);">مسح الفلاتر</button>
      <div class="view-toggle">
        <button class="view-btn active" id="btn-list" onclick="switchView('list')">قائمة</button>
        <button class="view-btn" id="btn-map" onclick="switchView('map')">🗺 خريطة</button>
      </div>
    </div>

    <!-- US-08: Palm type multi-filter tags -->
    <div class="palm-filter-tags" id="palmTags">
      <span class="palm-tag active" data-palm="all" onclick="selectPalm('all',this)">كل الأنواع</span>
      <?php foreach ($palmTypes as $pt): ?>
        <span class="palm-tag" data-palm="<?= htmlspecialchars($pt) ?>" onclick="selectPalm('<?= htmlspecialchars($pt) ?>',this)"><?= htmlspecialchars($pt) ?></span>
      <?php endforeach; ?>
    </div>

    <div id="no-results" style="display:none;text-align:center;padding:48px;color:var(--text-faint);">لا توجد مزارع تطابق الفلاتر المحددة.</div>

    <!-- Map view (US-04) -->
    <div id="view-map" style="display:none;margin-bottom:22px;">
      <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px;padding-right:4px;">
        🗺 <span id="map-count"><?= count($farms) ?> مزرعة على الخريطة</span>
        <span style="font-size:12px;color:var(--text-faint);margin-right:8px;">— الفلاتر تطبق تلقائياً</span>
      </div>
      <div id="leaflet-map"></div>
    </div>

    <!-- List view (US-05) -->
    <div id="view-list">
      <div id="farms-list">
        <?php foreach ($farms as $farm):
          $inWL = in_array($farm['farm_id'], $wishlistIds);
          $stars = str_repeat('★', round($farm['avg_rating'] ?? 0)) . str_repeat('☆', 5 - round($farm['avg_rating'] ?? 0));
          $avail = $farm['total_area_sqm'] - $farm['leased_area'];
        ?>
          <div class="farm-card"
               data-name="<?= strtolower($farm['name'].' '.$farm['region'].' '.$farm['palm_type']) ?>"
               data-region="<?= htmlspecialchars($farm['region']) ?>"
               data-palm="<?= htmlspecialchars($farm['palm_type']) ?>"
               data-area="<?= $farm['total_area_sqm'] ?>">
            <div class="farm-card-inner">
              <div class="palm-icon">🌴</div>
              <div class="farm-details" style="flex:1;">
                <div class="farm-name"><?= htmlspecialchars($farm['name']) ?></div>
                <div class="stars" title="<?= $farm['avg_rating'] ?? 0 ?>/5 (<?= $farm['review_count'] ?> تقييم)"><?= $stars ?></div>
                <div class="farm-meta"><?= htmlspecialchars($farm['region']) ?> · <?= htmlspecialchars($farm['palm_type']) ?></div>
                <div class="farm-price">من <?= number_format($farm['min_price'] ?? 0, 0) ?> ر.س/م² | متاح: <?= number_format(max(0,$avail), 0) ?> م²</div>
              </div>
              <div class="farm-actions">
                <!-- US-09: Wishlist button -->
                <button class="icon-btn wishlist-btn <?= $inWL ? 'active' : '' ?>"
                        onclick="toggleWishlist(this, <?= $farm['farm_id'] ?>)"
                        title="<?= $inWL ? 'إزالة من المفضلة' : 'إضافة للمفضلة' ?>"
                        style="<?= $inWL ? 'color:var(--gold);border-color:var(--gold);' : '' ?>">
                  <?= $inWL ? '❤️' : '🤍' ?>
                </button>
                <button class="btn-explore" onclick="openFarmOffers(<?= $farm['farm_id'] ?>, '<?= htmlspecialchars(addslashes($farm['name'])) ?>', '<?= htmlspecialchars($farm['region']) ?>', '<?= htmlspecialchars($farm['palm_type']) ?>')">
                  استكشف العروض
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($farms)): ?>
          <div style="text-align:center;padding:48px;color:var(--text-faint);">لا توجد مزارع معتمدة حالياً.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     صفحة عروض المزرعة (US-10 to US-13)
     ============================================================ -->
<div id="offersPage" class="offers-page">
  <div class="offers-page-header">
    <button class="offers-back-btn" onclick="closeOffersPage()">← رجوع</button>
    <div class="offers-page-title" id="offersPageTitle"></div>
    <div class="offers-page-meta"  id="offersPageMeta"></div>
  </div>
  <div class="offers-container" id="offersContainer"></div>
</div>

<!-- ============================================================
     صفحة 3 — قائمة الرغبات (US-09)
     ============================================================ -->
<div class="page" id="page-wishlist">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">← العودة</button>
    <div class="nav-links">
      <button class="nav-link" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link" onclick="showPage('browse')">استكشاف المزارع</button>
      <button class="nav-link active" onclick="showPage('wishlist')">قائمة الرغبات</button>
      <button class="nav-link" onclick="showPage('requests')">طلباتي</button>
      <a href="logout.php" class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المستثمر</span></div>
    </div>
  </nav>
  <div class="page-content">
    <div class="page-title-wrap"><h1 class="page-title">قائمة الرغبات</h1></div>
    <?php if (empty($myWishlist)): ?>
      <div style="text-align:center;padding:60px;color:var(--text-faint);">قائمة الرغبات فارغة. استكشف المزارع وأضف ما يعجبك!</div>
    <?php else: ?>
      <?php foreach ($myWishlist as $wf): ?>
        <div class="farm-card" style="margin-bottom:12px;">
          <div class="farm-card-inner">
            <div class="palm-icon">🌴</div>
            <div style="flex:1;">
              <div class="farm-name"><?= htmlspecialchars($wf['name']) ?></div>
              <div class="farm-meta"><?= htmlspecialchars($wf['region']) ?> · <?= htmlspecialchars($wf['palm_type']) ?></div>
              <div class="farm-price">من <?= number_format($wf['min_price'] ?? 0, 0) ?> ر.س/م²</div>
            </div>
            <div class="farm-actions">
              <button class="icon-btn" onclick="removeFromWishlist(<?= $wf['farm_id'] ?>, this)" title="إزالة">❤️</button>
              <button class="btn-explore" onclick="openFarmOffers(<?= $wf['farm_id'] ?>, '<?= htmlspecialchars(addslashes($wf['name'])) ?>', '<?= htmlspecialchars($wf['region']) ?>', '<?= htmlspecialchars($wf['palm_type']) ?>')">استكشف</button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================
     صفحة 4 — طلباتي (US-15, US-20)
     ============================================================ -->
<div class="page" id="page-requests">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">← العودة</button>
    <div class="nav-links">
      <button class="nav-link" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link" onclick="showPage('browse')">استكشاف المزارع</button>
      <button class="nav-link" onclick="showPage('wishlist')">قائمة الرغبات</button>
      <button class="nav-link active" onclick="showPage('requests')">طلباتي</button>
      <a href="logout.php" class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المستثمر</span></div>
    </div>
  </nav>
  <div class="page-content">
    <div class="page-title-wrap"><h1 class="page-title">طلبات الاستثمار</h1></div>
    <div class="filter-bar">
      <button class="filter-btn active" onclick="filterRequests('all',this)">الكل</button>
      <button class="filter-btn" onclick="filterRequests('pending',this)">قيد الانتظار</button>
      <button class="filter-btn" onclick="filterRequests('accepted',this)">مقبولة</button>
      <button class="filter-btn" onclick="filterRequests('rejected',this)">مرفوضة</button>
    </div>
    <div id="requests-list">
      <?php
      $harvestMap  = ['receive'=>'استلام التمور في المنزل','sell'=>'بيع المحصول','donate'=>'تبرع للجمعيات'];
      $statusLabels = ['pending'=>'قيد الانتظار','accepted'=>'مقبولة','rejected'=>'مرفوضة','cancelled'=>'ملغى','expired'=>'منتهية'];
      foreach ($myRequests as $req): ?>
        <div class="request-card <?= $req['req_status'] ?>" data-status="<?= $req['req_status'] ?>">
          <div class="request-header">
            <span class="request-name">🌴 <?= htmlspecialchars($req['farm_name']) ?></span>
            <span class="status-badge <?= $req['req_status'] ?>-badge"><?= $statusLabels[$req['req_status']] ?? $req['req_status'] ?></span>
          </div>
          <div class="request-details">
            <span class="request-detail"><?= number_format($req['area_sqm'],0) ?> م²</span>
            <span class="request-detail"><?= htmlspecialchars($req['duration']) ?></span>
            <span class="request-detail"><?= number_format($req['amount'],0) ?> ر.س</span>
            <span class="request-detail"><?= $harvestMap[$req['harvest_method']] ?? $req['harvest_method'] ?></span>
          </div>
          <div style="font-size:12px;color:var(--text-faint);">📅 <?= date('Y-m-d', strtotime($req['submitted_at'])) ?></div>
          <?php if ($req['req_status'] === 'rejected' && $req['rejection_reason']): ?>
            <div style="background:var(--red-light);padding:8px 12px;border-radius:6px;font-size:13px;color:var(--red);margin-top:8px;">
              سبب الرفض: <?= htmlspecialchars($req['rejection_reason']) ?>
            </div>
          <?php endif; ?>
          <!-- US-20: Rating (only for accepted, one per request) -->
          <?php if ($req['req_status'] === 'accepted' && !$req['review_id']): ?>
            <button class="btn-accept" style="margin-top:10px;padding:7px 16px;font-size:13px;" onclick="openRating(<?= $req['request_id'] ?>, <?= $req['farm_id'] ?>)">⭐ قيّم تجربتك</button>
          <?php elseif ($req['review_id']): ?>
            <div style="margin-top:8px;font-size:13px;color:var(--green-dark);">⭐ تم التقييم: <?= str_repeat('★', $req['my_rating']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($myRequests)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-faint);">لا توجد طلبات حتى الآن.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============================================================
     Harvest Method Popup (US-13)
     ============================================================ -->
<div id="harvestPopup" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:600;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:460px;margin:20px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <div style="background:var(--green-dark);padding:20px 24px;">
      <button onclick="closeHarvest()" style="float:left;background:rgba(255,255,255,0.2);border:none;color:#fff;width:30px;height:30px;border-radius:50%;font-size:18px;cursor:pointer;">✕</button>
      <div style="font-family:'Amiri',serif;font-size:20px;font-weight:700;color:#fff;">كيف تريد استلام عائد استثمارك؟</div>
      <div id="harvestFarmLabel" style="font-size:13px;color:var(--gold-light);margin-top:4px;"></div>
    </div>
    <div style="padding:22px;">
      <div class="harvest-option" onclick="selectHarvest('sell')"  id="ho-sell">  <b>💰 بيع المحصول واستلام الأرباح</b><br><small style="color:var(--text-muted);">يتم بيع التمور وتحويل الأرباح إليك</small></div>
      <div class="harvest-option" onclick="selectHarvest('receive')" id="ho-receive"><b>📦 استلام التمور في المنزل</b><br><small style="color:var(--text-muted);">يتم توصيل حصتك مباشرة لعنوانك</small></div>
      <div class="harvest-option" onclick="selectHarvest('donate')" id="ho-donate"> <b>🤲 التبرع للجمعيات الخيرية</b><br><small style="color:var(--text-muted);">يتم التبرع بالمحصول لجمعيات معتمدة</small></div>
      <div id="addressFields" style="display:none;margin-top:14px;">
        <input type="text" id="addrCity"     placeholder="المدينة *"         class="form-input" style="margin-bottom:10px;padding:10px 14px;" />
        <input type="text" id="addrDistrict" placeholder="الحي *"           class="form-input" style="margin-bottom:10px;padding:10px 14px;" />
        <input type="text" id="addrStreet"   placeholder="الشارع *"         class="form-input" style="margin-bottom:10px;padding:10px 14px;" />
        <input type="text" id="addrBuilding" placeholder="رقم المبنى *"     class="form-input" style="margin-bottom:10px;padding:10px 14px;" />
        <input type="text" id="addrPostal"   placeholder="الرمز البريدي"    class="form-input" style="padding:10px 14px;" />
      </div>
      <select id="durationSelect" class="form-select" style="margin-top:14px;" required>
        <option value="" disabled selected>اختر مدة الاستثمار *</option>
        <option value="1_season">موسم واحد</option>
        <option value="1_year">سنة واحدة</option>
        <option value="2_years">سنتان</option>
      </select>
      <button onclick="submitInvestment()" style="width:100%;background:var(--green-dark);color:#fff;border:none;border-radius:var(--radius);padding:13px;font-family:'Noto Naskh Arabic',serif;font-size:15px;font-weight:700;cursor:pointer;margin-top:16px;">
        📤 تأكيد وإرسال الطلب
      </button>
    </div>
  </div>
</div>

<!-- Rating Popup (US-20) -->
<div id="ratingPopup" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:700;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:440px;margin:20px;padding:32px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 style="font-family:'Amiri',serif;font-size:22px;color:var(--brown-dark);margin-bottom:16px;">⭐ قيّم تجربتك الاستثمارية</h3>
    <input type="hidden" id="ratingRequestId">
    <input type="hidden" id="ratingFarmId">
    <div class="star-rating-input" id="starInput">
      <span data-v="5" onclick="setStars(5)">★</span>
      <span data-v="4" onclick="setStars(4)">★</span>
      <span data-v="3" onclick="setStars(3)">★</span>
      <span data-v="2" onclick="setStars(2)">★</span>
      <span data-v="1" onclick="setStars(1)">★</span>
    </div>
    <input type="hidden" id="selectedRating" value="0">
    <textarea id="reviewText" class="form-textarea" style="margin-top:14px;" placeholder="تعليق اختياري..."></textarea>
    <div style="display:flex;gap:10px;margin-top:16px;">
      <button onclick="document.getElementById('ratingPopup').style.display='none'" style="flex:1;background:transparent;border:1.5px solid var(--border);border-radius:var(--radius);padding:12px;cursor:pointer;font-family:'Noto Naskh Arabic',serif;">إلغاء</button>
      <button onclick="submitRating()" style="flex:2;background:var(--green-dark);color:#fff;border:none;border-radius:var(--radius);padding:12px;cursor:pointer;font-family:'Noto Naskh Arabic',serif;font-weight:700;">إرسال التقييم</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- PHP data for JS -->
<script>
const PHP_FARMS    = <?= json_encode($farms,         JSON_UNESCAPED_UNICODE) ?>;
const PHP_OFFERS   = <?= json_encode($offersByFarm,  JSON_UNESCAPED_UNICODE) ?>;
const PHP_WL_IDS   = <?= json_encode($wishlistIds) ?>;
const PHP_REQUESTS = <?= json_encode($myRequests,    JSON_UNESCAPED_UNICODE) ?>;

// ── Page navigation ──────────────────────────────────────
function showPage(id) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-' + id)?.classList.add('active');
  document.querySelectorAll('.nav-link[onclick]').forEach(l => {
    l.classList.toggle('active', l.getAttribute('onclick')?.includes("'" + id + "'"));
  });
  if (id === 'requests') filterRequests('all', document.querySelector('#page-requests .filter-btn'));
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

// ── Farm filtering (US-05 to US-08) ──────────────────────
let activePalm = 'all';

function selectPalm(palm, el) {
  activePalm = palm;
  document.querySelectorAll('.palm-tag').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  applyFilters();
}

function applyFilters() {
  const q      = document.getElementById('farmSearch').value.toLowerCase();
  const region = document.getElementById('regionFilter').value;
  const size   = document.getElementById('sizeFilter').value;
  let visible  = 0;

  document.querySelectorAll('#farms-list .farm-card').forEach(card => {
    const matchName   = card.dataset.name.includes(q);
    const matchRegion = (region === 'all' || card.dataset.region === region);
    const matchPalm   = (activePalm === 'all' || card.dataset.palm === activePalm);
    const area        = parseFloat(card.dataset.area) || 0;
    let matchSize     = true;
    if (size === '0-500')    matchSize = area <= 500;
    if (size === '500-2000') matchSize = area > 500 && area <= 2000;
    if (size === '2000+')    matchSize = area > 2000;

    const show = matchName && matchRegion && matchPalm && matchSize;
    card.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  document.getElementById('no-results').style.display = (visible === 0) ? 'block' : 'none';

  // تحديث الخريطة بنفس الفلاتر
  updateMapMarkers();
}

function clearFilters() {
  document.getElementById('farmSearch').value   = '';
  document.getElementById('regionFilter').value = 'all';
  document.getElementById('sizeFilter').value   = 'all';
  activePalm = 'all';
  document.querySelectorAll('.palm-tag').forEach(t => t.classList.toggle('active', t.dataset.palm === 'all'));
  applyFilters();
}

// ── Wishlist (US-09) ──────────────────────────────────────
function toggleWishlist(btn, farmId) {
  const fd = new FormData();
  fd.append('act', 'toggle_wishlist');
  fd.append('farm_id', farmId);
  fetch('investor.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      if (d.status === 'added')   { btn.textContent = '❤️'; btn.style.color='var(--gold)'; btn.style.borderColor='var(--gold)'; showToast('تمت الإضافة للمفضلة'); }
      if (d.status === 'removed') { btn.textContent = '🤍'; btn.style.color=''; btn.style.borderColor=''; showToast('تمت الإزالة من المفضلة'); }
    });
}

function removeFromWishlist(farmId, btn) {
  const fd = new FormData();
  fd.append('act', 'toggle_wishlist');
  fd.append('farm_id', farmId);
  fetch('investor.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(() => {
      btn.closest('.farm-card')?.remove();
      showToast('تمت الإزالة من المفضلة');
    });
}

// ── Farm offers popup (US-10 to US-14) ───────────────────
let currentOfferId = null;
let currentFarmId  = null;
let currentAreaSqm = 0;
let currentPrice   = 0;
let selectedHarvest = null;

function openFarmOffers(farmId, name, region, palm) {
  document.getElementById('offersPageTitle').textContent = name;
  document.getElementById('offersPageMeta').textContent  = region + ' · ' + palm;

  const container = document.getElementById('offersContainer');
  container.innerHTML = '';

  const farmOffers = PHP_OFFERS[farmId] || [];
  if (farmOffers.length === 0) {
    container.innerHTML = '<div style="text-align:center;padding:48px;color:var(--text-faint);">لا توجد عروض متاحة لهذه المزرعة حالياً.</div>';
  } else {
    farmOffers.forEach((offer, i) => {
      const avail = Math.max(0, offer.available_area);
      const div   = document.createElement('div');
      div.className = 'offer-card';
      div.innerHTML = `
        <div class="offer-header">
          <span class="offer-title">عرض ${i+1}</span>
          <span class="offer-badge">متاح: ${avail.toLocaleString()} م²</span>
        </div>
        <div class="offer-details-grid">
          <div class="offer-detail"><strong>إجمالي المساحة</strong>${parseFloat(offer.area_size).toLocaleString()} م²</div>
          <div class="offer-detail"><strong>السعر/م²</strong>${parseFloat(offer.price).toFixed(0)} ر.س</div>
          <div class="offer-detail"><strong>المساحة المتاحة</strong>${avail.toLocaleString()} م²</div>
        </div>
        <div class="offer-avail">المساحة الدنيا: 50 م²</div>
        <div class="area-input-wrap">
          <label style="font-size:13px;font-weight:600;color:var(--brown-dark);">اختر المساحة (م²):</label>
          <input type="number" id="areaInput_${offer.offer_id}" min="50" max="${avail}"
                 value="100" style="width:110px;" oninput="updateCost(${offer.offer_id}, ${offer.price})">
          <span class="cost-preview" id="costPreview_${offer.offer_id}">= ${(100 * offer.price).toLocaleString()} ر.س</span>
        </div>
        <button class="btn-invest-offer" ${avail < 50 ? 'disabled' : ''}
                onclick="prepareInvestment(${offer.offer_id}, ${farmId}, ${offer.price}, '${name}')">
          📤 استثمر في هذا العرض
        </button>
      `;
      container.appendChild(div);
    });
  }
  document.getElementById('offersPage').classList.add('active');
}

function updateCost(offerId, price) {
  const area = parseFloat(document.getElementById('areaInput_' + offerId).value) || 0;
  document.getElementById('costPreview_' + offerId).textContent = '= ' + (area * price).toLocaleString() + ' ر.س';
}

function closeOffersPage() {
  document.getElementById('offersPage').classList.remove('active');
}

function prepareInvestment(offerId, farmId, price, farmName) {
  const areaInput = document.getElementById('areaInput_' + offerId);
  const area = parseFloat(areaInput?.value) || 0;
  if (area < 50) { showToast('الحد الأدنى للاستثمار 50 م²'); return; }
  currentOfferId  = offerId;
  currentFarmId   = farmId;
  currentAreaSqm  = area;
  currentPrice    = price;
  selectedHarvest = null;
  document.getElementById('harvestFarmLabel').textContent = farmName + ' · ' + area + ' م² · ' + (area * price).toLocaleString() + ' ر.س';
  document.querySelectorAll('.harvest-option').forEach(o => o.classList.remove('selected'));
  document.getElementById('addressFields').style.display = 'none';
  document.getElementById('durationSelect').value = '';
  document.getElementById('harvestPopup').style.display = 'flex';
}

function selectHarvest(method) {
  selectedHarvest = method;
  document.querySelectorAll('.harvest-option').forEach(o => o.classList.remove('selected'));
  document.getElementById('ho-' + method).classList.add('selected');
  document.getElementById('addressFields').style.display = (method === 'receive') ? 'block' : 'none';
}

function closeHarvest() { document.getElementById('harvestPopup').style.display = 'none'; }

function submitInvestment() {
  if (!selectedHarvest) { showToast('اختر طريقة الحصاد أولاً'); return; }
  const duration = document.getElementById('durationSelect').value;
  if (!duration) { showToast('اختر مدة الاستثمار أولاً'); return; }

  let deliveryAddr = '';
  if (selectedHarvest === 'receive') {
    const city     = document.getElementById('addrCity').value.trim();
    const district = document.getElementById('addrDistrict').value.trim();
    const street   = document.getElementById('addrStreet').value.trim();
    const building = document.getElementById('addrBuilding').value.trim();
    if (!city || !district || !street || !building) { showToast('أكمل بيانات العنوان'); return; }
    deliveryAddr = `${city}، حي ${district}، ${street}، مبنى ${building}`;
    const postal = document.getElementById('addrPostal').value.trim();
    if (postal) deliveryAddr += '، الرمز: ' + postal;
  }

  const fd = new FormData();
  fd.append('act',              'submit_request');
  fd.append('offer_id',         currentOfferId);
  fd.append('area_sqm',         currentAreaSqm);
  fd.append('duration',         duration);
  fd.append('harvest_method',   selectedHarvest);
  fd.append('delivery_address', deliveryAddr);

  fetch('investor.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      if (d.status === 'ok') {
        closeHarvest();
        closeOffersPage();
        showToast('✅ تم إرسال طلبك بنجاح! سيراجعه المزارع خلال 7 أيام.');
        setTimeout(() => location.reload(), 2000);
      } else {
        showToast('❌ ' + (d.msg || 'حدث خطأ، حاول مجدداً'));
      }
    });
}

// ── Request filtering (US-15) ─────────────────────────────
function filterRequests(status, btn) {
  if (!btn) return;
  document.querySelectorAll('#page-requests .filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('#requests-list .request-card').forEach(card => {
    card.style.display = (status === 'all' || card.dataset.status === status) ? '' : 'none';
  });
}

// ── Rating (US-20) ────────────────────────────────────────
function openRating(requestId, farmId) {
  document.getElementById('ratingRequestId').value = requestId;
  document.getElementById('ratingFarmId').value    = farmId;
  document.getElementById('selectedRating').value  = 0;
  document.getElementById('reviewText').value       = '';
  document.querySelectorAll('#starInput span').forEach(s => s.classList.remove('on'));
  document.getElementById('ratingPopup').style.display = 'flex';
}

function setStars(n) {
  document.getElementById('selectedRating').value = n;
  document.querySelectorAll('#starInput span').forEach(s => {
    s.classList.toggle('on', parseInt(s.dataset.v) <= n);
  });
}

function submitRating() {
  const rating = parseInt(document.getElementById('selectedRating').value);
  if (!rating) { showToast('اختر تقييماً من 1 إلى 5 نجوم'); return; }
  const fd = new FormData();
  fd.append('act',         'submit_review');
  fd.append('request_id',  document.getElementById('ratingRequestId').value);
  fd.append('farm_id',     document.getElementById('ratingFarmId').value);
  fd.append('rating',      rating);
  fd.append('review_text', document.getElementById('reviewText').value);
  fetch('investor.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      document.getElementById('ratingPopup').style.display = 'none';
      if (d.status === 'ok') { showToast('✅ شكراً على تقييمك!'); setTimeout(() => location.reload(), 1500); }
      else showToast('❌ ' + (d.msg || 'خطأ في حفظ التقييم'));
    });
}

// ── Map (US-04) ───────────────────────────────────────────
let leafletMap = null, mapInit = false;
let mapMarkers = []; // تتبع كل البنز الحالية

function switchView(view) {
  const lv = document.getElementById('view-list');
  const mv = document.getElementById('view-map');
  const bl = document.getElementById('btn-list');
  const bm = document.getElementById('btn-map');
  if (view === 'map') {
    lv.style.display = 'none'; mv.style.display = 'block';
    bl.classList.remove('active'); bm.classList.add('active');
    if (!mapInit) setTimeout(initLeaflet, 80);
    else { leafletMap.invalidateSize(); updateMapMarkers(); }
  } else {
    lv.style.display = 'block'; mv.style.display = 'none';
    bl.classList.add('active'); bm.classList.remove('active');
  }
}

const FARM_COORDS = {
  'الرياض':          [24.68, 46.72],
  'القصيم':          [26.33, 43.98],
  'المدينة المنورة': [24.47, 39.61],
  'الأحساء':         [25.38, 49.58],
  'تبوك':            [28.38, 36.56],
  'حائل':            [27.51, 41.68],
  'الجوف':           [29.79, 39.88],
  'نجران':           [17.56, 44.22],
};

// إزالة كل البنز من الخريطة
function clearMapMarkers() {
  mapMarkers.forEach(m => leafletMap.removeLayer(m));
  mapMarkers = [];
}

// رسم بنز الخريطة بناءً على الفلاتر الحالية
function updateMapMarkers() {
  if (!mapInit) return;
  clearMapMarkers();

  const q      = document.getElementById('farmSearch').value.toLowerCase();
  const region = document.getElementById('regionFilter').value;
  const size   = document.getElementById('sizeFilter').value;

  const filteredFarms = PHP_FARMS.filter(farm => {
    const matchName   = (farm.name + ' ' + farm.region + ' ' + farm.palm_type).toLowerCase().includes(q);
    const matchRegion = (region === 'all' || farm.region === region);
    const matchPalm   = (activePalm === 'all' || farm.palm_type === activePalm);
    const area        = parseFloat(farm.total_area_sqm) || 0;
    let matchSize     = true;
    if (size === '0-500')    matchSize = area <= 500;
    if (size === '500-2000') matchSize = area > 500 && area <= 2000;
    if (size === '2000+')    matchSize = area > 2000;
    return matchName && matchRegion && matchPalm && matchSize;
  });

  filteredFarms.forEach(farm => {
    const coords = FARM_COORDS[farm.region] || [24.5, 44.5];
    const jitter = [coords[0] + (Math.random()-0.5)*0.3, coords[1] + (Math.random()-0.5)*0.3];
    const icon = L.divIcon({
      className: '',
      html: `<div style="background:var(--green-dark);color:var(--gold-light);border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:16px;border:2px solid var(--gold);box-shadow:0 2px 8px rgba(0,0,0,0.3);">🌴</div>`,
      iconSize: [32,32], iconAnchor: [16,32], popupAnchor: [0,-36]
    });
    const marker = L.marker(jitter, { icon }).bindPopup(`
      <div dir="rtl" style="text-align:right;font-family:'Noto Naskh Arabic',serif;min-width:200px;">
        <strong style="color:var(--green-dark);font-size:15px;">${farm.name}</strong><br>
        <span style="font-size:12px;color:#666;">${farm.region} · ${farm.palm_type}</span><br>
        <span style="font-size:12px;">المساحة: ${parseFloat(farm.total_area_sqm).toLocaleString()} م²</span><br>
        <button onclick="openFarmOffers(${farm.farm_id},'${farm.name}','${farm.region}','${farm.palm_type}')"
          style="margin-top:8px;background:var(--green-dark);color:#fff;border:none;border-radius:6px;padding:6px 14px;cursor:pointer;font-family:'Noto Naskh Arabic',serif;">
          استكشف العروض
        </button>
      </div>
    `);
    marker.addTo(leafletMap);
    mapMarkers.push(marker);
  });

  // إذا فيه منطقة محددة، زوّم عليها
  if (region !== 'all' && FARM_COORDS[region]) {
    leafletMap.flyTo(FARM_COORDS[region], 9, { animate: true, duration: 0.8 });
  } else if (filteredFarms.length > 0) {
    leafletMap.setView([24.5, 44.5], 6);
  }

  // تحديث عداد النتائج على الخريطة
  const counter = document.getElementById('map-count');
  if (counter) counter.textContent = `${filteredFarms.length} مزرعة على الخريطة`;
}

function initLeaflet() {
  if (mapInit) return;
  leafletMap = L.map('leaflet-map', { center: [24.5, 44.5], zoom: 6 });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 18
  }).addTo(leafletMap);
  mapInit = true;
  updateMapMarkers();
}
</script>
</body>
</html>
