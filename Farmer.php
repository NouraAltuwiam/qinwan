<?php
// ============================================================
// قِنوان — Farmer.php  (Updated: US-03 to US-11)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header('Location: login.php'); exit;
}

$pdo        = getDB();
$farmer_id  = (int)$_SESSION['farmer_id'];
$first_name = $_SESSION['first_name'];
$last_name  = $_SESSION['last_name'] ?? '';

// ── معالجة POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    // US-07: Accept request
    if ($act === 'accept') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $chk = $pdo->prepare("
            SELECT ir.request_id, ir.area_sqm, fo.price
            FROM qw_investment_request ir
            JOIN qw_farm_offer fo ON ir.offer_id = fo.offer_id
            JOIN qw_farm f ON fo.farm_id = f.farm_id
            WHERE ir.request_id = ? AND f.farmer_id = ? AND ir.req_status = 'pending'
        ");
        $chk->execute([$request_id, $farmer_id]);
        $row = $chk->fetch();
        if ($row) {
            $pdo->prepare("UPDATE qw_investment_request SET req_status='accepted' WHERE request_id=?")->execute([$request_id]);
            $amount = round($row['area_sqm'] * $row['price'], 2);
            $pdo->prepare("INSERT INTO qw_transaction (request_id, amount, payment_status) VALUES (?,?,'pending')")->execute([$request_id, $amount]);
            try { $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,'accept_request','investment_request',?)")->execute([$_SESSION['user_id'],$request_id]); } catch(Exception $e){}
        }
        header('Location: Farmer.php'); exit;
    }

    // US-08: Reject request
    if ($act === 'reject') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'لم يتم تحديد سبب');
        $chk = $pdo->prepare("
            SELECT ir.request_id FROM qw_investment_request ir
            JOIN qw_farm_offer fo ON ir.offer_id = fo.offer_id
            JOIN qw_farm f ON fo.farm_id = f.farm_id
            WHERE ir.request_id = ? AND f.farmer_id = ? AND ir.req_status = 'pending'
        ");
        $chk->execute([$request_id, $farmer_id]);
        if ($chk->fetch()) {
            $pdo->prepare("UPDATE qw_investment_request SET req_status='rejected', rejection_reason=? WHERE request_id=?")->execute([$reason, $request_id]);
            try { $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,'reject_request','investment_request',?)")->execute([$_SESSION['user_id'],$request_id]); } catch(Exception $e){}
        }
        header('Location: Farmer.php'); exit;
    }

    // US-04: Add farm
    if ($act === 'add_farm') {
        $name      = trim($_POST['name']        ?? '');
        $region    = trim($_POST['region']      ?? '');
        $palm_type = trim($_POST['palm_type']   ?? '');
        $date_type = trim($_POST['date_type']   ?? '');
        $area      = (float)($_POST['area']     ?? 0);
        $desc      = trim($_POST['description'] ?? '');

        if ($name && $region && $palm_type && $date_type && $area > 0) {
            // Check for duplicate (US-04: prevent duplicate name+region)
            $dup = $pdo->prepare("SELECT farm_id FROM qw_farm WHERE farmer_id=? AND name=? AND region=?");
            $dup->execute([$farmer_id, $name, $region]);
            if (!$dup->fetch()) {
                $pdo->prepare("INSERT INTO qw_farm (farmer_id, name, region, total_area_sqm, palm_type, date_type, description) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$farmer_id, $name, $region, $area, $palm_type, $date_type, $desc]);
                $new_farm_id = $pdo->lastInsertId();
                try { $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,'add_farm','farm',?)")->execute([$_SESSION['user_id'],$new_farm_id]); } catch(Exception $e){}
                $_SESSION['flash'] = 'تم تقديم طلب تسجيل المزرعة. ستتم مراجعتها من قبل الإدارة.';
            } else {
                $_SESSION['flash_error'] = 'مزرعة بنفس الاسم والمنطقة مسجلة مسبقاً.';
            }
        } else {
            $_SESSION['flash_error'] = 'يرجى تعبئة جميع الحقول المطلوبة.';
        }
        header('Location: Farmer.php'); exit;
    }

    // US-05: Edit farm
    if ($act === 'edit_farm') {
        $farm_id   = (int)($_POST['farm_id']      ?? 0);
        $name      = trim($_POST['name']           ?? '');
        $palm_type = trim($_POST['palm_type']      ?? '');
        $date_type = trim($_POST['date_type']      ?? '');
        $desc      = trim($_POST['description']    ?? '');
        // Verify ownership
        $chk = $pdo->prepare("SELECT farm_id FROM qw_farm WHERE farm_id=? AND farmer_id=?");
        $chk->execute([$farm_id, $farmer_id]);
        if ($chk->fetch()) {
            $pdo->prepare("UPDATE qw_farm SET name=?, palm_type=?, date_type=?, description=? WHERE farm_id=?")
                ->execute([$name, $palm_type, $date_type, $desc, $farm_id]);
            try { $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,'edit_farm','farm',?)")->execute([$_SESSION['user_id'],$farm_id]); } catch(Exception $e){}
            $_SESSION['flash'] = 'تم تحديث بيانات المزرعة بنجاح.';
        }
        header('Location: Farmer.php'); exit;
    }

    // US-09: Post update
    if ($act === 'post_update') {
        $farm_id = (int)($_POST['farm_id'] ?? 0);
        $content = trim($_POST['content']  ?? '');
        if ($farm_id && $content) {
            $chk = $pdo->prepare("
                SELECT f.farm_id FROM qw_farm f
                JOIN qw_investment_request ir ON ir.offer_id IN (SELECT offer_id FROM qw_farm_offer WHERE farm_id=f.farm_id)
                WHERE f.farm_id=? AND f.farmer_id=? AND ir.req_status='accepted'
                LIMIT 1
            ");
            $chk->execute([$farm_id, $farmer_id]);
            if ($chk->fetch()) {
                $pdo->prepare("INSERT INTO qw_farm_update (farm_id, content) VALUES (?,?)")->execute([$farm_id, $content]);
                try { $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,'post_update','farm',?)")->execute([$_SESSION['user_id'],$farm_id]); } catch(Exception $e){}
                $_SESSION['flash'] = 'تم نشر التحديث بنجاح.';
            } else {
                $_SESSION['flash_error'] = 'لا يمكن النشر إلا على مزارع بها مستثمرون نشطون.';
            }
        }
        header('Location: Farmer.php'); exit;
    }
}

// ── جلب البيانات ─────────────────────────────────────────
$farmsStmt = $pdo->prepare("SELECT * FROM qw_farm WHERE farmer_id = ? ORDER BY created_at DESC");
$farmsStmt->execute([$farmer_id]);
$myFarms = $farmsStmt->fetchAll();

// US-10: Dashboard stats
$statsStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(f.total_area_sqm),0)     AS total_area,
        COUNT(DISTINCT ir.investor_id)          AS active_investors,
        COALESCE(SUM(ir.area_sqm),0)           AS leased_area
    FROM qw_farm f
    LEFT JOIN qw_farm_offer fo ON fo.farm_id = f.farm_id
    LEFT JOIN qw_investment_request ir ON ir.offer_id = fo.offer_id AND ir.req_status = 'accepted'
    WHERE f.farmer_id = ?
");
$statsStmt->execute([$farmer_id]);
$stats = $statsStmt->fetch();
$remaining_area = max(0, $stats['total_area'] - $stats['leased_area']);
$estimated_revenue = round($stats['leased_area'] * 150, 2); // avg 150 SAR/m²

// US-06: Investment requests
$reqStmt = $pdo->prepare("
    SELECT ir.request_id, ir.area_sqm, ir.duration, ir.harvest_method,
           ir.req_status, ir.submitted_at, ir.rejection_reason,
           f.name AS farm_name, f.farm_id,
           u.first_name AS inv_first, u.last_name AS inv_last,
           u.phone AS inv_phone,
           (ir.area_sqm * fo.price) AS amount,
           CASE WHEN fr2.verification_status='verified' THEN 1 ELSE 0 END AS inv_verified
    FROM qw_investment_request ir
    JOIN qw_farm_offer fo ON ir.offer_id   = fo.offer_id
    JOIN qw_farm f        ON fo.farm_id    = f.farm_id
    JOIN qw_investor inv  ON ir.investor_id = inv.investor_id
    JOIN qw_user u        ON inv.user_id   = u.user_id
    LEFT JOIN qw_farmer fr2 ON fr2.user_id = u.user_id
    WHERE f.farmer_id = ?
    ORDER BY ir.submitted_at DESC
");
$reqStmt->execute([$farmer_id]);
$incomingRequests = $reqStmt->fetchAll();

// Updates (US-09)
$updStmt = $pdo->prepare("
    SELECT fu.*, f.name AS farm_name
    FROM qw_farm_update fu
    JOIN qw_farm f ON fu.farm_id = f.farm_id
    WHERE f.farmer_id = ?
    ORDER BY fu.created_at DESC
");
$updStmt->execute([$farmer_id]);
$myUpdates = $updStmt->fetchAll();

// Farms with active investors (for posting updates)
$activeFarmsStmt = $pdo->prepare("
    SELECT DISTINCT f.farm_id, f.name FROM qw_farm f
    JOIN qw_farm_offer fo ON fo.farm_id = f.farm_id
    JOIN qw_investment_request ir ON ir.offer_id = fo.offer_id AND ir.req_status = 'accepted'
    WHERE f.farmer_id = ?
");
$activeFarmsStmt->execute([$farmer_id]);
$activeFarms = $activeFarmsStmt->fetchAll();

$flash      = $_SESSION['flash']       ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>قِنوان | لوحة المزارع</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>

<!-- ============================================================
     صفحة 1 — لوحة التحكم
     ============================================================ -->
<div class="page active" id="page-dashboard">
  <nav>
    <button class="nav-back" onclick="window.location.href='index.php'">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link active" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link"        onclick="showPage('add-farm')">إضافة مزرعة</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('send-update')">نشر تحديث</button>
      <a href="logout.php" class="nav-link nav-logout">تسجيل الخروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المزارع</span></div>
    </div>
  </nav>

  <div class="page-content">

    <?php if ($flash): ?>
      <div class="alert-success">✅ <?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
      <div class="alert-error">⚠️ <?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <div class="page-title-wrap">
      <h1 class="page-title">لوحة تحكم المزارع — مرحباً <?= htmlspecialchars($first_name . ' ' . $last_name) ?></h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-dot"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:24px"></div>
      </div>
    </div>

    <!-- US-10: Stats from real DB -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">المساحة الكلية</div>
        <div class="stat-value"><?= number_format($stats['total_area'], 0) ?> م²</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">المساحة المؤجرة</div>
        <div class="stat-value"><?= number_format($stats['leased_area'], 0) ?> م²</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">المساحة المتبقية</div>
        <div class="stat-value"><?= number_format($remaining_area, 0) ?> م²</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">المستثمرون النشطون</div>
        <div class="stat-value"><?= $stats['active_investors'] ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">الإيرادات المتوقعة</div>
        <div class="stat-value"><?= number_format($estimated_revenue, 0) ?> ر.س</div>
      </div>
    </div>

    <div class="section-title">مزارعي (<?= count($myFarms) ?>)</div>

    <?php if (empty($myFarms)): ?>
      <div class="empty-state-msg">لا توجد مزارع مسجلة. <button class="btn-link" onclick="showPage('add-farm')">أضف مزرعة الآن</button></div>
    <?php else: ?>
      <?php foreach ($myFarms as $farm): ?>
        <div class="farm-card">
          <div class="palm-icon">🌴</div>
          <div class="farm-info" style="flex:1; padding-right: 16px;">
            <div class="farm-name"><?= htmlspecialchars($farm['name']) ?></div>
            <div class="farm-meta"><?= htmlspecialchars($farm['region']) ?> · <?= htmlspecialchars($farm['palm_type']) ?></div>
            <div style="margin-top:6px;">
              <span class="status-badge status-<?= $farm['farm_status'] ?>"><?php
                $statusMap = ['pending'=>'قيد المراجعة','approved'=>'معتمدة','rejected'=>'مرفوضة','deactivated'=>'معطلة'];
                echo $statusMap[$farm['farm_status']] ?? $farm['farm_status'];
              ?></span>
            </div>
          </div>
          <div class="farm-actions">
            <button class="btn-explore" onclick="openEditFarm(<?= $farm['farm_id'] ?>, '<?= htmlspecialchars(addslashes($farm['name'])) ?>', '<?= htmlspecialchars(addslashes($farm['palm_type'])) ?>', '<?= htmlspecialchars(addslashes($farm['date_type'])) ?>', '<?= htmlspecialchars(addslashes($farm['description'] ?? '')) ?>')">تعديل</button>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </div>
</div>

<!-- ============================================================
     صفحة تعديل المزرعة (US-05)
     ============================================================ -->
<div class="page" id="page-edit-farm">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">رجوع</button>
    <div class="nav-links">
      <a href="logout.php" class="nav-link nav-logout">تسجيل الخروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المزارع</span></div>
    </div>
  </nav>
  <div class="page-content">
    <h1 class="page-title">تعديل المزرعة</h1>
    <div class="form-card">
      <form method="POST" action="Farmer.php" id="editFarmForm">
        <input type="hidden" name="act" value="edit_farm" />
        <input type="hidden" name="farm_id" id="edit_farm_id" />

        <div class="form-group">
          <label class="form-label">اسم المزرعة</label>
          <input type="text" name="name" id="edit_farm_name" class="form-input" required placeholder="اسم المزرعة" />
        </div>
        <div class="form-group">
          <label class="form-label">نوع النخيل</label>
          <input type="text" name="palm_type" id="edit_palm_type" class="form-input" placeholder="نوع النخيل" />
        </div>
        <div class="form-group">
          <label class="form-label">نوع التمر</label>
          <input type="text" name="date_type" id="edit_date_type" class="form-input" placeholder="نوع التمر" />
        </div>
        <div class="form-group">
          <label class="form-label">الوصف</label>
          <textarea name="description" id="edit_description" class="form-textarea" placeholder="وصف المزرعة"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">صورة المزرعة (اختياري)</label>
          <input type="file" accept="image/*" class="form-input" />
        </div>
        <button type="submit" class="btn-primary">💾 حفظ التغييرات</button>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================
     صفحة 2 — نشر تحديث (US-09)
     ============================================================ -->
<div class="page" id="page-send-update">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link"        onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link"        onclick="showPage('add-farm')">إضافة مزرعة</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link active" onclick="showPage('send-update')">نشر تحديث</button>
      <a href="logout.php" class="nav-link nav-logout">تسجيل الخروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المزارع</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">نشر تحديث</h1>
      <div class="title-ornament"><div class="orn-line" style="width:40px"></div><div class="orn-diamond"></div><div class="orn-line" style="width:16px"></div></div>
    </div>

    <div class="form-card">
      <form method="POST" action="Farmer.php">
        <input type="hidden" name="act" value="post_update" />

        <div class="form-group">
          <label class="form-label">اختر المزرعة</label>
          <?php if (empty($activeFarms)): ?>
            <div class="auth-error">لا توجد مزارع بها مستثمرون نشطون حالياً. يجب أن يكون لديك طلب مقبول قبل النشر.</div>
          <?php else: ?>
            <select name="farm_id" class="form-select" required>
              <option value="" disabled selected>اختر المزرعة</option>
              <?php foreach ($activeFarms as $af): ?>
                <option value="<?= $af['farm_id'] ?>"><?= htmlspecialchars($af['name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">نص التحديث</label>
          <textarea name="content" class="form-textarea" placeholder="اكتب تحديثاً عن المزرعة..." required></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">صور أو فيديو (اختياري)</label>
          <div class="upload-zone" onclick="document.getElementById('file-input').click()">
            <div class="upload-text">اضغط لرفع الملفات</div>
            <div class="upload-hint">JPG · PNG · MP4 — حتى 50MB</div>
          </div>
          <input type="file" id="file-input" multiple accept="image/*,video/*" style="display:none" onchange="previewFiles(this)" />
          <div id="file-preview" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;"></div>
        </div>

        <button type="submit" class="btn-primary" <?= empty($activeFarms) ? 'disabled' : '' ?>>📤 نشر التحديث</button>
      </form>

      <?php if (!empty($myUpdates)): ?>
        <hr style="margin: 24px 0; border-color: var(--border-light);" />
        <div class="section-title">آخر التحديثات المنشورة</div>
        <?php foreach (array_slice($myUpdates, 0, 5) as $upd): ?>
          <div class="update-item">
            <div class="update-farm">🌴 <?= htmlspecialchars($upd['farm_name']) ?></div>
            <p class="update-text"><?= htmlspecialchars($upd['content']) ?></p>
            <div class="update-date"><?= date('Y-m-d H:i', strtotime($upd['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============================================================
     صفحة 3 — إضافة مزرعة (US-04)
     ============================================================ -->
<div class="page" id="page-add-farm">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link"        onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link active" onclick="showPage('add-farm')">إضافة مزرعة</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('send-update')">نشر تحديث</button>
      <a href="logout.php" class="nav-link nav-logout">تسجيل الخروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المزارع</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">إضافة مزرعة جديدة</h1>
      <div class="title-ornament"><div class="orn-line" style="width:60px"></div><div class="orn-diamond"></div><div class="orn-line" style="width:24px"></div></div>
    </div>

    <div class="form-card" style="max-width:680px;">
      <form method="POST" action="Farmer.php" id="addFarmForm" novalidate>
        <input type="hidden" name="act" value="add_farm" />

        <div class="form-group">
          <label class="form-label">اسم المزرعة <span style="color:red">*</span></label>
          <input type="text" name="name" class="form-input" placeholder="أدخل اسم المزرعة" required />
        </div>

        <div class="form-group">
          <label class="form-label">المنطقة <span style="color:red">*</span></label>
          <select name="region" class="form-select" required>
            <option value="" disabled selected>اختر المنطقة</option>
            <option>الرياض</option><option>القصيم</option><option>المدينة المنورة</option>
            <option>الأحساء</option><option>تبوك</option><option>حائل</option>
            <option>الجوف</option><option>نجران</option><option>الباحة</option>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">المساحة (م²) <span style="color:red">*</span></label>
            <input type="number" name="area" class="form-input" placeholder="0" min="1" required />
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">الإنتاج الموسمي (كجم)</label>
            <input type="number" name="yield_kg" class="form-input" placeholder="0" min="0" />
          </div>
        </div>
        <div style="margin-bottom:22px"></div>

        <div class="form-group">
          <label class="form-label">نوع النخيل <span style="color:red">*</span></label>
          <select name="palm_type" class="form-select" required>
            <option value="" disabled selected>اختر النوع</option>
            <option>سكري</option><option>مجهول</option><option>خلاص</option>
            <option>برحي</option><option>صفري</option><option>رزيز</option><option>سفري</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">نوع التمر <span style="color:red">*</span></label>
          <select name="date_type" class="form-select" required>
            <option value="" disabled selected>اختر نوع التمر</option>
            <option>سكري</option><option>عجوة</option><option>خلاص</option>
            <option>برحي</option><option>مجدول</option><option>سفري</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">الوصف</label>
          <textarea name="description" class="form-textarea" placeholder="وصف المزرعة وميزاتها..."></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">صور المزرعة (اختياري)</label>
          <div class="upload-zone" onclick="document.getElementById('farm-images').click()">
            <div class="upload-text">ارفع صور المزرعة</div>
            <div class="upload-hint">JPG · PNG</div>
          </div>
          <input type="file" id="farm-images" multiple accept="image/*" style="display:none" />
        </div>

        <button type="submit" class="btn-primary">🌴 إضافة المزرعة للمراجعة</button>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================
     صفحة 4 — طلبات الاستثمار (US-06, US-07, US-08, US-11)
     ============================================================ -->
<div class="page" id="page-requests">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link"        onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link"        onclick="showPage('add-farm')">إضافة مزرعة</button>
      <button class="nav-link active" onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('send-update')">نشر تحديث</button>
      <a href="logout.php" class="nav-link nav-logout">تسجيل الخروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المزارع</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">طلبات الاستثمار</h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div><div class="orn-diamond"></div>
        <div class="orn-dot"></div><div class="orn-diamond"></div><div class="orn-line" style="width:24px"></div>
      </div>
    </div>

    <div class="filter-bar">
      <button class="filter-btn active" onclick="filterRequests('all',this)">الكل (<?= count($incomingRequests) ?>)</button>
      <button class="filter-btn" onclick="filterRequests('pending',this)">قيد الانتظار</button>
      <button class="filter-btn" onclick="filterRequests('accepted',this)">مقبولة</button>
      <button class="filter-btn" onclick="filterRequests('rejected',this)">مرفوضة</button>
      <button class="filter-btn filter-btn-completed" onclick="filterRequests('expired',this)">منتهية الصلاحية</button>
    </div>

    <div id="requests-list">
      <?php
        $harvestMap = ['receive'=>'استلام التمور في المنزل','sell'=>'بيع المحصول واستلام الأرباح','donate'=>'التبرع للجمعيات الخيرية'];
        $statusLabelMap = ['pending'=>'قيد الانتظار','accepted'=>'مقبول','rejected'=>'مرفوض','expired'=>'منتهية الصلاحية','cancelled'=>'ملغى'];

        if (empty($incomingRequests)): ?>
          <div class="empty-state-msg">لا توجد طلبات استثمار حتى الآن.</div>
        <?php else: ?>
          <?php foreach ($incomingRequests as $req): ?>
            <div class="request-card status-<?= $req['req_status'] ?>" data-status="<?= $req['req_status'] ?>">
              <div class="request-header">
                <span class="status-badge"><?= $statusLabelMap[$req['req_status']] ?? $req['req_status'] ?></span>
                <span class="request-name">
                  <?= htmlspecialchars($req['inv_first'] . ' ' . $req['inv_last']) ?>
                  <?php if ($req['inv_verified']): ?><span style="color:var(--green-mid);font-size:12px;"> ✓ موثق</span><?php endif; ?>
                </span>
              </div>
              <div class="request-details">
                <span class="request-detail"><?= htmlspecialchars($req['farm_name']) ?></span>
                <span class="request-detail"><?= number_format($req['area_sqm'], 0) ?> م²</span>
                <span class="request-detail"><?= htmlspecialchars($req['duration']) ?></span>
                <span class="request-detail"><?= $harvestMap[$req['harvest_method']] ?? $req['harvest_method'] ?></span>
                <span class="request-detail"><?= number_format($req['amount'], 0) ?> ر.س</span>
              </div>
              <div style="font-size:12px;color:var(--text-faint);margin-bottom:10px;">
                📅 <?= date('Y-m-d', strtotime($req['submitted_at'])) ?>
                <?php if ($req['inv_phone']): ?> | 📞 <?= htmlspecialchars($req['inv_phone']) ?><?php endif; ?>
              </div>
              <?php if ($req['req_status'] === 'pending'): ?>
                <div class="request-actions">
                  <form method="POST" action="Farmer.php" style="display:inline;">
                    <input type="hidden" name="act" value="accept">
                    <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                    <button type="submit" class="btn-accept">✅ قبول</button>
                  </form>
                  <button class="btn-reject" onclick="showRejectModal(<?= $req['request_id'] ?>)">❌ رفض</button>
                </div>
              <?php elseif ($req['req_status'] === 'rejected' && $req['rejection_reason']): ?>
                <div style="font-size:12px;color:var(--red);background:var(--red-light);padding:8px 12px;border-radius:6px;margin-top:8px;">
                  سبب الرفض: <?= htmlspecialchars($req['rejection_reason']) ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
    </div>
  </div>
</div>

<!-- Reject Modal (US-08) -->
<div id="reject-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:700;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:440px;margin:20px;padding:32px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 style="font-family:'Amiri',serif;font-size:20px;color:var(--brown-dark);margin-bottom:16px;">❌ رفض الطلب</h3>
    <form method="POST" action="Farmer.php">
      <input type="hidden" name="act" value="reject">
      <input type="hidden" name="request_id" id="reject_request_id">
      <label style="display:block;font-size:13px;font-weight:600;color:var(--brown-dark);margin-bottom:8px;">سبب الرفض (اختياري)</label>
      <textarea name="reason" class="form-textarea" placeholder="اكتب سبب الرفض للمستثمر..." style="width:100%;min-height:100px;"></textarea>
      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="button" onclick="document.getElementById('reject-modal').style.display='none'" style="flex:1;background:transparent;color:var(--text-muted);border:1.5px solid var(--border);border-radius:8px;padding:12px;cursor:pointer;font-family:'Noto Naskh Arabic',serif;">إلغاء</button>
        <button type="submit" style="flex:2;background:var(--red);color:#fff;border:none;border-radius:8px;padding:12px;cursor:pointer;font-family:'Noto Naskh Arabic',serif;font-weight:700;">تأكيد الرفض</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<style>
.alert-success { background: #e2f1e1; border: 1px solid rgba(45,95,51,0.3); border-right: 4px solid var(--green-mid); color: var(--green-dark); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
.alert-error   { background: var(--red-light); border: 1px solid rgba(139,42,42,0.3); border-right: 4px solid var(--red); color: var(--red); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
.empty-state-msg { text-align: center; padding: 48px 0; color: var(--text-muted); font-size: 15px; }
.btn-link { background: none; border: none; color: var(--green-mid); font-weight: 700; cursor: pointer; font-family: 'Noto Naskh Arabic', serif; font-size: 15px; text-decoration: underline; }
</style>

<script>
// PHP data
const PHP_FARMER_NAME = <?= json_encode($first_name . ' ' . $last_name, JSON_UNESCAPED_UNICODE) ?>;
const PHP_REQUESTS    = <?= json_encode($incomingRequests, JSON_UNESCAPED_UNICODE) ?>;
const PHP_FARMS       = <?= json_encode($myFarms, JSON_UNESCAPED_UNICODE) ?>;
const PHP_STATS       = <?= json_encode($stats, JSON_UNESCAPED_UNICODE) ?>;

function showPage(pageId) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-' + pageId)?.classList.add('active');
  document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
  document.querySelectorAll('.nav-link').forEach(l => {
    if (l.getAttribute('onclick')?.includes("'" + pageId + "'")) l.classList.add('active');
  });
}

function openEditFarm(id, name, palmType, dateType, desc) {
  document.getElementById('edit_farm_id').value   = id;
  document.getElementById('edit_farm_name').value = name;
  document.getElementById('edit_palm_type').value = palmType;
  document.getElementById('edit_date_type').value = dateType;
  document.getElementById('edit_description').value = desc;
  showPage('edit-farm');
}

function filterRequests(status, btn) {
  document.querySelectorAll('#page-requests .filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('#requests-list .request-card').forEach(card => {
    card.style.display = (status === 'all' || card.dataset.status === status) ? 'block' : 'none';
  });
}

function showRejectModal(requestId) {
  document.getElementById('reject_request_id').value = requestId;
  document.getElementById('reject-modal').style.display = 'flex';
}

function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

function previewFiles(input) {
  const preview = document.getElementById('file-preview');
  preview.innerHTML = '';
  Array.from(input.files).forEach(f => {
    if (f.type.startsWith('image/')) {
      const img = document.createElement('img');
      img.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--border-light);';
      img.src = URL.createObjectURL(f);
      preview.appendChild(img);
    } else {
      const div = document.createElement('div');
      div.style.cssText = 'padding:8px 12px;background:var(--bg-section);border-radius:6px;font-size:12px;color:var(--text-muted);';
      div.textContent = '🎬 ' + f.name;
      preview.appendChild(div);
    }
  });
}

// Validate add farm form
document.getElementById('addFarmForm')?.addEventListener('submit', function(e) {
  const name   = this.querySelector('[name=name]').value.trim();
  const region = this.querySelector('[name=region]').value;
  const area   = parseFloat(this.querySelector('[name=area]').value);
  const palm   = this.querySelector('[name=palm_type]').value;
  const date   = this.querySelector('[name=date_type]').value;
  if (!name || !region || !area || area <= 0 || !palm || !date) {
    e.preventDefault();
    showToast('يرجى تعبئة جميع الحقول المطلوبة');
  }
});
</script>
</body>
</html>