<?php
// ============================================================
// قِنوان — investor.php
// لوحة المستثمر — نفس investor.html بالكامل + بيانات PHP
// Tables: qw_user, qw_investor, qw_farm, qw_farm_offer,
//         qw_investment_request, qw_wishlist, qw_cart,
//         qw_cart_item, qw_review, qw_farm_update
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// حماية الصفحة — المستثمرون فقط
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'investor') {
    header('Location: login.php');
    exit;
}

$pdo         = getDB();
$investor_id = (int)$_SESSION['investor_id'];
$first_name  = $_SESSION['first_name'];
$last_name   = $_SESSION['last_name'];

// ── جلب المزارع المعتمدة من qw_farm + qw_farm_offer ──────
$farmsStmt = $pdo->query("
    SELECT f.farm_id, f.name, f.region, f.palm_type, f.date_type,
           f.total_area_sqm, f.description,
           MIN(fo.price) AS min_price,
           ROUND(AVG(rv.rating),1) AS avg_rating,
           COUNT(DISTINCT rv.review_id) AS review_count
    FROM qw_farm f
    LEFT JOIN qw_farm_offer fo ON fo.farm_id = f.farm_id
    LEFT JOIN qw_review rv     ON rv.farm_id  = f.farm_id
    WHERE f.farm_status = 'approved'
    GROUP BY f.farm_id
    ORDER BY f.created_at DESC
");
$farms = $farmsStmt->fetchAll();

// ── جلب طلبات الاستثمار من qw_investment_request ──────────
$reqStmt = $pdo->prepare("
    SELECT ir.request_id, ir.area_sqm, ir.duration, ir.harvest_method,
           ir.req_status, ir.submitted_at, ir.rejection_reason,
           f.name AS farm_name, f.region, f.palm_type,
           fo.price,
           (ir.area_sqm * fo.price) AS amount,
           tx.payment_status, tx.paid_at,
           rv.rating AS my_rating
    FROM qw_investment_request ir
    JOIN qw_farm_offer fo ON ir.offer_id   = fo.offer_id
    JOIN qw_farm f        ON fo.farm_id    = f.farm_id
    LEFT JOIN qw_transaction tx ON tx.request_id = ir.request_id
    LEFT JOIN qw_review rv      ON rv.request_id = ir.request_id
    WHERE ir.investor_id = ?
    ORDER BY ir.submitted_at DESC
");
$reqStmt->execute([$investor_id]);
$myRequests = $reqStmt->fetchAll();

// ── جلب قائمة الرغبات من qw_wishlist ─────────────────────
$wlStmt = $pdo->prepare("
    SELECT w.farm_id, f.name, f.region, f.palm_type
    FROM qw_wishlist w
    JOIN qw_farm f ON w.farm_id = f.farm_id
    WHERE w.investor_id = ?
");
$wlStmt->execute([$investor_id]);
$myWishlist = $wlStmt->fetchAll();
$wishlistIds = array_column($myWishlist, 'farm_id');

// ── جلب سلة التسوق من qw_cart + qw_cart_item ─────────────
$cartStmt = $pdo->prepare("
    SELECT ci.cart_item_id, ci.area_sqm, ci.duration, ci.harvest_method,
           ci.delivery_address, fo.price,
           f.name AS farm_name, f.region,
           (ci.area_sqm * fo.price) AS total_amount
    FROM qw_cart c
    JOIN qw_cart_item ci ON ci.cart_id  = c.cart_id
    JOIN qw_farm_offer fo ON ci.offer_id = fo.offer_id
    JOIN qw_farm f        ON fo.farm_id  = f.farm_id
    WHERE c.investor_id = ?
");
$cartStmt->execute([$investor_id]);
$myCart = $cartStmt->fetchAll();

// ── جلب تحديثات المزارع التي استثمر فيها ─────────────────
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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>قِنوان | المستثمر</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
  <style>
    /* أزرار التبديل قائمة/خريطة */
    .view-toggle{display:flex;border:1.5px solid var(--border);border-radius:8px;overflow:hidden;margin-right:8px;}
    .view-btn{padding:9px 15px;font-family:'Noto Naskh Arabic',serif;font-size:13px;font-weight:600;cursor:pointer;border:none;background:var(--bg-white);color:var(--text-muted);display:flex;align-items:center;gap:6px;transition:all 0.2s;}
    .view-btn+.view-btn{border-right:1px solid var(--border);}
    .view-btn:hover{background:#edf3ee;color:var(--green-dark);}
    .view-btn.active{background:var(--green-dark);color:var(--gold-light);}
    #leaflet-map{width:100%;height:500px;border-radius:10px;border:1.5px solid var(--border-light);box-shadow:0 4px 20px rgba(0,0,0,0.08);margin-top:0;}
    .leaflet-popup-content-wrapper{font-family:'Noto Naskh Arabic',serif !important;direction:rtl;border-radius:10px !important;}
    .leaflet-popup-content{margin:12px 14px !important;}

    /* صفحة العروض الجديدة */
    .offers-page {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: #f5f2ed;
      z-index: 500;
      overflow-y: auto;
      display: none;
    }
    .offers-page.active {
      display: block;
    }
    .offers-page-header {
      background: #2d5f33;
      padding: 20px 24px;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .offers-back-btn {
      background: rgba(255,255,255,0.2);
      border: none;
      color: #fff;
      padding: 8px 16px;
      border-radius: 30px;
      font-family: 'Noto Naskh Arabic', serif;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 16px;
    }
    .offers-back-btn:hover {
      background: rgba(255,255,255,0.3);
    }
    .offers-page-title {
      font-family: 'Amiri', serif;
      font-size: 28px;
      font-weight: 700;
      color: #fff;
      margin-bottom: 8px;
    }
    .offers-page-meta {
      font-size: 14px;
      color: #F2D998;
    }
    .offers-container {
      padding: 24px;
      max-width: 800px;
      margin: 0 auto;
    }
    .offer-card {
      background: #fff;
      border-radius: 20px;
      padding: 20px;
      margin-bottom: 20px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
      border: 1px solid #e2d9cf;
      transition: all 0.2s;
    }
    .offer-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    }
    .offer-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 2px solid #F2D998;
    }
    .offer-title {
      font-family: 'Amiri', serif;
      font-size: 20px;
      font-weight: 700;
      color: #2d5f33;
    }
    .offer-badge {
      background: #2d5f33;
      color: #F2D998;
      padding: 4px 12px;
      border-radius: 30px;
      font-size: 12px;
      font-weight: 600;
    }
    .offer-details-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 14px;
      margin-bottom: 16px;
    }
    .offer-detail {
      font-size: 14px;
      color: #5c4634;
    }
    .offer-detail strong {
      color: #2d5f33;
      display: block;
      font-size: 11px;
      margin-bottom: 4px;
    }
    .offer-price {
      font-size: 22px;
      font-weight: 700;
      color: #C8922A;
      margin: 12px 0;
    }
    .offer-actions {
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
    }
    .quantity-control {
      display: flex;
      align-items: center;
      gap: 12px;
      background: #f7f4ef;
      border-radius: 40px;
      padding: 4px 8px;
      border: 1.5px solid #e2d9cf;
    }
    .quantity-btn {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      border: none;
      background: #edf3ee;
      color: #2d5f33;
      font-size: 18px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s;
    }
    .quantity-btn:hover {
      background: #2d5f33;
      color: #fff;
    }
    .quantity-value {
      font-size: 16px;
      font-weight: 700;
      color: #2d5f33;
      min-width: 40px;
      text-align: center;
    }
    .btn-add-to-cart {
      background: #2d5f33;
      color: #fff;
      border: none;
      border-radius: 40px;
      padding: 10px 24px;
      font-family: 'Noto Naskh Arabic', serif;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
    }
    .btn-add-to-cart:hover {
      background: #1e4523;
      transform: scale(1.02);
    }
    .btn-add-to-cart:disabled {
      background: #b8cfb4;
      cursor: not-allowed;
      transform: none;
    }
    .cart-badge {
      background: #C8922A;
      color: #fff;
      border-radius: 30px;
      padding: 2px 8px;
      font-size: 12px;
      font-weight: 700;
      margin-right: 8px;
    }
    /* زر الإرسال لكل عنصر في السلة */
    .btn-submit-single {
      background: #C8922A;
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 8px 16px;
      font-family: 'Noto Naskh Arabic', serif;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }
    .btn-submit-single:hover {
      background: #b07a1a;
    }
    .toast {
      position: fixed;
      bottom: 30px;
      left: 50%;
      transform: translateX(-50%);
      background: #2d5f33;
      color: white;
      padding: 12px 24px;
      border-radius: 50px;
      z-index: 400;
      display: none;
      font-size: 14px;
      white-space: nowrap;
    }
  </style>
</head>
<body>

<!-- SVG sprites -->
<svg style="display:none" xmlns="http://www.w3.org/2000/svg">
  <symbol id="icon-send" viewBox="0 0 20 20">
    <path d="M2 10L18 3 11 18 9 12 2 10Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
  </symbol>
  <symbol id="icon-plus" viewBox="0 0 20 20">
    <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
  </symbol>
  <symbol id="icon-upload" viewBox="0 0 32 32">
    <path d="M16 22V10M16 10l-5 5M16 10l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M8 24h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
  </symbol>
  <symbol id="icon-heart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
  </symbol>
  <symbol id="icon-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <circle cx="9" cy="21" r="1"/>
    <circle cx="20" cy="21" r="1"/>
    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
  </symbol>
  <symbol id="icon-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
  </symbol>
  <symbol id="icon-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <circle cx="11" cy="11" r="8"/>
    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
  </symbol>
  <symbol id="palm" viewBox="0 0 40 50">
    <rect x="17" y="26" width="6" height="22" rx="3" fill="currentColor" opacity="0.5"/>
    <path d="M20 26 Q20 10 20 3" stroke="currentColor" stroke-width="5" stroke-linecap="round" fill="none"/>
    <path d="M20 20 Q30 12 38 8 Q30 18 22 23" fill="currentColor"/>
    <path d="M20 20 Q10 12 2 8 Q10 18 18 23" fill="currentColor"/>
    <path d="M20 22 Q32 18 36 14 Q28 21 21 24" fill="currentColor"/>
    <path d="M20 22 Q8 18 4 14 Q12 21 19 24" fill="currentColor"/>
  </symbol>
</svg>

<!-- ============================================================
     صفحة 1 — لوحة تحكم المستثمر
     ============================================================ -->
<div class="page active" id="page-dashboard">
  <nav>
    <button class="nav-back" onclick="history.back()">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link active" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link" onclick="showPage('browse')">استكشاف المزارع</button>
      <button class="nav-link" onclick="showPage('wishlist')">قائمة الرغبات</button>
      <button class="nav-link" onclick="showPage('cart')">سلة الاستثمار</button>
      <button class="nav-link" onclick="showPage('requests')">طلباتي</button>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div>
        <span class="logo-name">قِنوان</span>
        <span class="logo-sub">المستثمر</span>
      </div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">لوحة تحكم المستثمر</h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-dot"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:24px"></div>
      </div>
    </div>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">إجمالي الاستثمار</div>
        <div class="stat-value">٥٠,٠٠٠ ر.س</div>
        <div class="stat-sub">موزعة على ٣ مزارع</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">الاستثمارات النشطة</div>
        <div class="stat-value">٣</div>
        <div class="stat-sub">جارية حالياً</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">الطلبات المعلقة</div>
        <div class="stat-value">٢</div>
        <div class="stat-sub">قيد المراجعة</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">قائمة الرغبات</div>
        <div class="stat-value">٤</div>
        <div class="stat-sub">مزارع محفوظة</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">العائد المتوقع</div>
        <div class="stat-value">٨,٥٠٠ ر.س</div>
        <div class="stat-sub">أرباح متوقعة من الاستثمارات</div>
      </div>
    </div>

    <div class="two-columns">
      <div class="section-card">
        <div class="section-title">استثماراتي الحالية</div>
        <div class="investment-list">
          <div class="investment-item">
            <div class="investment-info">
              <div class="investment-name">مزرعة الأحساء التراثية</div>
              <div class="investment-meta">٢٥,٠٠٠ ر.س · تم الاستثمار: يناير ٢٠٢٦</div>
            </div>
            <span class="status-badge status-active">نشط</span>
          </div>
          <div class="investment-item">
            <div class="investment-info">
              <div class="investment-name">نخيل المدينة الملكية</div>
              <div class="investment-meta">١٥,٠٠٠ ر.س · تم الاستثمار: فبراير ٢٠٢٦</div>
            </div>
            <span class="status-badge status-active">نشط</span>
          </div>
          <div class="investment-item">
            <div class="investment-info">
              <div class="investment-name">مزرعة القصيم الخضراء</div>
              <div class="investment-meta">١٠,٠٠٠ ر.س · تم الاستثمار: مارس ٢٠٢٦</div>
            </div>
            <span class="status-badge status-pending">قيد الانتظار</span>
          </div>
        </div>
      </div>
      <div class="section-card">
        <div class="section-title">آخر التحديثات</div>
        <div class="updates-list">
          <div class="update-item">
            <div class="update-farm">مزرعة الأحساء التراثية</div>
            <p class="update-text">اقتراب موسم الحصاد — تمور الخلاص تنضج في الموعد المحدد.</p>
            <div class="update-date">منذ ٣ أيام</div>
          </div>
          <div class="update-item">
            <div class="update-farm">نخيل المدينة الملكية</div>
            <p class="update-text">تم تركيب نظام ري جديد، من المتوقع زيادة الإنتاج بنسبة ١٥٪.</p>
            <div class="update-date">منذ أسبوع</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     صفحة 2 — استكشاف المزارع (مع زر رجوع)
     ============================================================ -->
<div class="page" id="page-browse">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">← العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link active" onclick="showPage('browse')">استكشاف المزارع</button>
      <button class="nav-link" onclick="showPage('wishlist')">قائمة الرغبات</button>
      <button class="nav-link" onclick="showPage('cart')">سلة الاستثمار</button>
      <button class="nav-link" onclick="showPage('requests')">طلباتي</button>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المستثمر</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">استكشاف المزارع</h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:24px"></div>
      </div>
    </div>

    <div class="search-bar">
      <div class="search-input-wrapper">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8A6F5A" stroke-width="2">
          <use href="#icon-search"/>
        </svg>
        <input type="text" class="search-input" id="farmSearch" placeholder="ابحث عن مزرعة..." onkeyup="filterFarms()">
      </div>
      <select class="filter-select" id="regionFilter" onchange="filterFarms()">
        <option value="all">جميع المناطق</option>
        <option value="الرياض">الرياض</option>
        <option value="القصيم">القصيم</option>
        <option value="المدينة">المدينة المنورة</option>
        <option value="الأحساء">الأحساء</option>
      </select>
      <div class="view-toggle">
        <button class="view-btn active" id="btn-list" onclick="switchView('list')">
          <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><rect x="2" y="3" width="3" height="3" rx="0.5"/><rect x="7" y="3" width="11" height="3" rx="1"/><rect x="2" y="9" width="3" height="3" rx="0.5"/><rect x="7" y="9" width="11" height="3" rx="1"/><rect x="2" y="15" width="3" height="3" rx="0.5"/><rect x="7" y="15" width="11" height="3" rx="1"/></svg>
          قائمة
        </button>
        <button class="view-btn" id="btn-map" onclick="switchView('map')">
          <svg width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="1 5 1 19 7 16 13 19 19 16 19 2 13 5 7 2 1 5"/><line x1="7" y1="2" x2="7" y2="16"/><line x1="13" y1="5" x2="13" y2="19"/></svg>
          خريطة
        </button>
      </div>
    </div>

    <div id="view-map" style="display:none;margin-bottom:24px;"><div id="leaflet-map"></div></div>

    <div id="view-list">
      <div id="farms-list">
        <div class="farm-card" data-name="مزرعة العقيلي" data-region="القصيم">
          <div class="farm-card-inner">
            <div class="palm-icon"><svg width="28" height="32" viewBox="0 0 40 50" fill="#4f7f49"><use href="#palm"/></svg></div>
            <div class="farm-details">
              <div class="farm-name">مزرعة العقيلي</div>
              <div class="stars">★★★★★</div>
              <div class="farm-meta">القصيم · سكري</div>
              <div class="farm-price">سعر المتر: ٢٥ ر.س/م²</div>
            </div>
            <div class="farm-actions">
              <button class="icon-btn wishlist-btn" onclick="toggleWishlist(this, 'مزرعة العقيلي', 'القصيم', '٢٥')">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><use href="#icon-heart"/></svg>
              </button>
              <button class="btn-explore" onclick="openOffersPage('مزرعة العقيلي', 'القصيم', 'سكري')">استكشف المزرعة</button>
            </div>
          </div>
        </div>

        <div class="farm-card" data-name="واحة العجوة المباركة" data-region="المدينة">
          <div class="farm-card-inner">
            <div class="palm-icon"><svg width="28" height="32" viewBox="0 0 40 50" fill="#8A6F5A"><use href="#palm"/></svg></div>
            <div class="farm-details">
              <div class="farm-name">واحة العجوة المباركة</div>
              <div class="stars">★★★★☆</div>
              <div class="farm-meta">المدينة المنورة · عجوة</div>
              <div class="farm-price">سعر المتر: ٣٠ ر.س/م²</div>
            </div>
            <div class="farm-actions">
              <button class="icon-btn wishlist-btn" onclick="toggleWishlist(this, 'واحة العجوة المباركة', 'المدينة', '٣٠')">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><use href="#icon-heart"/></svg>
              </button>
              <button class="btn-explore" onclick="openOffersPage('واحة العجوة المباركة', 'المدينة المنورة', 'عجوة')">استكشف المزرعة</button>
            </div>
          </div>
        </div>

        <div class="farm-card" data-name="مزارع الخلاص الأصيلة" data-region="القصيم">
          <div class="farm-card-inner">
            <div class="palm-icon"><svg width="28" height="32" viewBox="0 0 40 50" fill="#C8922A"><use href="#palm"/></svg></div>
            <div class="farm-details">
              <div class="farm-name">مزارع الخلاص الأصيلة</div>
              <div class="stars">★★★☆☆</div>
              <div class="farm-meta">القصيم · خلاص</div>
              <div class="farm-price">سعر المتر: ٢٠ ر.س/م²</div>
            </div>
            <div class="farm-actions">
              <button class="icon-btn wishlist-btn" onclick="toggleWishlist(this, 'مزارع الخلاص الأصيلة', 'القصيم', '٢٠')">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><use href="#icon-heart"/></svg>
              </button>
              <button class="btn-explore" onclick="openOffersPage('مزارع الخلاص الأصيلة', 'القصيم', 'خلاص')">استكشف المزرعة</button>
            </div>
          </div>
        </div>

        <div class="farm-card" data-name="نخيل البرحي الفاخر" data-region="الأحساء">
          <div class="farm-card-inner">
            <div class="palm-icon"><svg width="28" height="32" viewBox="0 0 40 50" fill="#6E5242"><use href="#palm"/></svg></div>
            <div class="farm-details">
              <div class="farm-name">نخيل البرحي الفاخر</div>
              <div class="stars">★★★☆☆</div>
              <div class="farm-meta">الأحساء · برحي</div>
              <div class="farm-price">سعر المتر: ٢٢ ر.س/م²</div>
            </div>
            <div class="farm-actions">
              <button class="icon-btn wishlist-btn" onclick="toggleWishlist(this, 'نخيل البرحي الفاخر', 'الأحساء', '٢٢')">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><use href="#icon-heart"/></svg>
              </button>
              <button class="btn-explore" onclick="openOffersPage('نخيل البرحي الفاخر', 'الأحساء', 'برحي')">استكشف المزرعة</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     صفحة العروض الجديدة (تفتح عند استكشاف المزرعة)
     ============================================================ -->
<div id="offersPage" class="offers-page">
  <div class="offers-page-header">
    <button class="offers-back-btn" onclick="closeOffersPage()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M15 18l-6-6 6-6" stroke="currentColor"/>
      </svg>
      رجوع إلى المزارع
    </button>
    <div class="offers-page-title" id="offersPageTitle"></div>
    <div class="offers-page-meta" id="offersPageMeta"></div>
  </div>
  <div class="offers-container" id="offersContainer"></div>
</div>

<!-- ============================================================
     صفحة 3 — قائمة الرغبات
     ============================================================ -->
<div class="page" id="page-wishlist">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">← العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link" onclick="showPage('browse')">استكشاف المزارع</button>
      <button class="nav-link active" onclick="showPage('wishlist')">قائمة الرغبات</button>
      <button class="nav-link" onclick="showPage('cart')">سلة الاستثمار</button>
      <button class="nav-link" onclick="showPage('requests')">طلباتي</button>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المستثمر</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">قائمة الرغبات</h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-dot"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:24px"></div>
      </div>
    </div>
    <div id="wishlist-container" class="wishlist-container"></div>
  </div>
</div>

<!-- ============================================================
     صفحة 4 — سلة الاستثمار (مع زر إرسال لكل عرض)
     ============================================================ -->
<div class="page" id="page-cart">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">← العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link" onclick="showPage('browse')">استكشاف المزارع</button>
      <button class="nav-link" onclick="showPage('wishlist')">قائمة الرغبات</button>
      <button class="nav-link active" onclick="showPage('cart')">سلة الاستثمار</button>
      <button class="nav-link" onclick="showPage('requests')">طلباتي</button>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المستثمر</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">سلة الاستثمار</h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:24px"></div>
      </div>
    </div>
    <div id="cart-container"></div>
  </div>
</div>

<!-- ============================================================
     صفحة 5 — طلباتي (مع عينات: قيد الانتظار، مقبولة، مرفوضة)
     ============================================================ -->
<div class="page" id="page-requests">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">← العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link" onclick="showPage('browse')">استكشاف المزارع</button>
      <button class="nav-link" onclick="showPage('wishlist')">قائمة الرغبات</button>
      <button class="nav-link" onclick="showPage('cart')">سلة الاستثمار</button>
      <button class="nav-link active" onclick="showPage('requests')">طلباتي</button>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المستثمر</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">طلبات الاستثمار</h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-dot"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:24px"></div>
      </div>
    </div>

    <div class="filter-bar">
      <button class="filter-btn active" onclick="filterRequests('all',this)">الكل</button>
      <button class="filter-btn" onclick="filterRequests('pending',this)">قيد الانتظار</button>
      <button class="filter-btn" onclick="filterRequests('accepted',this)">مقبولة</button>
      <button class="filter-btn" onclick="filterRequests('rejected',this)">مرفوضة</button>
      <button class="filter-btn filter-btn-completed" onclick="filterRequests('completed',this)">✅ منتهية</button>
    </div>

    <div id="requests-list"></div>
  </div>
</div>

<!-- ============================================================
     Popup 1 — طريقة الحصاد (لطلب فردي)
     ============================================================ -->
<div id="popup-harvest-single" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:600;align-items:flex-start;justify-content:center;overflow-y:auto;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:480px;margin:20px auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <div style="background:#2d5f33;padding:20px 24px;position:relative;">
      <button onclick="closeHarvestPopup()" style="position:absolute;top:14px;left:16px;background:rgba(255,255,255,0.2);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;">✕</button>
      <div style="height:3px;background:repeating-linear-gradient(90deg,#C8922A 0px,#C8922A 8px,transparent 8px,transparent 14px);margin-bottom:14px;border-radius:2px;"></div>
      <div style="font-family:Amiri,serif;font-size:20px;font-weight:700;color:#fff;">كيف تريد استلام عائد استثمارك؟</div>
      <div style="font-size:13px;color:#F2D998;margin-top:4px;opacity:0.85;" id="singleFarmName"></div>
    </div>
    <div style="padding:24px;">
      <div class="harvest-option" onclick="selectSingleHarvest('sell')" id="ho-sell-single">
        <div style="font-size:22px;margin-bottom:6px;">💰</div>
        <div style="font-family:Amiri,serif;font-size:16px;font-weight:700;color:#2d5f33;margin-bottom:4px;">بيع المحصول واستلام الأرباح</div>
        <div style="font-size:12px;color:#8A6F5A;">يتم بيع التمور باسمك وتحويل الأرباح إليك</div>
      </div>
      <div class="harvest-option" onclick="selectSingleHarvest('receive')" id="ho-receive-single">
        <div style="font-size:22px;margin-bottom:6px;">📦</div>
        <div style="font-family:Amiri,serif;font-size:16px;font-weight:700;color:#2d5f33;margin-bottom:4px;">استلام التمور في المنزل</div>
        <div style="font-size:12px;color:#8A6F5A;">يتم توصيل حصتك من التمور مباشرة لعنوانك</div>
      </div>
      <div class="harvest-option" onclick="selectSingleHarvest('donate')" id="ho-donate-single">
        <div style="font-size:22px;margin-bottom:6px;">🤲</div>
        <div style="font-family:Amiri,serif;font-size:16px;font-weight:700;color:#2d5f33;margin-bottom:4px;">التبرع للجمعيات الخيرية</div>
        <div style="font-size:12px;color:#8A6F5A;">يتم التبرع بالمحصول لجمعيات خيرية معتمدة</div>
      </div>
      <div id="address-field-single" style="display:none;margin-top:16px;">
        <div style="background:#f2f8f3;border:1.5px solid #c5dfc8;border-radius:10px;padding:16px;margin-bottom:4px;">
          <div style="font-family:Amiri,serif;font-size:15px;font-weight:700;color:#2d5f33;margin-bottom:14px;">📍 عنوان التوصيل</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
            <div>
              <label style="display:block;font-size:11px;font-weight:600;color:#6E5242;margin-bottom:5px;">المدينة</label>
              <input type="text" id="addr-city-single" placeholder="مثال: الرياض"
                style="width:100%;background:#fff;border:1.5px solid #d9c8b0;border-radius:8px;padding:10px 12px;font-family:Noto Naskh Arabic,serif;font-size:13px;direction:rtl;outline:none;box-sizing:border-box;"/>
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:600;color:#6E5242;margin-bottom:5px;">الحي</label>
              <input type="text" id="addr-district-single" placeholder="اسم الحي"
                style="width:100%;background:#fff;border:1.5px solid #d9c8b0;border-radius:8px;padding:10px 12px;font-family:Noto Naskh Arabic,serif;font-size:13px;direction:rtl;outline:none;box-sizing:border-box;"/>
            </div>
          </div>
          <div style="margin-bottom:10px;">
            <label style="display:block;font-size:11px;font-weight:600;color:#6E5242;margin-bottom:5px;">اسم الشارع</label>
            <input type="text" id="addr-street-single" placeholder="اسم الشارع أو رقمه"
              style="width:100%;background:#fff;border:1.5px solid #d9c8b0;border-radius:8px;padding:10px 12px;font-family:Noto Naskh Arabic,serif;font-size:13px;direction:rtl;outline:none;box-sizing:border-box;"/>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
            <div>
              <label style="display:block;font-size:11px;font-weight:600;color:#6E5242;margin-bottom:5px;">رقم المبنى</label>
              <input type="text" id="addr-building-single" placeholder="رقم المبنى"
                style="width:100%;background:#fff;border:1.5px solid #d9c8b0;border-radius:8px;padding:10px 12px;font-family:Noto Naskh Arabic,serif;font-size:13px;direction:rtl;outline:none;box-sizing:border-box;"/>
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:600;color:#6E5242;margin-bottom:5px;">الرمز البريدي</label>
              <input type="text" id="addr-postal-single" placeholder="مثال: 12345"
                style="width:100%;background:#fff;border:1.5px solid #d9c8b0;border-radius:8px;padding:10px 12px;font-family:Noto Naskh Arabic,serif;font-size:13px;direction:rtl;outline:none;box-sizing:border-box;"/>
            </div>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#6E5242;margin-bottom:5px;">ملاحظات إضافية (اختياري)</label>
            <input type="text" id="addr-notes-single" placeholder="مثال: الدور الثاني، أمام المسجد..."
              style="width:100%;background:#fff;border:1.5px solid #d9c8b0;border-radius:8px;padding:10px 12px;font-family:Noto Naskh Arabic,serif;font-size:13px;direction:rtl;outline:none;box-sizing:border-box;"/>
          </div>
        </div>
        <!-- الحقل المجمّع المخفي للاستخدام الداخلي -->
        <input type="hidden" id="delivery-address-single"/>
      </div>
      <button onclick="proceedSingleToPayment()"
        style="width:100%;background:#2d5f33;color:#fff;border:none;border-radius:8px;padding:14px;font-family:Noto Naskh Arabic,serif;font-size:15px;font-weight:700;cursor:pointer;margin-top:20px;">
        التالي — الدفع
      </button>
    </div>
  </div>
</div>

<!-- ============================================================
     Popup 2 — معلومات الدفع (للطلب الفردي)
     ============================================================ -->
<div id="popup-payment-single" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:600;align-items:flex-start;justify-content:center;overflow-y:auto;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:480px;margin:20px auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <div style="background:#2d5f33;padding:20px 24px;position:relative;">
      <button onclick="closeSinglePayment()" style="position:absolute;top:14px;left:16px;background:rgba(255,255,255,0.2);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;">✕</button>
      <div style="height:3px;background:repeating-linear-gradient(90deg,#C8922A 0px,#C8922A 8px,transparent 8px,transparent 14px);margin-bottom:14px;border-radius:2px;"></div>
      <div style="font-family:Amiri,serif;font-size:20px;font-weight:700;color:#fff;">معلومات الدفع</div>
      <div style="font-size:13px;color:#F2D998;margin-top:4px;" id="payment-summary-single"></div>
    </div>
    <div style="padding:24px;">
      <div id="payment-item-single" style="background:#f7f4ef;border-radius:8px;padding:14px;margin-bottom:20px;"></div>
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:13px;font-weight:600;color:#6E5242;margin-bottom:8px;">اسم حامل البطاقة</label>
        <input type="text" id="card-name-single" placeholder="الاسم كما هو على البطاقة"
          style="width:100%;background:#f7f4ef;border:1.5px solid #d9c8b0;border-radius:8px;padding:12px 14px;"/>
      </div>
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:13px;font-weight:600;color:#6E5242;margin-bottom:8px;">رقم البطاقة</label>
        <input type="text" id="card-number-single" placeholder="XXXX  XXXX  XXXX  XXXX" maxlength="19"
          oninput="formatCardSingle(this)"
          style="width:100%;background:#f7f4ef;border:1.5px solid #d9c8b0;border-radius:8px;padding:12px 14px;font-family:monospace;"/>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
        <div>
          <label style="display:block;font-size:13px;font-weight:600;color:#6E5242;margin-bottom:8px;">تاريخ الانتهاء</label>
          <input type="text" id="card-expiry-single" placeholder="MM / YY" maxlength="7"
            oninput="formatExpirySingle(this)"
            style="width:100%;background:#f7f4ef;border:1.5px solid #d9c8b0;border-radius:8px;padding:12px 14px;"/>
        </div>
        <div>
          <label style="display:block;font-size:13px;font-weight:600;color:#6E5242;margin-bottom:8px;">CVV</label>
          <input type="password" id="card-cvv-single" placeholder="•••" maxlength="4"
            style="width:100%;background:#f7f4ef;border:1.5px solid #d9c8b0;border-radius:8px;padding:12px 14px;"/>
        </div>
      </div>
      <div style="background:#f2f8f3;border-radius:8px;padding:14px 18px;display:flex;justify-content:space-between;margin-bottom:20px;">
        <span>إجمالي المبلغ</span>
        <span id="payment-total-single" style="font-family:Amiri,serif;font-size:22px;font-weight:700;color:#2d5f33;"></span>
      </div>
      <div style="display:flex;gap:10px;">
        <button onclick="closeSinglePayment()" style="flex:1;background:transparent;color:#8B2A2A;border:1.5px solid rgba(139,42,42,0.35);border-radius:8px;padding:13px;">إلغاء</button>
        <button onclick="confirmSinglePayment()" style="flex:2;background:#2d5f33;color:#fff;border:none;border-radius:8px;padding:13px;">تأكيد الدفع</button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     Popup 3 — تأكيد النجاح
     ============================================================ -->
<div id="popup-success-single" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:600;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:400px;margin:20px;padding:40px 32px;text-align:center;">
    <div style="width:64px;height:64px;background:#edf3ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px;">✓</div>
    <div style="font-family:Amiri,serif;font-size:22px;font-weight:700;color:#2d5f33;margin-bottom:8px;">تم إرسال طلبك بنجاح</div>
    <div style="font-size:13px;color:#8A6F5A;margin-bottom:24px;">سيقوم المزارع بمراجعة طلبك والرد خلال ٧ أيام.</div>
    <button onclick="closeSingleSuccess()" style="width:100%;background:#2d5f33;color:#fff;border:none;border-radius:8px;padding:13px;">عرض طلباتي</button>
  </div>
</div>

<style>
.harvest-option {
  border: 1.5px solid #e2d9cf;
  border-radius: 10px;
  padding: 16px 18px;
  margin-bottom: 10px;
  cursor: pointer;
  transition: all 0.2s;
  text-align: right;
}
.harvest-option:hover { border-color: #2d5f33; background: #f2f8f3; }
.harvest-option.selected { border-color: #2d5f33; background: #f2f8f3; box-shadow: 0 0 0 2px rgba(45,95,51,0.15); }
.toast { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); background: #2d5f33; color: white; padding: 12px 24px; border-radius: 50px; z-index: 400; display: none; font-size: 14px; white-space: nowrap; }
.request-card { background: #fff; border-radius: 12px; padding: 16px; margin-bottom: 12px; border-right: 4px solid; }
.request-card.pending { border-right-color: #C8922A; }
.request-card.accepted { border-right-color: #2d5f33; }
.request-card.rejected { border-right-color: #8B2A2A; }
.request-card.completed { border-right-color: #5a6e8c; background: linear-gradient(135deg, #fff 0%, #f4f7fb 100%); }
.status-badge.pending-badge { background: #C8922A20; color: #C8922A; }
.status-badge.accepted-badge { background: #2d5f3320; color: #2d5f33; }
.status-badge.rejected-badge { background: #8B2A2A20; color: #8B2A2A; }
.status-badge.completed-badge { background: #5a6e8c18; color: #5a6e8c; }
.filter-btn-completed { border-color: #5a6e8c !important; color: #5a6e8c !important; }
.filter-btn-completed.active { background: #5a6e8c !important; color: #fff !important; }

/* نجوم التقييم */
.star-rating { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 4px; }
.star-rating input { display: none; }
.star-rating label {
  font-size: 28px;
  color: #d9c8b0;
  cursor: pointer;
  transition: color 0.15s, transform 0.15s;
  line-height: 1;
}
.star-rating label:hover,
.star-rating label:hover ~ label,
.star-rating input:checked ~ label { color: #C8922A; }
.star-rating label:hover { transform: scale(1.2); }
.star-display { color: #C8922A; font-size: 16px; letter-spacing: 2px; }
.star-display.grey { color: #d9c8b0; }
.btn-rate {
  background: #5a6e8c;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 7px 16px;
  font-family: 'Noto Naskh Arabic', serif;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background 0.2s;
}
.btn-rate:hover { background: #485a72; }
.btn-rate.rated { background: #C8922A; cursor: default; }
</style>

<div class="toast" id="toast"></div>

<!-- ============================================================
     Popup التقييم — المزرعة والمزارع
     ============================================================ -->
<div id="popup-rating" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:700;align-items:flex-start;justify-content:center;overflow-y:auto;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:460px;margin:24px auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.22);">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,#2d5f33,#3d7a45);padding:22px 24px;position:relative;">
      <button onclick="closeRatingPopup()" style="position:absolute;top:14px;left:16px;background:rgba(255,255,255,0.2);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;">✕</button>
      <div style="height:3px;background:repeating-linear-gradient(90deg,#C8922A 0,#C8922A 8px,transparent 8px,transparent 14px);margin-bottom:14px;border-radius:2px;"></div>
      <div style="font-family:Amiri,serif;font-size:21px;font-weight:700;color:#fff;">قيّم تجربتك الاستثمارية</div>
      <div style="font-size:13px;color:#F2D998;margin-top:4px;" id="rating-farm-label"></div>
    </div>
    <div style="padding:24px;">

      <!-- ملخص الاستثمار -->
      <div id="rating-summary" style="background:#f7f4ef;border-radius:10px;padding:14px 16px;margin-bottom:22px;"></div>

      <!-- تقييم المزرعة -->
      <div style="margin-bottom:22px;">
        <div style="font-family:Amiri,serif;font-size:16px;font-weight:700;color:#2d5f33;margin-bottom:4px;">🌴 تقييم المزرعة</div>
        <div style="font-size:12px;color:#8A6F5A;margin-bottom:12px;">ما مدى رضاك عن المزرعة؟ (الجودة، الإنتاج، الشفافية)</div>
        <div style="display:flex;gap:8px;justify-content:center;margin-bottom:8px;" id="stars-farm">
          <span class="rstar" data-type="farm" data-val="5" onclick="setRating('farm',5)" style="font-size:36px;cursor:pointer;color:#d9c8b0;transition:color .15s,transform .15s;">★</span>
          <span class="rstar" data-type="farm" data-val="4" onclick="setRating('farm',4)" style="font-size:36px;cursor:pointer;color:#d9c8b0;transition:color .15s,transform .15s;">★</span>
          <span class="rstar" data-type="farm" data-val="3" onclick="setRating('farm',3)" style="font-size:36px;cursor:pointer;color:#d9c8b0;transition:color .15s,transform .15s;">★</span>
          <span class="rstar" data-type="farm" data-val="2" onclick="setRating('farm',2)" style="font-size:36px;cursor:pointer;color:#d9c8b0;transition:color .15s,transform .15s;">★</span>
          <span class="rstar" data-type="farm" data-val="1" onclick="setRating('farm',1)" style="font-size:36px;cursor:pointer;color:#d9c8b0;transition:color .15s,transform .15s;">★</span>
        </div>
        <div style="text-align:center;font-size:13px;color:#C8922A;font-weight:600;min-height:20px;" id="rating-farm-text"></div>
      </div>

      <div style="height:1px;background:#e8ddd3;margin-bottom:22px;"></div>

      <!-- تقييم المزارع -->
      <div style="margin-bottom:22px;">
        <div style="font-family:Amiri,serif;font-size:16px;font-weight:700;color:#2d5f33;margin-bottom:4px;">👨‍🌾 تقييم المزارع</div>
        <div style="font-size:12px;color:#8A6F5A;margin-bottom:12px;">ما مدى رضاك عن المزارع؟ (التواصل، الأمانة، الالتزام)</div>
        <div style="display:flex;gap:8px;justify-content:center;margin-bottom:8px;" id="stars-farmer">
          <span class="rstar" data-type="farmer" data-val="5" onclick="setRating('farmer',5)" style="font-size:36px;cursor:pointer;color:#d9c8b0;transition:color .15s,transform .15s;">★</span>
          <span class="rstar" data-type="farmer" data-val="4" onclick="setRating('farmer',4)" style="font-size:36px;cursor:pointer;color:#d9c8b0;transition:color .15s,transform .15s;">★</span>
          <span class="rstar" data-type="farmer" data-val="3" onclick="setRating('farmer',3)" style="font-size:36px;cursor:pointer;color:#d9c8b0;transition:color .15s,transform .15s;">★</span>
          <span class="rstar" data-type="farmer" data-val="2" onclick="setRating('farmer',2)" style="font-size:36px;cursor:pointer;color:#d9c8b0;transition:color .15s,transform .15s;">★</span>
          <span class="rstar" data-type="farmer" data-val="1" onclick="setRating('farmer',1)" style="font-size:36px;cursor:pointer;color:#d9c8b0;transition:color .15s,transform .15s;">★</span>
        </div>
        <div style="text-align:center;font-size:13px;color:#C8922A;font-weight:600;min-height:20px;" id="rating-farmer-text"></div>
      </div>

      <!-- تعليق اختياري -->
      <div style="margin-bottom:22px;">
        <label style="display:block;font-size:13px;font-weight:600;color:#6E5242;margin-bottom:8px;">تعليق (اختياري)</label>
        <textarea id="rating-comment" rows="3" placeholder="شاركنا تجربتك مع هذه المزرعة..."
          style="width:100%;background:#f7f4ef;border:1.5px solid #d9c8b0;border-radius:8px;padding:12px 14px;font-family:Noto Naskh Arabic,serif;font-size:13px;direction:rtl;resize:none;outline:none;box-sizing:border-box;"></textarea>
      </div>

      <button onclick="submitRating()"
        style="width:100%;background:#2d5f33;color:#fff;border:none;border-radius:8px;padding:14px;font-family:Noto Naskh Arabic,serif;font-size:15px;font-weight:700;cursor:pointer;">
        إرسال التقييم ⭐
      </button>
    </div>
  </div>
</div>

<!-- Popup نجاح التقييم -->
<div id="popup-rating-success" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:800;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:360px;margin:20px;padding:40px 28px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <div style="font-size:54px;margin-bottom:12px;">⭐</div>
    <div style="font-family:Amiri,serif;font-size:22px;font-weight:700;color:#2d5f33;margin-bottom:8px;">شكراً على تقييمك!</div>
    <div style="font-size:13px;color:#8A6F5A;margin-bottom:8px;" id="rating-success-stars"></div>
    <div style="font-size:12px;color:#8A6F5A;margin-bottom:24px;">تقييمك يساعد المستثمرين الآخرين على اتخاذ قرارات أفضل.</div>
    <button onclick="closeRatingSuccess()" style="width:100%;background:#2d5f33;color:#fff;border:none;border-radius:8px;padding:13px;font-family:Noto Naskh Arabic,serif;font-weight:700;cursor:pointer;">عرض طلباتي</button>
  </div>
</div>

<script>
// ============================================================
// البيانات الأساسية
// ============================================================
let cart = [];
let nextCartId = 100;
let wishlist = [];

// عينات الطلبات (قيد الانتظار، مقبولة، مرفوضة)
let investmentRequests = [
  { id: 1, farmName: 'مزرعة العقيلي', area: '١٠٠ م²', duration: '١ سنة', amount: 2500, status: 'pending', submittedAt: '٢٠٢٦-٠٣-١٥', harvest: 'sell' },
  { id: 2, farmName: 'واحة العجوة المباركة', area: '٨٠ م²', duration: '١ سنة', amount: 2400, status: 'accepted', submittedAt: '٢٠٢٦-٠٣-١٠', harvest: 'receive' },
  { id: 3, farmName: 'مزارع الخلاص الأصيلة', area: '١٢٠ م²', duration: '١ سنة', amount: 2400, status: 'rejected', submittedAt: '٢٠٢٦-٠٣-٠٥', harvest: 'donate' },
  { id: 4, farmName: 'نخيل البرحي الفاخر', area: '٩٠ م²', duration: '١ سنة', amount: 1980, status: 'pending', submittedAt: '٢٠٢٦-٠٣-١٨', harvest: 'sell' },
  { id: 5, farmName: 'مزرعة العقيلي', area: '٢٠٠ م²', duration: '٣ سنوات', amount: 5000, status: 'accepted', submittedAt: '٢٠٢٦-٠٢-٢٨', harvest: 'sell' },
  // استثمارات منتهية
  { id: 6, farmName: 'مزرعة العقيلي', area: '١٠٠ م²', duration: '١ سنة', amount: 2500, status: 'completed', submittedAt: '٢٠٢٤-١١-٠١', completedAt: '٢٠٢٥-١١-٠١', harvest: 'sell', profit: 3125, region: 'القصيم', type: 'سكري' },
  { id: 7, farmName: 'واحة العجوة المباركة', area: '١٥٠ م²', duration: '٣ سنوات', amount: 4500, status: 'completed', submittedAt: '٢٠٢٢-٠٦-١٠', completedAt: '٢٠٢٥-٠٦-١٠', harvest: 'receive', profit: 6075, region: 'المدينة المنورة', type: 'عجوة' },
  { id: 8, farmName: 'مزارع الخلاص الأصيلة', area: '٢٥٠ م²', duration: '٣ سنوات', amount: 5000, status: 'completed', submittedAt: '٢٠٢٢-٠٩-٢٠', completedAt: '٢٠٢٥-٠٩-٢٠', harvest: 'sell', profit: 6700, region: 'القصيم', type: 'خلاص', farmRating: 4, farmerRating: 5 }
];

// تخزين التقييمات
let ratings = {};

// عروض المزارع
const farmOffersData = {
  'مزرعة العقيلي': {
    farmName: 'مزرعة العقيلي',
    region: 'القصيم',
    type: 'سكري',
    offers: [
      { id: 1, area: '١٠٠ م²', duration: '١ سنة', returnRate: '١٢٪', totalPrice: 2500, pricePerMeter: 25, minArea: 50 },
      { id: 2, area: '٢٠٠ م²', duration: '٣ سنوات', returnRate: '١٥٪', totalPrice: 5000, pricePerMeter: 25, minArea: 50 },
      { id: 3, area: '٥٠٠ م²', duration: '٥ سنوات', returnRate: '١٨٪', totalPrice: 12500, pricePerMeter: 25, minArea: 50 }
    ]
  },
  'واحة العجوة المباركة': {
    farmName: 'واحة العجوة المباركة',
    region: 'المدينة المنورة',
    type: 'عجوة',
    offers: [
      { id: 1, area: '٨٠ م²', duration: '١ سنة', returnRate: '١٠٪', totalPrice: 2400, pricePerMeter: 30, minArea: 40 },
      { id: 2, area: '١٥٠ م²', duration: '٣ سنوات', returnRate: '١٤٪', totalPrice: 4500, pricePerMeter: 30, minArea: 40 },
      { id: 3, area: '٣٠٠ م²', duration: '٥ سنوات', returnRate: '١٧٪', totalPrice: 9000, pricePerMeter: 30, minArea: 40 }
    ]
  },
  'مزارع الخلاص الأصيلة': {
    farmName: 'مزارع الخلاص الأصيلة',
    region: 'القصيم',
    type: 'خلاص',
    offers: [
      { id: 1, area: '١٢٠ م²', duration: '١ سنة', returnRate: '١١٪', totalPrice: 2400, pricePerMeter: 20, minArea: 60 },
      { id: 2, area: '٢٥٠ م²', duration: '٣ سنوات', returnRate: '١٤٪', totalPrice: 5000, pricePerMeter: 20, minArea: 60 },
      { id: 3, area: '٦٠٠ م²', duration: '٥ سنوات', returnRate: '١٦٪', totalPrice: 12000, pricePerMeter: 20, minArea: 60 }
    ]
  },
  'نخيل البرحي الفاخر': {
    farmName: 'نخيل البرحي الفاخر',
    region: 'الأحساء',
    type: 'برحي',
    offers: [
      { id: 1, area: '٩٠ م²', duration: '١ سنة', returnRate: '١١٪', totalPrice: 1980, pricePerMeter: 22, minArea: 45 },
      { id: 2, area: '١٨٠ م²', duration: '٣ سنوات', returnRate: '١٤٪', totalPrice: 3960, pricePerMeter: 22, minArea: 45 },
      { id: 3, area: '٤٥٠ م²', duration: '٥ سنوات', returnRate: '١٧٪', totalPrice: 9900, pricePerMeter: 22, minArea: 45 }
    ]
  }
};

let selectedOffers = {};
let currentSingleItem = null;
let selectedSingleHarvest = null;

// ============================================================
// دوال صفحة العروض
// ============================================================
function openOffersPage(farmName, region, type) {
  const farmData = farmOffersData[farmName];
  if (!farmData) {
    showToast('لا توجد عروض متاحة لهذه المزرعة حالياً');
    return;
  }
  
  document.getElementById('offersPageTitle').textContent = farmName;
  document.getElementById('offersPageMeta').innerHTML = `${region} · نوع التمر: ${type}`;
  
  const container = document.getElementById('offersContainer');
  container.innerHTML = '';
  
  farmData.offers.forEach((offer, idx) => {
    const offerKey = `${farmName}_${offer.id}`;
    const selectedQty = selectedOffers[offerKey] || 0;
    
    const offerDiv = document.createElement('div');
    offerDiv.className = 'offer-card';
    offerDiv.innerHTML = `
      <div class="offer-header">
        <span class="offer-title">عرض ${idx + 1}</span>
        <span class="offer-badge">${offer.duration}</span>
      </div>
      <div class="offer-details-grid">
        <div class="offer-detail"><strong>المساحة</strong>${offer.area}</div>
        <div class="offer-detail"><strong>مدة الاستثمار</strong>${offer.duration}</div>
        <div class="offer-detail"><strong>العائد المتوقع</strong>${offer.returnRate}</div>
        <div class="offer-detail"><strong>الحد الأدنى</strong>${offer.minArea} م²</div>
        <div class="offer-detail"><strong>نوع التمر</strong>${farmData.type}</div>
      </div>
      <div class="offer-price">${offer.totalPrice.toLocaleString()} ر.س</div>
      <div class="offer-actions">
        <button class="btn-add-to-cart" id="addBtn_${farmName}_${offer.id}" onclick="addOfferToCart('${farmName}', ${offer.id})">
          🛒 أضف للسلة
        </button>
      </div>
    `;
    container.appendChild(offerDiv);
  });
  
  document.getElementById('offersPage').classList.add('active');
}

function closeOffersPage() {
  document.getElementById('offersPage').classList.remove('active');
}

function changeOfferQuantity(farmName, offerId, delta) {
  const farmData = farmOffersData[farmName];
  const offer = farmData.offers.find(o => o.id === offerId);
  const offerKey = `${farmName}_${offerId}`;
  const currentQty = selectedOffers[offerKey] || 0;
  const newQty = Math.max(0, currentQty + delta);
  
  if (newQty === 0) {
    delete selectedOffers[offerKey];
  } else {
    selectedOffers[offerKey] = newQty;
  }
  
  const qtySpan = document.getElementById(`qty_${farmName}_${offerId}`);
  if (qtySpan) qtySpan.textContent = newQty;
  
  const addBtn = document.getElementById(`addBtn_${farmName}_${offerId}`);
  if (addBtn) addBtn.disabled = newQty === 0;
}

function addOfferToCart(farmName, offerId) {
  const farmData = farmOffersData[farmName];
  const offer = farmData.offers.find(o => o.id === offerId);
  const offerKey = `${farmName}_${offerId}`;
  const quantity = 1;
  
  // منع الإضافة المكررة لنفس العرض
  const alreadyInCart = cart.find(i => i.farmName === farmName && i.offerId === offerId);
  if (alreadyInCart) {
    showToast('هذا العرض موجود مسبقاً في السلة');
    return;
  }
  
  const totalPrice = offer.totalPrice * quantity;
  
  const cartItem = {
    id: nextCartId++,
    farmName: farmName,
    region: farmData.region,
    type: farmData.type,
    area: offer.area,
    duration: offer.duration,
    returnRate: offer.returnRate,
    quantity: quantity,
    pricePerUnit: offer.totalPrice,
    total: totalPrice,
    offerId: offerId
  };
  
  cart.push(cartItem);
  delete selectedOffers[offerKey];
  
  const qtySpan = document.getElementById(`qty_${farmName}_${offerId}`);
  if (qtySpan) qtySpan.textContent = '0';
  
  const addBtn = document.getElementById(`addBtn_${farmName}_${offerId}`);
  if (addBtn) {
    addBtn.disabled = true;
    addBtn.textContent = '✓ تمت الإضافة';
    addBtn.style.background = '#5a9e60';
  }
  
  showToast(`تمت إضافة ${offer.area} من ${farmName} إلى السلة`);
}

// ============================================================
// دوال السلة (مع زر إرسال لكل عرض)
// ============================================================
function renderCart() {
  const container = document.getElementById('cart-container');
  if (!container) return;
  
  if (!cart.length) {
    container.innerHTML = '<div style="text-align:center;padding:60px 0;color:var(--text-faint);font-size:15px;">السلة فارغة</div>';
    return;
  }
  
  const total = cart.reduce((s, i) => s + (i.total || 0), 0);
  
  container.innerHTML = cart.map(item => `
    <div class="request-card status-accepted" style="margin-bottom:14px;">
      <div class="request-header">
        <span class="status-badge" style="background:#edf3ee;color:#2d5f33;border:1px solid rgba(45,95,51,0.25);">في السلة</span>
        <span class="request-name">${item.farmName}</span>
      </div>
      <div class="request-details">
        <span class="request-detail">نوع التمر: ${item.type || '—'}</span>
        <span class="request-detail">${item.area || ''}</span>
        <span class="request-detail">${item.duration || ''}</span>
        <span class="request-detail">الكمية: ${item.quantity || 1}</span>
        <span class="request-detail" style="color:#2d5f33;font-weight:700;">${(item.total || 0).toLocaleString()} ر.س</span>
      </div>
      <div class="request-actions" style="display:flex;gap:10px;margin-top:12px;">
        <button onclick="removeFromCart(${item.id})"
          style="background:transparent;color:#8B2A2A;border:1.5px solid rgba(139,42,42,0.35);border-radius:8px;padding:8px 18px;cursor:pointer;">
          حذف
        </button>
        <button onclick="submitSingleRequest(${item.id})"
          class="btn-submit-single">
          📤 إرسال الطلب
        </button>
      </div>
    </div>
  `).join('') + `
    <div style="background:#f2f8f3;border:1px solid rgba(45,95,51,0.2);border-radius:8px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;margin:16px 0;">
      <span style="font-size:14px;color:#8A6F5A;">إجمالي السلة</span>
      <span style="font-family:Amiri,serif;font-size:22px;font-weight:700;color:#2d5f33;">${total.toLocaleString()} ر.س</span>
    </div>
    <div style="text-align:center;">
      <button onclick="submitAllRequests()"
        style="background:#2d5f33;color:#fff;border:none;border-radius:8px;padding:14px 60px;font-family:Noto Naskh Arabic,serif;font-size:15px;font-weight:700;cursor:pointer;">
        إرسال جميع الطلبات
      </button>
    </div>`;
}

function removeFromCart(id) {
  cart = cart.filter(i => i.id !== id);
  renderCart();
  showToast('تم الحذف من السلة');
}

// إرسال طلب فردي
function submitSingleRequest(cartId) {
  const item = cart.find(i => i.id === cartId);
  if (!item) return;
  currentSingleItem = item;
  selectedSingleHarvest = null;
  
  document.getElementById('singleFarmName').textContent = `${item.farmName} · ${item.area} · ${item.duration}`;
  document.querySelectorAll('#popup-harvest-single .harvest-option').forEach(o => o.classList.remove('selected'));
  document.getElementById('address-field-single').style.display = 'none';
  ['addr-city-single','addr-district-single','addr-street-single','addr-building-single','addr-postal-single','addr-notes-single'].forEach(id => { const el = document.getElementById(id); if(el) el.value=''; });
  document.getElementById('delivery-address-single').value = '';
  
  document.getElementById('popup-harvest-single').style.display = 'flex';
}

function closeHarvestPopup() {
  document.getElementById('popup-harvest-single').style.display = 'none';
  currentSingleItem = null;
  selectedSingleHarvest = null;
}

function selectSingleHarvest(method) {
  selectedSingleHarvest = method;
  document.querySelectorAll('#popup-harvest-single .harvest-option').forEach(o => o.classList.remove('selected'));
  document.getElementById(`ho-${method}-single`).classList.add('selected');
  document.getElementById('address-field-single').style.display = method === 'receive' ? 'block' : 'none';
}

function proceedSingleToPayment() {
  if (!selectedSingleHarvest) { showToast('اختر طريقة الحصاد أولاً'); return; }
  if (selectedSingleHarvest === 'receive') {
    const city = document.getElementById('addr-city-single').value.trim();
    const district = document.getElementById('addr-district-single').value.trim();
    const street = document.getElementById('addr-street-single').value.trim();
    const building = document.getElementById('addr-building-single').value.trim();
    if (!city || !district || !street || !building) {
      showToast('أكمل بيانات العنوان (المدينة، الحي، الشارع، رقم المبنى)');
      return;
    }
    const postal = document.getElementById('addr-postal-single').value.trim();
    const notes = document.getElementById('addr-notes-single').value.trim();
    const fullAddress = `${city}، حي ${district}، ${street}، مبنى ${building}${postal ? '، الرمز: ' + postal : ''}${notes ? '، ' + notes : ''}`;
    document.getElementById('delivery-address-single').value = fullAddress;
  }
  
  const harvestLabel = { sell: 'بيع المحصول', receive: 'توصيل للمنزل', donate: 'تبرع' }[selectedSingleHarvest];
  document.getElementById('payment-summary-single').textContent = `${harvestLabel}`;
  document.getElementById('payment-item-single').innerHTML = `
    <div style="display:flex;justify-content:space-between;">
      <span style="font-weight:600;">${currentSingleItem.farmName}</span>
      <span style="color:#2d5f33;font-weight:700;">${currentSingleItem.total.toLocaleString()} ر.س</span>
    </div>
    <div style="font-size:12px;color:#8A6F5A;margin-top:8px;">${currentSingleItem.area} · ${currentSingleItem.duration} · الكمية: ${currentSingleItem.quantity}</div>
  `;
  document.getElementById('payment-total-single').textContent = currentSingleItem.total.toLocaleString() + ' ر.س';
  
  ['card-name-single', 'card-number-single', 'card-expiry-single', 'card-cvv-single'].forEach(id => document.getElementById(id).value = '');
  
  document.getElementById('popup-harvest-single').style.display = 'none';
  document.getElementById('popup-payment-single').style.display = 'flex';
}

function formatCardSingle(input) {
  let v = input.value.replace(/\D/g, '').substring(0, 16);
  input.value = v.replace(/(.{4})/g, '$1  ').trim();
}

function formatExpirySingle(input) {
  let v = input.value.replace(/\D/g, '').substring(0, 4);
  if (v.length >= 3) v = v.substring(0, 2) + ' / ' + v.substring(2);
  input.value = v;
}

function closeSinglePayment() {
  document.getElementById('popup-payment-single').style.display = 'none';
  document.getElementById('popup-harvest-single').style.display = 'flex';
}

function confirmSinglePayment() {
  const name = document.getElementById('card-name-single').value.trim();
  const number = document.getElementById('card-number-single').value.trim();
  
  if (!name || !number) {
    showToast('أكمل بيانات البطاقة'); return;
  }
  if (number.replace(/\s/g, '').length < 16) {
    showToast('رقم البطاقة غير مكتمل'); return;
  }
  
  // إضافة الطلب إلى قائمة الطلبات
  const newRequest = {
    id: investmentRequests.length + 1,
    farmName: currentSingleItem.farmName,
    area: currentSingleItem.area,
    duration: currentSingleItem.duration,
    amount: currentSingleItem.total,
    status: 'pending',
    submittedAt: new Date().toLocaleDateString('ar-SA'),
    harvest: selectedSingleHarvest
  };
  investmentRequests.unshift(newRequest);
  
  // حذف من السلة
  cart = cart.filter(i => i.id !== currentSingleItem.id);
  renderCart();
  renderRequests();
  
  document.getElementById('popup-payment-single').style.display = 'none';
  document.getElementById('popup-success-single').style.display = 'flex';
}

function closeSingleSuccess() {
  document.getElementById('popup-success-single').style.display = 'none';
  showPage('requests');
}

function submitAllRequests() {
  if (!cart.length) return;
  
  cart.forEach(item => {
    const newRequest = {
      id: investmentRequests.length + 1,
      farmName: item.farmName,
      area: item.area,
      duration: item.duration,
      amount: item.total,
      status: 'pending',
      submittedAt: new Date().toLocaleDateString('ar-SA'),
      harvest: 'sell'
    };
    investmentRequests.unshift(newRequest);
  });
  
  cart = [];
  renderCart();
  renderRequests();
  showToast(`تم إرسال جميع الطلبات بنجاح`);
  showPage('requests');
}

// ============================================================
// دوال الطلبات
// ============================================================
function renderRequests(filter = 'all') {
  const container = document.getElementById('requests-list');
  if (!container) return;
  
  let filtered = investmentRequests;
  if (filter === 'pending') filtered = investmentRequests.filter(r => r.status === 'pending');
  else if (filter === 'accepted') filtered = investmentRequests.filter(r => r.status === 'accepted');
  else if (filter === 'rejected') filtered = investmentRequests.filter(r => r.status === 'rejected');
  else if (filter === 'completed') filtered = investmentRequests.filter(r => r.status === 'completed');
  
  if (!filtered.length) {
    container.innerHTML = '<div style="text-align:center;padding:60px 0;color:#8A6F5A;">لا توجد طلبات</div>';
    return;
  }
  
  const statusMap = {
    pending: { class: 'pending', text: 'قيد الانتظار', badgeClass: 'pending-badge' },
    accepted: { class: 'accepted', text: 'مقبولة', badgeClass: 'accepted-badge' },
    rejected: { class: 'rejected', text: 'مرفوضة', badgeClass: 'rejected-badge' },
    completed: { class: 'completed', text: 'منتهية', badgeClass: 'completed-badge' }
  };
  
  const harvestMap = { sell: '💰 بيع', receive: '📦 توصيل', donate: '🤲 تبرع' };
  
  container.innerHTML = filtered.map(req => {
    const s = statusMap[req.status];
    
    if (req.status === 'completed') {
      const r = ratings[req.id] || { farm: req.farmRating || 0, farmer: req.farmerRating || 0 };
      const isRated = r.farm > 0 && r.farmer > 0;
      const profit = req.profit ? `<span style="font-size:13px;color:#2d5f33;font-weight:700;">📈 الربح: ${req.profit.toLocaleString()} ر.س</span>` : '';
      
      const starsDisplay = (n) => {
        let s = '';
        for(let i=1;i<=5;i++) s += `<span style="color:${i<=n?'#C8922A':'#d9c8b0'};font-size:15px;">★</span>`;
        return s;
      };

      return `
        <div class="request-card completed" id="rcard-${req.id}">
          <div class="request-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <span class="request-name" style="font-weight:700;font-size:16px;">🌴 ${req.farmName}</span>
            <span class="status-badge completed-badge" style="padding:4px 12px;border-radius:30px;font-size:12px;">منتهية</span>
          </div>
          <div class="request-details" style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:10px;">
            <span style="font-size:13px;">📐 ${req.area}</span>
            <span style="font-size:13px;">⏱ ${req.duration}</span>
            <span style="font-size:13px;">💳 ${req.amount.toLocaleString()} ر.س</span>
            ${profit}
            <span style="font-size:13px;">${harvestMap[req.harvest] || ''}</span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <span style="font-size:11px;color:#8A6F5A;">انتهى: ${req.completedAt || '—'}</span>
          </div>
          ${isRated ? `
            <div style="background:#f7f4ef;border-radius:8px;padding:10px 14px;font-size:13px;">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <span style="color:#6E5242;">تقييم المزرعة</span>
                <span>${starsDisplay(r.farm)}</span>
              </div>
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="color:#6E5242;">تقييم المزارع</span>
                <span>${starsDisplay(r.farmer)}</span>
              </div>
            </div>
          ` : `
            <button class="btn-rate" onclick="openRatingPopup(${req.id})">
              ⭐ قيّم تجربتك
            </button>
          `}
        </div>
      `;
    }

    return `
      <div class="request-card ${s.class}">
        <div class="request-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
          <span class="request-name" style="font-weight:700;font-size:16px;">${req.farmName}</span>
          <span class="status-badge ${s.badgeClass}" style="padding:4px 12px;border-radius:30px;font-size:12px;">${s.text}</span>
        </div>
        <div class="request-details" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;">
          <span style="font-size:13px;">📐 ${req.area}</span>
          <span style="font-size:13px;">⏱ ${req.duration}</span>
          <span style="font-size:13px;">💰 ${req.amount.toLocaleString()} ر.س</span>
          <span style="font-size:13px;">${harvestMap[req.harvest] || ''}</span>
        </div>
        <div class="request-footer" style="display:flex;justify-content:space-between;align-items:center;">
          <span style="font-size:11px;color:#8A6F5A;">تاريخ التقديم: ${req.submittedAt}</span>
        </div>
      </div>
    `;
  }).join('');
}

function filterRequests(status, btn) {
  document.querySelectorAll('#page-requests .filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderRequests(status);
}

// ============================================================
// دوال عامة
// ============================================================
function showPage(pageId) {
  document.querySelectorAll('.page').forEach(page => page.classList.remove('active'));
  document.getElementById(`page-${pageId}`).classList.add('active');
  
  document.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));
  const activeLink = Array.from(document.querySelectorAll('.nav-link')).find(
    link => link.getAttribute('onclick')?.includes(`'${pageId}'`)
  );
  if (activeLink) activeLink.classList.add('active');
  
  if (pageId === 'cart') renderCart();
  if (pageId === 'requests') renderRequests('all');
}

function showToast(msg) {
  const toast = document.getElementById('toast');
  toast.textContent = msg;
  toast.style.display = 'block';
  setTimeout(() => toast.style.display = 'none', 2500);
}

function filterFarms() {
  const searchTerm = document.getElementById('farmSearch').value.toLowerCase();
  const region = document.getElementById('regionFilter').value;
  const cards = document.querySelectorAll('#farms-list .farm-card');
  cards.forEach(card => {
    const name = card.getAttribute('data-name')?.toLowerCase() || '';
    const cardRegion = card.getAttribute('data-region') || '';
    card.style.display = (name.includes(searchTerm) && (region === 'all' || cardRegion === region)) ? 'block' : 'none';
  });
}

function toggleWishlist(btn, farmName, region, price) {
  const exists = wishlist.find(f => f.name === farmName);
  if (exists) {
    wishlist = wishlist.filter(f => f.name !== farmName);
    btn.style.color = '#8A6F5A';
    showToast(`تم إزالة ${farmName} من المفضلة`);
  } else {
    wishlist.push({ name: farmName, region, price });
    btn.style.color = '#C8922A';
    showToast(`تم إضافة ${farmName} إلى المفضلة`);
  }
  renderWishlist();
}

function renderWishlist() {
  const container = document.getElementById('wishlist-container');
  if (!container) return;
  if (!wishlist.length) {
    container.innerHTML = '<div style="text-align:center;padding:60px 0;color:#8A6F5A;">قائمة الرغبات فارغة</div>';
    return;
  }
  container.innerHTML = wishlist.map(farm => `
    <div class="farm-card">
      <div class="farm-card-inner">
        <div class="palm-icon"><svg width="28" height="32" viewBox="0 0 40 50" fill="#4f7f49"><use href="#palm"/></svg></div>
        <div class="farm-details">
          <div class="farm-name">${farm.name}</div>
          <div class="farm-meta">${farm.region}</div>
          <div class="farm-price">${farm.price} ر.س/م²</div>
        </div>
        <div class="farm-actions">
          <button class="btn-explore" onclick="openOffersPage('${farm.name}','${farm.region}','')">استكشف العروض</button>
        </div>
      </div>
    </div>
  `).join('');
}

// Leaflet Map
let leafletMap = null, mapInit = false;
const MAP_FARMS = [
  { id: 0, name: 'مزرعة العقيلي', region: 'القصيم', type: 'سكري', stars: 5, lat: 26.32, lng: 43.97 },
  { id: 1, name: 'واحة العجوة المباركة', region: 'المدينة المنورة', type: 'عجوة', stars: 4, lat: 24.47, lng: 39.61 },
  { id: 2, name: 'مزارع الخلاص الأصيلة', region: 'القصيم', type: 'خلاص', stars: 3, lat: 26.10, lng: 43.50 },
  { id: 3, name: 'نخيل البرحي الفاخر', region: 'الأحساء', type: 'برحي', stars: 3, lat: 25.38, lng: 49.58 },
];

function switchView(view) {
  const lv = document.getElementById('view-list'), mv = document.getElementById('view-map');
  const bl = document.getElementById('btn-list'), bm = document.getElementById('btn-map');
  if (view === 'map') {
    lv.style.display = 'none'; mv.style.display = 'block';
    bl.classList.remove('active'); bm.classList.add('active');
    if (!mapInit) setTimeout(initLeaflet, 80);
    else leafletMap.invalidateSize();
  } else {
    lv.style.display = 'block'; mv.style.display = 'none';
    bl.classList.add('active'); bm.classList.remove('active');
  }
}

function initLeaflet() {
  if (mapInit) return;
  leafletMap = L.map('leaflet-map', { center: [24.5, 44.5], zoom: 6 });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 18 }).addTo(leafletMap);
  MAP_FARMS.forEach(f => {
    const icon = L.divIcon({
      className: '',
      html: `<svg width="28" height="38" viewBox="0 0 28 38"><path d="M14 0C6.3 0 0 6.3 0 14C0 24.5 14 38 14 38C14 38 28 24.5 28 14C28 6.3 21.7 0 14 0Z" fill="#2d5f33" stroke="#F2D998" stroke-width="1.8"/><circle cx="14" cy="14" r="7" fill="white" opacity="0.92"/><line x1="14" y1="18" x2="14" y2="10" stroke="#2d5f33" stroke-width="1.5"/><path d="M14 12 Q11 10 9 9" stroke="#2d5f33" stroke-width="1.2"/><path d="M14 12 Q17 10 19 9" stroke="#2d5f33" stroke-width="1.2"/></svg>`,
      iconSize: [28, 38], iconAnchor: [14, 38], popupAnchor: [0, -40]
    });
    L.marker([f.lat, f.lng], { icon }).addTo(leafletMap).bindPopup(
      `<div style="direction:rtl;text-align:right;">
        <div style="font-weight:700;color:#2d5f33;">${f.name}</div>
        <div style="font-size:12px;">${f.region} · ${f.type}</div>
        <button onclick="openOffersPage('${f.name}','${f.region}','${f.type}')" style="background:#2d5f33;color:#fff;border:none;border-radius:6px;padding:6px 12px;margin-top:8px;cursor:pointer;">استكشف</button>
      </div>`
    );
  });
  mapInit = true;
}

// ============================================================
// دوال التقييم
// ============================================================
let currentRatingId = null;
let pendingRating = { farm: 0, farmer: 0 };
const ratingLabels = { 1: 'سيء', 2: 'مقبول', 3: 'جيد', 4: 'جيد جداً', 5: 'ممتاز' };

function openRatingPopup(reqId) {
  const req = investmentRequests.find(r => r.id === reqId);
  if (!req) return;
  currentRatingId = reqId;
  pendingRating = { farm: 0, farmer: 0 };

  document.getElementById('rating-farm-label').textContent = req.farmName + ' · ' + req.area + ' · ' + req.duration;
  document.getElementById('rating-comment').value = '';
  document.getElementById('rating-farm-text').textContent = '';
  document.getElementById('rating-farmer-text').textContent = '';

  document.getElementById('rating-summary').innerHTML = `
    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
      <span style="font-size:13px;">💳 المبلغ: <strong>${req.amount.toLocaleString()} ر.س</strong></span>
      ${req.profit ? `<span style="font-size:13px;color:#2d5f33;">📈 الربح: <strong>${req.profit.toLocaleString()} ر.س</strong></span>` : ''}
      <span style="font-size:13px;">🗓 انتهى: <strong>${req.completedAt || '—'}</strong></span>
    </div>
  `;

  updateStars('farm', 0);
  updateStars('farmer', 0);
  document.getElementById('popup-rating').style.display = 'flex';
}

function closeRatingPopup() {
  document.getElementById('popup-rating').style.display = 'none';
  currentRatingId = null;
}

function setRating(type, val) {
  pendingRating[type] = val;
  updateStars(type, val);
  const textEl = document.getElementById(`rating-${type}-text`);
  textEl.textContent = '★'.repeat(val) + ' ' + (ratingLabels[val] || '');
}

function updateStars(type, val) {
  const container = document.getElementById(`stars-${type}`);
  if (!container) return;
  // النجوم مرتبة من 5 إلى 1 في الـ HTML (RTL)
  container.querySelectorAll('.rstar').forEach(star => {
    const sv = parseInt(star.dataset.val);
    star.style.color = sv <= val ? '#C8922A' : '#d9c8b0';
    star.style.transform = sv <= val ? 'scale(1.05)' : 'scale(1)';
  });
}

function submitRating() {
  if (!pendingRating.farm || !pendingRating.farmer) {
    showToast('قيّم المزرعة والمزارع أولاً');
    return;
  }
  ratings[currentRatingId] = { farm: pendingRating.farm, farmer: pendingRating.farmer };
  // حفظ في بيانات الطلب
  const req = investmentRequests.find(r => r.id === currentRatingId);
  if (req) { req.farmRating = pendingRating.farm; req.farmerRating = pendingRating.farmer; }

  document.getElementById('rating-success-stars').innerHTML =
    `المزرعة: ${'★'.repeat(pendingRating.farm)}${'☆'.repeat(5-pendingRating.farm)} &nbsp;|&nbsp; المزارع: ${'★'.repeat(pendingRating.farmer)}${'☆'.repeat(5-pendingRating.farmer)}`;

  document.getElementById('popup-rating').style.display = 'none';
  document.getElementById('popup-rating-success').style.display = 'flex';
}

function closeRatingSuccess() {
  document.getElementById('popup-rating-success').style.display = 'none';
  renderRequests('completed');
  // إعادة تفعيل تاب المنتهية
  document.querySelectorAll('#page-requests .filter-btn').forEach(b => b.classList.remove('active'));
  const completedBtn = Array.from(document.querySelectorAll('#page-requests .filter-btn')).find(b => b.textContent.includes('منتهية'));
  if (completedBtn) completedBtn.classList.add('active');
}


// ============================================================
// بيانات حقيقية من PHP (تحل محل البيانات الثابتة عند الحاجة)
// ============================================================
const PHP_INVESTOR_NAME = <?= json_encode($first_name . ' ' . $last_name, JSON_UNESCAPED_UNICODE) ?>;
const PHP_REQUESTS = <?= json_encode($myRequests, JSON_UNESCAPED_UNICODE) ?>;
const PHP_WISHLIST_IDS = <?= json_encode($wishlistIds) ?>;
const PHP_CART = <?= json_encode($myCart, JSON_UNESCAPED_UNICODE) ?>;
const PHP_FARMS = <?= json_encode($farms, JSON_UNESCAPED_UNICODE) ?>;
const PHP_UPDATES = <?= json_encode($farmUpdates, JSON_UNESCAPED_UNICODE) ?>;

// تهيئة الصفحة
document.addEventListener('DOMContentLoaded', () => {
  renderWishlist();
  renderCart();
  renderRequests('all');
});
</script>

</body>
</html>