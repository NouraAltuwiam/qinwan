<?php
// ============================================================
// قِنوان — Farmer.php
// لوحة المزارع — نفس Farmer.html بالكامل + بيانات PHP
// Tables: qw_farm, qw_farmer, qw_investment_request,
//         qw_farm_offer, qw_farm_update, qw_transaction
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// حماية الصفحة — المزارعون فقط
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header('Location: login.php');
    exit;
}

$pdo        = getDB();
$farmer_id  = (int)$_SESSION['farmer_id'];
$first_name = $_SESSION['first_name'];

// ── معالجة POST (قبول/رفض طلب) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act        = $_POST['act']        ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);

    if ($act === 'accept' && $request_id) {
        // تحقق أن الطلب ينتمي لمزارع هذا المزارع
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
            $pdo->prepare("UPDATE qw_investment_request SET req_status='accepted' WHERE request_id=?")
                ->execute([$request_id]);
            $amount = round($row['area_sqm'] * $row['price'], 2);
            $pdo->prepare("INSERT INTO qw_transaction (request_id, amount, payment_status) VALUES (?,?,'pending')")
                ->execute([$request_id, $amount]);
        }
        header('Location: Farmer.php');
        exit;
    }

    if ($act === 'reject' && $request_id) {
        $reason = trim($_POST['reason'] ?? 'لم يتم تحديد سبب');
        $chk = $pdo->prepare("
            SELECT ir.request_id FROM qw_investment_request ir
            JOIN qw_farm_offer fo ON ir.offer_id = fo.offer_id
            JOIN qw_farm f ON fo.farm_id = f.farm_id
            WHERE ir.request_id = ? AND f.farmer_id = ? AND ir.req_status = 'pending'
        ");
        $chk->execute([$request_id, $farmer_id]);
        if ($chk->fetch()) {
            $pdo->prepare("UPDATE qw_investment_request SET req_status='rejected', rejection_reason=? WHERE request_id=?")
                ->execute([$reason, $request_id]);
        }
        header('Location: Farmer.php');
        exit;
    }

    if ($act === 'add_farm') {
        $name       = trim($_POST['name']        ?? '');
        $region     = trim($_POST['region']      ?? '');
        $palm_type  = trim($_POST['palm_type']   ?? '');
        $date_type  = trim($_POST['date_type']   ?? '');
        $area       = (float)($_POST['area']     ?? 0);
        $desc       = trim($_POST['description'] ?? '');
        if ($name && $region && $palm_type && $date_type && $area > 0) {
            $pdo->prepare("
                INSERT INTO qw_farm (farmer_id, name, region, total_area_sqm, palm_type, date_type, description)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$farmer_id, $name, $region, $area, $palm_type, $date_type, $desc]);
        }
        header('Location: Farmer.php');
        exit;
    }

    if ($act === 'post_update') {
        $farm_id = (int)($_POST['farm_id'] ?? 0);
        $content = trim($_POST['content']  ?? '');
        if ($farm_id && $content) {
            // تحقق ملكية المزرعة
            $chk = $pdo->prepare("SELECT farm_id FROM qw_farm WHERE farm_id=? AND farmer_id=?");
            $chk->execute([$farm_id, $farmer_id]);
            if ($chk->fetch()) {
                $pdo->prepare("INSERT INTO qw_farm_update (farm_id, content) VALUES (?,?)")
                    ->execute([$farm_id, $content]);
            }
        }
        header('Location: Farmer.php');
        exit;
    }
}

// ── جلب مزارع هذا المزارع ─────────────────────────────────
$farmsStmt = $pdo->prepare("SELECT * FROM qw_farm WHERE farmer_id = ? ORDER BY created_at DESC");
$farmsStmt->execute([$farmer_id]);
$myFarms = $farmsStmt->fetchAll();

// ── إجمالي إحصائيات من qw_farm ──────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(f.total_area_sqm),0) AS total_area,
        COUNT(DISTINCT ir.investor_id) AS active_investors,
        COALESCE(SUM(ir.area_sqm),0) AS leased_area
    FROM qw_farm f
    LEFT JOIN qw_farm_offer fo ON fo.farm_id = f.farm_id
    LEFT JOIN qw_investment_request ir ON ir.offer_id = fo.offer_id AND ir.req_status = 'accepted'
    WHERE f.farmer_id = ?
");
$statsStmt->execute([$farmer_id]);
$stats = $statsStmt->fetch();

// ── طلبات الاستثمار الواردة ───────────────────────────────
$reqStmt = $pdo->prepare("
    SELECT ir.request_id, ir.area_sqm, ir.duration, ir.harvest_method,
           ir.req_status, ir.submitted_at, ir.rejection_reason,
           f.name AS farm_name,
           u.first_name AS inv_first, u.last_name AS inv_last,
           u.phone AS inv_phone,
           (ir.area_sqm * fo.price) AS amount
    FROM qw_investment_request ir
    JOIN qw_farm_offer fo ON ir.offer_id   = fo.offer_id
    JOIN qw_farm f        ON fo.farm_id    = f.farm_id
    JOIN qw_investor inv  ON ir.investor_id = inv.investor_id
    JOIN qw_user u        ON inv.user_id   = u.user_id
    WHERE f.farmer_id = ?
    ORDER BY ir.submitted_at DESC
");
$reqStmt->execute([$farmer_id]);
$incomingRequests = $reqStmt->fetchAll();

// ── تحديثات المزارع ───────────────────────────────────────
$updStmt = $pdo->prepare("
    SELECT fu.*, f.name AS farm_name
    FROM qw_farm_update fu
    JOIN qw_farm f ON fu.farm_id = f.farm_id
    WHERE f.farmer_id = ?
    ORDER BY fu.created_at DESC
");
$updStmt->execute([$farmer_id]);
$myUpdates = $updStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>قِنوان | المزارع</title>
  <link rel="stylesheet" href="style.css" />
</head>

<body>

<!-- SVG sprites — أيقونات الأزرار ونخلة الهيدر -->
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
     صفحة 1 — لوحة التحكم
     ============================================================ -->
<div class="page active" id="page-dashboard">
  <nav>
    <button class="nav-back" onclick="history.back()">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link active" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link"        onclick="showPage('add-farm')">إضافة مزرعة</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('send-update')">نشر تحديث</button>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <!-- ضعي صورة اللوقو باسم logo في نفس المجلد -->
      <img class="logo-img" src="logo.png" alt="قِنوان"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div>
        <span class="logo-name">قِنوان</span>
        <span class="logo-sub">المزارع</span>
      </div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">لوحة تحكم المزارع</h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-dot"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:24px"></div>
      </div>
    </div>

    <!-- إحصائيات — بيانات من جداول Farm وInvestmentRequest وTransaction -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">المساحة الكلية</div>
        <div class="stat-value">5,000 م²</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">المساحة المؤجرة</div>
        <div class="stat-value">3,000 م²</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">المساحة المتبقية</div>
        <div class="stat-value">2,000 م²</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">المستثمرون النشطون</div>
        <div class="stat-value">8</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">الأرباح المتوقعة</div>
        <div class="stat-value">45,000 ر</div>
      </div>
    </div>

    <div class="section-title">مزارعي</div>

    <!-- بطاقة مزرعة — بيانات من جدول Farm -->
   <div class="farm-card">
      <div style="display:flex; align-items:center; gap:16px;">
        
        <div class="farm-info">
          <!-- ✅ هذا هو التعديل الوحيد -->
          <div class="farm-name" onclick="openEditFarm()" style="cursor:pointer;">
            مزرعة النخيل الذهبية
          </div>

          <div class="farm-meta">الرياض · سكري</div>
        </div>

      </div>
    </div>

  </div>
</div>
 
<div class="page" id="page-edit-farm">

  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">رجوع</button>
  </nav>

  <div class="page-content">

    <h1 class="page-title">تعديل المزرعة</h1>

    <div class="form-card">

      <div class="form-group">
        <label class="form-label">اسم المزرعة</label>
        <input type="text" class="form-input" placeholder="اسم المزرعة" />
      </div>

      <div class="form-group">
        <label class="form-label">المنطقة</label>
        <input type="text" class="form-input" placeholder="المنطقة" />
      </div>

      <div class="form-group">
        <label class="form-label">نوع النخيل</label>
        <input type="text" class="form-input" placeholder="نوع النخيل" />
      </div>

      <div class="form-group">
        <label class="form-label">تغيير الصورة</label>
        <input type="file" />
      </div>

      <button class="btn-primary" onclick="showPage('dashboard')">
        حفظ
      </button>

    </div>

  </div>
</div>


<!-- ============================================================
     صفحة 2 — نشر تحديث
     ============================================================ -->
<div class="page" id="page-send-update">
  <nav>
    <button class="nav-back" onclick="history.back()">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link"        onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link"        onclick="showPage('add-farm')">إضافة مزرعة</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link active" onclick="showPage('send-update')">نشر تحديث</button>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المزارع</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">نشر تحديث</h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:40px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:16px"></div>
      </div>
    </div>

    <div class="form-card">

      <!-- farm_id FK — يحدد المزرعة في جدول FarmUpdate -->
      <div class="form-group">
        <label class="form-label">اختر المزرعة</label>
        <select class="form-select">
          <option value="" disabled selected>اختر المزرعة</option>
          <option value="1">مزرعة النخيل الذهبية</option>
          <option value="2">واحة العجوة المباركة</option>
        </select>
      </div>

      <!-- content — نص التحديث في جدول FarmUpdate -->
      <div class="form-group">
        <label class="form-label">نص التحديث</label>
        <textarea class="form-textarea" placeholder="اكتب تحديثاً عن المزرعة..."></textarea>
      </div>

      <!-- media_urls و media_type في جدول FarmUpdate -->
      <div class="form-group">
        <label class="form-label">صور أو فيديو</label>
        <div class="upload-zone" onclick="document.getElementById('file-input').click()">
          <svg width="36" height="36" viewBox="0 0 32 32" fill="none"
               stroke="#8A6F5A" stroke-width="2" style="display:block;margin:0 auto;">
            <use href="#icon-upload"/>
          </svg>
          <div class="upload-text">اضغط لرفع الملفات</div>
          <div class="upload-hint">JPG · PNG · MP4 — حتى 50MB</div>
        </div>
        <input type="file" id="file-input" multiple accept="image/*,video/*"
               style="display:none" onchange="previewFiles(this)" />
        <div id="file-preview"></div>
      </div>

      <button class="btn-primary" onclick="submitUpdate()">
        <svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="white" stroke-width="1.6">
          <use href="#icon-send"/>
        </svg>
        نشر التحديث
      </button>
    </div>
  </div>
</div>


<!-- ============================================================
     صفحة 3 — إضافة مزرعة
     ============================================================ -->
<div class="page" id="page-add-farm">
  <nav>
    <button class="nav-back" onclick="history.back()">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link"        onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link active" onclick="showPage('add-farm')">إضافة مزرعة</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('send-update')">نشر تحديث</button>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المزارع</span></div>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-title-wrap">
      <h1 class="page-title">إضافة مزرعة جديدة</h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:24px"></div>
      </div>
    </div>

    <div class="form-card" style="max-width:680px;">

      <!-- name في جدول Farm -->
      <div class="form-group">
        <label class="form-label">اسم المزرعة</label>
        <input type="text" class="form-input" placeholder="أدخل اسم المزرعة" />
      </div>

      <!-- region في جدول Farm -->
      <div class="form-group">
        <label class="form-label">المنطقة</label>
        <select class="form-select">
          <option value="" disabled selected>اختر المنطقة</option>
          <option>الرياض</option>
          <option>القصيم</option>
          <option>المدينة المنورة</option>
          <option>الأحساء</option>
          <option>تبوك</option>
          <option>حائل</option>
        </select>
      </div>

      <!-- total_area_sqm في جدول Farm -->
      <div class="form-row">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">المساحة (م²)</label>
          <input type="number" class="form-input" placeholder="0" min="0" />
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">عدد النخيل</label>
          <input type="number" class="form-input" placeholder="0" min="0" />
        </div>
      </div>
      <div style="margin-bottom:24px"></div>

      <!-- palm_type في جدول Farm -->
      <div class="form-group">
        <label class="form-label">نوع النخيل</label>
        <select class="form-select">
          <option value="" disabled selected>اختر النوع</option>
          <option>سكري</option>
          <option>مجهول</option>
          <option>خلاص</option>
          <option>برحي</option>
          <option>صفري</option>
          <option>رزيز</option>
        </select>
      </div>

      <!-- date_type في جدول Farm -->
      <div class="form-group">
        <label class="form-label">نوع التمر</label>
        <select class="form-select">
          <option value="" disabled selected>اختر نوع التمر</option>
          <option>سكري</option>
          <option>عجوة</option>
          <option>خلاص</option>
          <option>برحي</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">الإنتاج الموسمي (كجم)</label>
        <input type="number" class="form-input" placeholder="0" min="0" />
      </div>

      <div class="form-group">
        <label class="form-label">صور المزرعة</label>
        <div class="upload-zone" onclick="document.getElementById('farm-images').click()">
          <svg width="36" height="36" viewBox="0 0 32 32" fill="none"
               stroke="#8A6F5A" stroke-width="2" style="display:block;margin:0 auto;">
            <use href="#icon-upload"/>
          </svg>
          <div class="upload-text">ارفع صور المزرعة</div>
        </div>
        <input type="file" id="farm-images" multiple accept="image/*" style="display:none" />
      </div>

      <button class="btn-primary" onclick="submitFarm()">
        <svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="white" stroke-width="2.2">
          <use href="#icon-plus"/>
        </svg>
        إضافة المزرعة
      </button>
    </div>
  </div>
</div>


<!-- ============================================================
     صفحة 4 — طلبات الاستثمار
     ============================================================ -->
<div class="page" id="page-requests">
  <nav>
    <button class="nav-back" onclick="history.back()">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link"        onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link"        onclick="showPage('add-farm')">إضافة مزرعة</button>
      <button class="nav-link active" onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('send-update')">نشر تحديث</button>
    </div>
    <div class="nav-logo" onclick="showPage('dashboard')">
      <img class="logo-img" src="logo.png" alt="قِنوان"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none">ق</div>
      <div><span class="logo-name">قِنوان</span><span class="logo-sub">المزارع</span></div>
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

    <!-- فلاتر status من جدول InvestmentRequest -->
    <div class="filter-bar">
      <button class="filter-btn active" onclick="filterRequests('all',this)">الكل</button>
      <button class="filter-btn"        onclick="filterRequests('pending',this)">قيد الانتظار</button>
      <button class="filter-btn"        onclick="filterRequests('accepted',this)">مقبولة</button>
      <button class="filter-btn"        onclick="filterRequests('rejected',this)">مرفوضة</button>
    </div>

    <div id="requests-list">

      <div class="request-card status-accepted" data-status="accepted">
        <div class="request-header">
          <span class="status-badge">مقبول</span>
          <span class="request-name">أحمد المطيري</span>
        </div>
        <div class="request-details">
          <span class="request-detail">مزرعة النخيل الذهبية</span>
          <span class="request-detail">200 م²</span>
          <span class="request-detail">سنة واحدة</span>
          <span class="request-detail">استلام التمور في المنزل</span>
        </div>
      </div>

      <div class="request-card status-pending" data-status="pending">
        <div class="request-header">
          <span class="status-badge">قيد الانتظار</span>
          <span class="request-name">أحمد المطيري</span>
        </div>
        <div class="request-details">
          <span class="request-detail">واحة العجوة المباركة</span>
          <span class="request-detail">100 م²</span>
          <span class="request-detail">موسم واحد</span>
          <span class="request-detail">بيع المحصول واستلام الأرباح</span>
        </div>
        <div class="request-actions">
          <button class="btn-accept" onclick="acceptRequest(this)">قبول</button>
          <button class="btn-reject" onclick="rejectRequest(this)">رفض</button>
        </div>
      </div>

      <div class="request-card status-accepted" data-status="accepted">
        <div class="request-header">
          <span class="status-badge">مقبول</span>
          <span class="request-name">سارة العتيبي</span>
        </div>
        <div class="request-details">
          <span class="request-detail">مزارع الخلاص الأصيلة</span>
          <span class="request-detail">500 م²</span>
          <span class="request-detail">سنتان</span>
          <span class="request-detail">التبرع للجمعيات الخيرية</span>
        </div>
      </div>

      <div class="request-card status-rejected" data-status="rejected">
        <div class="request-header">
          <span class="status-badge">مرفوض</span>
          <span class="request-name">نورة الدوسري</span>
        </div>
        <div class="request-details">
          <span class="request-detail">نخيل البرحي الفاخر</span>
          <span class="request-detail">150 م²</span>
          <span class="request-detail">سنة واحدة</span>
          <span class="request-detail">استلام التمور في المنزل</span>
        </div>
      </div>

      <div class="request-card status-pending" data-status="pending">
        <div class="request-header">
          <span class="status-badge">قيد الانتظار</span>
          <span class="request-name">سارة العتيبي</span>
        </div>
        <div class="request-details">
          <span class="request-detail">مزرعة المجدول الملكية</span>
          <span class="request-detail">100 م²</span>
          <span class="request-detail">موسم واحد</span>
          <span class="request-detail">بيع المحصول واستلام الأرباح</span>
        </div>
        <div class="request-actions">
          <button class="btn-accept" onclick="acceptRequest(this)">قبول</button>
          <button class="btn-reject" onclick="rejectRequest(this)">رفض</button>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Toast إشعار -->
<div class="toast" id="toast"></div>


<script>
// ── بيانات PHP الحقيقية ──────────────────────────────────
const PHP_FARMER_NAME    = <?= json_encode($first_name, JSON_UNESCAPED_UNICODE) ?>;
const PHP_FARMS          = <?= json_encode($myFarms, JSON_UNESCAPED_UNICODE) ?>;
const PHP_REQUESTS       = <?= json_encode($incomingRequests, JSON_UNESCAPED_UNICODE) ?>;
const PHP_UPDATES        = <?= json_encode($myUpdates, JSON_UNESCAPED_UNICODE) ?>;
const PHP_STATS          = <?= json_encode($stats, JSON_UNESCAPED_UNICODE) ?>;

// ── دوال PHP-aware ──────────────────────────────────────
function acceptRequestPHP(requestId) {
    const f = document.createElement('form');
    f.method = 'POST'; f.action = 'Farmer.php';
    f.innerHTML = '<input name="act" value="accept"><input name="request_id" value="' + requestId + '">';
    document.body.appendChild(f); f.submit();
}
function rejectRequestPHP(requestId, reason) {
    const f = document.createElement('form');
    f.method = 'POST'; f.action = 'Farmer.php';
    f.innerHTML = '<input name="act" value="reject"><input name="request_id" value="' + requestId + '"><input name="reason" value="' + (reason||'') + '">';
    document.body.appendChild(f); f.submit();
}
</script>

<script src="script.js"></script>

</body>
</html>