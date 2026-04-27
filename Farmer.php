<?php
// ============================================================
// قِنوان — Farmer.php  (Updated: US-03 to US-11)
// ============================================================
require_once 'db_connect.php';
require_once 'notifications.php';
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
            SELECT ir.request_id, ir.area_sqm, ir.investor_id, fo.price, f.name AS farm_name,
                   u.user_id AS inv_user_id
            FROM qw_investment_request ir
            JOIN qw_farm_offer fo ON ir.offer_id = fo.offer_id
            JOIN qw_farm f        ON fo.farm_id  = f.farm_id
            JOIN qw_investor i    ON ir.investor_id = i.investor_id
            JOIN qw_user u        ON i.user_id   = u.user_id
            WHERE ir.request_id = ? AND f.farmer_id = ? AND ir.req_status = 'pending'
        ");
        $chk->execute([$request_id, $farmer_id]);
        $row = $chk->fetch();
        if ($row) {
            $pdo->prepare("UPDATE qw_investment_request SET req_status='accepted' WHERE request_id=?")->execute([$request_id]);
            $amount = round($row['area_sqm'] * $row['price'], 2);
            $pdo->prepare("INSERT INTO qw_transaction (request_id, amount, payment_status) VALUES (?,?,'pending')")->execute([$request_id, $amount]);
            try { $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,'accept_request','investment_request',?)")->execute([$_SESSION['user_id'],$request_id]); } catch(Exception $e){}
            // إشعار المستثمر
            notifyInvestmentDecision($pdo, $row['investor_id'], $row['inv_user_id'], $row['farm_name'], 'accepted');
            $_SESSION['flash'] = 'تم قبول الطلب وإشعار المستثمر.';
        }
        header('Location: Farmer.php'); exit;
    }

    // US-08: Reject request
    if ($act === 'reject') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'لم يتم تحديد سبب');
        $chk = $pdo->prepare("
            SELECT ir.request_id, ir.investor_id, f.name AS farm_name,
                   u.user_id AS inv_user_id
            FROM qw_investment_request ir
            JOIN qw_farm_offer fo ON ir.offer_id = fo.offer_id
            JOIN qw_farm f        ON fo.farm_id  = f.farm_id
            JOIN qw_investor i    ON ir.investor_id = i.investor_id
            JOIN qw_user u        ON i.user_id   = u.user_id
            WHERE ir.request_id = ? AND f.farmer_id = ? AND ir.req_status = 'pending'
        ");
        $chk->execute([$request_id, $farmer_id]);
        $row = $chk->fetch();
        if ($row) {
            $pdo->prepare("UPDATE qw_investment_request SET req_status='rejected', rejection_reason=? WHERE request_id=?")->execute([$reason, $request_id]);
            try { $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,'reject_request','investment_request',?)")->execute([$_SESSION['user_id'],$request_id]); } catch(Exception $e){}
            // إشعار المستثمر
            notifyInvestmentDecision($pdo, $row['investor_id'], $row['inv_user_id'], $row['farm_name'], 'rejected');
            $_SESSION['flash'] = 'تم رفض الطلب وإشعار المستثمر.';
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

    // إضافة عرض استثمار لمزرعة
    if ($act === 'add_offer') {
        $farm_id    = (int)($_POST['offer_farm_id'] ?? 0);
        $area_size  = (float)($_POST['area_size']   ?? 0);
        $price      = (float)($_POST['price']       ?? 0);

        if ($farm_id && $area_size > 0 && $price > 0) {
            // تأكد أن المزرعة تخص هذا المزارع وأنها معتمدة
            $chk = $pdo->prepare("SELECT farm_id, total_area_sqm FROM qw_farm WHERE farm_id=? AND farmer_id=? AND farm_status='approved'");
            $chk->execute([$farm_id, $farmer_id]);
            $farmRow = $chk->fetch();
            if ($farmRow) {
                if ($area_size > $farmRow['total_area_sqm']) {
                    $_SESSION['flash_error'] = 'مساحة العرض أكبر من مساحة المزرعة الكلية.';
                } else {
                    $pdo->prepare("INSERT INTO qw_farm_offer (farm_id, area_size, price) VALUES (?,?,?)")
                        ->execute([$farm_id, $area_size, $price]);
                    try { $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,'add_offer','farm',?)")->execute([$_SESSION['user_id'],$farm_id]); } catch(Exception $e){}
                    $_SESSION['flash'] = 'تم إضافة العرض بنجاح. يمكن للمستثمرين الآن رؤيته.';
                }
            } else {
                $_SESSION['flash_error'] = 'المزرعة غير موجودة أو غير معتمدة بعد. يجب أن تكون المزرعة معتمدة من الإدارة أولاً.';
            }
        } else {
            $_SESSION['flash_error'] = 'يرجى تعبئة جميع حقول العرض.';
        }
        header('Location: Farmer.php'); exit;
    }

    // US-09: Post update — ينبّه المستثمرين (US-17 TC2)
    if ($act === 'post_update') {
        $farm_id = (int)($_POST['farm_id'] ?? 0);
        $content = trim($_POST['content']  ?? '');
        if ($farm_id && $content) {
            $chk = $pdo->prepare("
                SELECT f.farm_id, f.name FROM qw_farm f
                JOIN qw_investment_request ir ON ir.offer_id IN (SELECT offer_id FROM qw_farm_offer WHERE farm_id=f.farm_id)
                WHERE f.farm_id=? AND f.farmer_id=? AND ir.req_status='accepted'
                LIMIT 1
            ");
            $chk->execute([$farm_id, $farmer_id]);
            $farmRow = $chk->fetch();
            if ($farmRow) {
                // رفع الصور إن وجدت
                $mediaUrls  = [];
                $mediaType  = null;
                $uploadDir  = __DIR__ . '/uploads/updates/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                if (!empty($_FILES['update_images']['name'][0])) {
                    foreach ($_FILES['update_images']['tmp_name'] as $i => $tmpName) {
                        if ($_FILES['update_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                        $origName = $_FILES['update_images']['name'][$i];
                        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) continue;
                        if ($_FILES['update_images']['size'][$i] > 5 * 1024 * 1024) continue; // 5MB max
                        $newName  = uniqid('upd_', true) . '.' . $ext;
                        if (move_uploaded_file($tmpName, $uploadDir . $newName)) {
                            $mediaUrls[] = 'uploads/updates/' . $newName;
                        }
                    }
                    if (!empty($mediaUrls)) $mediaType = 'image';
                }

                $mediaJson = !empty($mediaUrls) ? json_encode($mediaUrls) : null;
                $pdo->prepare("INSERT INTO qw_farm_update (farm_id, content, media_urls, media_type) VALUES (?,?,?,?)")
                    ->execute([$farm_id, $content, $mediaJson, $mediaType]);
                $update_id = (int)$pdo->lastInsertId();
                try { $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,'post_update','farm',?)")->execute([$_SESSION['user_id'],$farm_id]); } catch(Exception $e){}
                // US-17 TC2: أرسل إشعارات لكل المستثمرين في هذه المزرعة
                notifyInvestorsOfFarmUpdate($pdo, $farm_id, $farmRow['name'], $update_id);
                $_SESSION['flash'] = 'تم نشر التحديث بنجاح وتم إشعار المستثمرين.';
            } else {
                $_SESSION['flash_error'] = 'لا يمكن النشر إلا على مزارع بها مستثمرون نشطون.';
            }
        }
        header('Location: Farmer.php'); exit;
    }

    // US-18: قبول أو رفض طلب تغيير طريقة الحصاد
    if ($act === 'decide_harvest_change') {
        $hcr_id      = (int)($_POST['hcr_id']      ?? 0);
        $decision    = $_POST['decision']            ?? ''; // 'approved' or 'rejected'
        $farmer_note = trim($_POST['farmer_note']   ?? '');

        if (!in_array($decision, ['approved','rejected']) || !$hcr_id) {
            $_SESSION['flash_error'] = 'بيانات غير صحيحة.'; header('Location: Farmer.php'); exit;
        }

        // تأكد أن الطلب ينتمي لمزارع هذا المزارع وأنه pending
        $chk = $pdo->prepare("
            SELECT hcr.hcr_id, hcr.request_id, hcr.investor_id, hcr.new_harvest_method, hcr.new_delivery_address,
                   f.name AS farm_name, ir.investor_id AS inv_id,
                   inv_u.user_id AS inv_user_id
            FROM qw_harvest_change_request hcr
            JOIN qw_investment_request ir ON hcr.request_id = ir.request_id
            JOIN qw_farm_offer fo         ON ir.offer_id    = fo.offer_id
            JOIN qw_farm f                ON fo.farm_id     = f.farm_id
            JOIN qw_investor invi         ON invi.investor_id = hcr.investor_id
            JOIN qw_user inv_u            ON inv_u.user_id   = invi.user_id
            WHERE hcr.hcr_id=? AND f.farmer_id=? AND hcr.hcr_status='pending'
        ");
        $chk->execute([$hcr_id, $farmer_id]);
        $hcr = $chk->fetch();

        if (!$hcr) {
            $_SESSION['flash_error'] = 'الطلب غير موجود أو تمت معالجته.'; header('Location: Farmer.php'); exit;
        }

        $pdo->beginTransaction();
        try {
            // تحديث حالة الطلب
            $pdo->prepare("UPDATE qw_harvest_change_request SET hcr_status=?, farmer_note=?, decided_at=NOW() WHERE hcr_id=?")
                ->execute([$decision, $farmer_note ?: null, $hcr_id]);

            // TC3 Outcome: إذا approved — حدّث طريقة الحصاد في الطلب الأصلي
            if ($decision === 'approved') {
                $pdo->prepare("UPDATE qw_investment_request SET harvest_method=?, delivery_address=? WHERE request_id=?")
                    ->execute([$hcr['new_harvest_method'], $hcr['new_delivery_address'], $hcr['request_id']]);
            }

            try { $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,'decide_harvest_change','harvest_change_request',?)")->execute([$_SESSION['user_id'],$hcr_id]); } catch(Exception $e){}

            // TC3: أرسل إشعار للمستثمر
            notifyHarvestChangeOutcome($pdo, $hcr['inv_id'], $hcr['inv_user_id'], $hcr['farm_name'], $decision);

            $pdo->commit();
            $ar = $decision === 'approved' ? 'تمت الموافقة على' : 'تم رفض';
            $_SESSION['flash'] = "{$ar} طلب تغيير طريقة الحصاد وتم إشعار المستثمر.";
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = 'حدث خطأ. حاول مجدداً.';
        }
        header('Location: Farmer.php'); exit;
    }

} // end POST

// ── جلب البيانات ─────────────────────────────────────────
$farmsStmt = $pdo->prepare("SELECT * FROM qw_farm WHERE farmer_id = ? ORDER BY created_at DESC");
$farmsStmt->execute([$farmer_id]);
$myFarms = $farmsStmt->fetchAll();

// جلب العروض الخاصة بمزارع هذا المزارع
$offersStmt = $pdo->prepare("
    SELECT fo.*, f.name AS farm_name, f.farm_status
    FROM qw_farm_offer fo
    JOIN qw_farm f ON fo.farm_id = f.farm_id
    WHERE f.farmer_id = ?
    ORDER BY fo.offer_id DESC
");
$offersStmt->execute([$farmer_id]);
$myOffers = $offersStmt->fetchAll();

// المزارع المعتمدة فقط (لإضافة عروض عليها)
$approvedFarmsForOffer = array_filter($myFarms, fn($f) => $f['farm_status'] === 'approved');

// US-18: طلبات تغيير الحصاد الواردة
$hcrStmt = $pdo->prepare("
    SELECT hcr.hcr_id, hcr.request_id, hcr.new_harvest_method, hcr.new_delivery_address,
           hcr.hcr_status, hcr.farmer_note, hcr.created_at,
           f.name AS farm_name,
           u.first_name AS inv_first, u.last_name AS inv_last,
           ir.harvest_method AS current_method
    FROM qw_harvest_change_request hcr
    JOIN qw_investment_request ir ON hcr.request_id = ir.request_id
    JOIN qw_farm_offer fo         ON ir.offer_id    = fo.offer_id
    JOIN qw_farm f                ON fo.farm_id     = f.farm_id
    JOIN qw_investor invi         ON invi.investor_id = hcr.investor_id
    JOIN qw_user u                ON u.user_id = invi.user_id
    WHERE f.farmer_id = ?
    ORDER BY hcr.hcr_status ASC, hcr.created_at DESC
");
$hcrStmt->execute([$farmer_id]);
$harvestChangeRequests = $hcrStmt->fetchAll();
$pendingHCR = count(array_filter($harvestChangeRequests, fn($h) => $h['hcr_status'] === 'pending'));

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

// Farmer notifications (harvest change requests badge)
$farmerNotifStmt = $pdo->prepare("
    SELECT notif_id, notif_type, title, message, is_read, created_at
    FROM qw_notification
    WHERE user_id = ?
    ORDER BY created_at DESC LIMIT 15
");
try {
    $farmerNotifStmt->execute([$_SESSION['user_id']]);
    $farmerNotifications = $farmerNotifStmt->fetchAll();
    $farmerUnread = count(array_filter($farmerNotifications, fn($n) => !$n['is_read']));
} catch(Exception $e) {
    $farmerNotifications = [];
    $farmerUnread = 0;
}

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
      <button class="nav-link"        onclick="showPage('offers')">عروض الاستثمار</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('harvest-changes')" style="position:relative;">🔄 تغيير الحصاد<?php if($pendingHCR>0):?> <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;"><?= $pendingHCR ?></span><?php endif;?></button>
      <button class="nav-link"        onclick="showPage('send-update')">نشر تحديث</button>
      <button class="nav-link"        onclick="showPage('farmer-notifications')" style="position:relative;">🔔<?php if($farmerUnread>0):?> <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;"><?= $farmerUnread ?></span><?php endif;?></button>
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
      <button class="nav-link"        onclick="showPage('offers')">عروض الاستثمار</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('harvest-changes')">🔄 تغيير الحصاد<?php if($pendingHCR>0):?> <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;"><?php echo $pendingHCR;?></span><?php endif;?></button>
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
      <form method="POST" action="Farmer.php" enctype="multipart/form-data">
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
          <label class="form-label">صور (اختياري — حتى 5 صور)</label>
          <div class="upload-zone" onclick="document.getElementById('file-input').click()" style="cursor:pointer;">
            <div class="upload-text">📷 اضغط لرفع الصور</div>
            <div class="upload-hint">JPG · PNG — حتى 5MB لكل صورة</div>
          </div>
          <input type="file" id="file-input" name="update_images[]" multiple accept="image/*" style="display:none" onchange="previewFiles(this)" />
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
      <button class="nav-link"        onclick="showPage('offers')">عروض الاستثمار</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('harvest-changes')">🔄 تغيير الحصاد<?php if($pendingHCR>0):?> <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;"><?php echo $pendingHCR;?></span><?php endif;?></button>
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
     صفحة عروض الاستثمار
     ============================================================ -->
<div class="page" id="page-offers">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link"        onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link"        onclick="showPage('add-farm')">إضافة مزرعة</button>
      <button class="nav-link active" onclick="showPage('offers')">عروض الاستثمار</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('harvest-changes')">🔄 تغيير الحصاد<?php if($pendingHCR>0):?> <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;"><?php echo $pendingHCR;?></span><?php endif;?></button>
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
      <h1 class="page-title">عروض الاستثمار</h1>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div><div class="orn-dot"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:24px"></div>
      </div>
    </div>

    <!-- فورم إضافة عرض جديد -->
    <div class="form-card" style="max-width:680px; margin-bottom:32px;">
      <h3 style="font-family:'Amiri',serif;font-size:18px;color:var(--brown-dark);margin-bottom:18px;">➕ إضافة عرض جديد</h3>

      <?php if (empty($approvedFarmsForOffer)): ?>
        <div class="auth-error" style="margin-bottom:0;">
          ⚠️ لا توجد مزارع معتمدة حالياً. يجب أن تكون المزرعة معتمدة من الإدارة أولاً قبل إضافة عروض عليها.
        </div>
      <?php else: ?>
        <form method="POST" action="Farmer.php" id="addOfferForm">
          <input type="hidden" name="act" value="add_offer" />

          <div class="form-group">
            <label class="form-label">اختر المزرعة <span style="color:red">*</span></label>
            <select name="offer_farm_id" class="form-select" required>
              <option value="" disabled selected>اختر المزرعة</option>
              <?php foreach ($approvedFarmsForOffer as $af): ?>
                <option value="<?= $af['farm_id'] ?>">
                  <?= htmlspecialchars($af['name']) ?> — <?= htmlspecialchars($af['region']) ?> (<?= number_format($af['total_area_sqm'], 0) ?> م²)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-row">
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">مساحة العرض (م²) <span style="color:red">*</span></label>
              <input type="number" name="area_size" class="form-input" placeholder="مثال: 500" min="1" step="0.01" required />
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">السعر لكل م² (ريال) <span style="color:red">*</span></label>
              <input type="number" name="price" class="form-input" placeholder="مثال: 150" min="1" step="0.01" required />
            </div>
          </div>
          <div style="margin-bottom:18px"></div>

          <button type="submit" class="btn-primary">💰 إضافة العرض</button>
        </form>
      <?php endif; ?>
    </div>

    <!-- قائمة العروض الحالية -->
    <div class="section-title">عروضي الحالية (<?= count($myOffers) ?>)</div>

    <?php if (empty($myOffers)): ?>
      <div class="empty-state-msg">لا توجد عروض مضافة حتى الآن.</div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.06);">
          <thead>
            <tr style="background:var(--green-dark);color:#fff;font-size:14px;">
              <th style="padding:12px 16px;text-align:right;">المزرعة</th>
              <th style="padding:12px 16px;text-align:right;">المساحة (م²)</th>
              <th style="padding:12px 16px;text-align:right;">السعر/م²</th>
              <th style="padding:12px 16px;text-align:right;">إجمالي القيمة</th>
              <th style="padding:12px 16px;text-align:right;">حالة المزرعة</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($myOffers as $i => $offer): ?>
              <tr style="border-bottom:1px solid var(--border-light);background:<?= $i % 2 === 0 ? '#fff' : '#f9fafb' ?>;">
                <td style="padding:12px 16px;font-weight:600;color:var(--brown-dark);">
                  🌴 <?= htmlspecialchars($offer['farm_name']) ?>
                </td>
                <td style="padding:12px 16px;"><?= number_format($offer['area_size'], 0) ?> م²</td>
                <td style="padding:12px 16px;"><?= number_format($offer['price'], 2) ?> ر.س</td>
                <td style="padding:12px 16px;color:var(--green-dark);font-weight:700;">
                  <?= number_format($offer['area_size'] * $offer['price'], 0) ?> ر.س
                </td>
                <td style="padding:12px 16px;">
                  <?php
                    $fsMap = ['approved'=>'✅ معتمدة','pending'=>'⏳ قيد المراجعة','rejected'=>'❌ مرفوضة','deactivated'=>'⛔ معطلة'];
                    echo $fsMap[$offer['farm_status']] ?? $offer['farm_status'];
                  ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
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
      <button class="nav-link"        onclick="showPage('offers')">عروض الاستثمار</button>
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

<!-- ============================================================
     صفحة طلبات تغيير الحصاد — US-18
     ============================================================ -->
<div class="page" id="page-harvest-changes">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">العودة للرئيسية</button>
    <div class="nav-links">
      <button class="nav-link"        onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link"        onclick="showPage('add-farm')">إضافة مزرعة</button>
      <button class="nav-link"        onclick="showPage('offers')">عروض الاستثمار</button>
      <button class="nav-link"        onclick="showPage('requests')">طلبات الاستثمار</button>
      <button class="nav-link"        onclick="showPage('harvest-changes')">🔄 تغيير الحصاد<?php if($pendingHCR>0):?> <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;"><?php echo $pendingHCR;?></span><?php endif;?></button>
      <button class="nav-link active" onclick="showPage('harvest-changes')">
        🔄 تغييرات الحصاد
        <?php if ($pendingHCR > 0): ?>
          <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;"><?= $pendingHCR ?></span>
        <?php endif; ?>
      </button>
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
      <h1 class="page-title">🔄 طلبات تغيير طريقة الحصاد</h1>
      <div class="title-ornament"><div class="orn-line" style="width:60px"></div><div class="orn-diamond"></div><div class="orn-line" style="width:24px"></div></div>
    </div>

    <?php if (empty($harvestChangeRequests)): ?>
      <div class="empty-state-msg">لا توجد طلبات تغيير حصاد حتى الآن.</div>
    <?php else: ?>
      <?php
      $hmLabels = ['sell'=>'💰 بيع المحصول','receive'=>'📦 استلام في المنزل','donate'=>'🤲 تبرع للجمعيات'];
      $hcrStatusLabels = ['pending'=>'⏳ قيد المراجعة','approved'=>'✅ موافَق عليه','rejected'=>'❌ مرفوض'];
      $hcrStatusColors = ['pending'=>'#b45309','approved'=>'#166534','rejected'=>'#991b1b'];
      foreach ($harvestChangeRequests as $hcr):
      ?>
      <div style="background:#fff;border-radius:14px;padding:20px;margin-bottom:16px;box-shadow:var(--shadow-sm);border:1px solid var(--border-light);border-right:4px solid <?= $hcrStatusColors[$hcr['hcr_status']] ?? '#ccc' ?>;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
          <div>
            <div style="font-family:'Amiri',serif;font-size:16px;font-weight:700;color:var(--green-dark);">🌴 <?= htmlspecialchars($hcr['farm_name']) ?></div>
            <div style="font-size:13px;color:var(--text-muted);">المستثمر: <?= htmlspecialchars($hcr['inv_first'] . ' ' . $hcr['inv_last']) ?></div>
          </div>
          <span style="background:<?= $hcrStatusColors[$hcr['hcr_status']] ?? '#ccc' ?>;color:#fff;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;">
            <?= $hcrStatusLabels[$hcr['hcr_status']] ?? $hcr['hcr_status'] ?>
          </span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
          <span style="background:var(--bg-section);padding:3px 10px;border-radius:14px;font-size:13px;">
            من: <?= $hmLabels[$hcr['current_method']] ?? $hcr['current_method'] ?>
          </span>
          <span style="font-size:18px;">←</span>
          <span style="background:#f0fdf4;padding:3px 10px;border-radius:14px;font-size:13px;color:var(--green-dark);font-weight:600;">
            إلى: <?= $hmLabels[$hcr['new_harvest_method']] ?? $hcr['new_harvest_method'] ?>
          </span>
        </div>
        <?php if ($hcr['new_delivery_address'] && $hcr['new_harvest_method'] === 'receive'): ?>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">📍 العنوان الجديد: <?= htmlspecialchars($hcr['new_delivery_address']) ?></div>
        <?php endif; ?>
        <div style="font-size:12px;color:var(--text-faint);margin-bottom:12px;">📅 <?= date('Y-m-d H:i', strtotime($hcr['created_at'])) ?></div>

        <!-- TC3 Outcome: أزرار القبول/الرفض فقط للـ pending -->
        <?php if ($hcr['hcr_status'] === 'pending'): ?>
          <form method="POST" action="Farmer.php" style="display:inline;">
            <input type="hidden" name="act" value="decide_harvest_change">
            <input type="hidden" name="hcr_id" value="<?= $hcr['hcr_id'] ?>">
            <input type="hidden" name="decision" value="approved">
            <input type="hidden" name="farmer_note" value="">
            <button type="submit" style="background:var(--green-dark);color:#fff;border:none;border-radius:var(--radius);padding:8px 18px;font-family:'Noto Naskh Arabic',serif;font-size:13px;font-weight:700;cursor:pointer;margin-left:8px;">
              ✅ موافقة
            </button>
          </form>
          <button onclick="openRejectHCR(<?= $hcr['hcr_id'] ?>)"
            style="background:transparent;border:1.5px solid var(--red);color:var(--red);border-radius:var(--radius);padding:8px 18px;font-family:'Noto Naskh Arabic',serif;font-size:13px;cursor:pointer;">
            ❌ رفض
          </button>
        <?php elseif ($hcr['farmer_note']): ?>
          <div style="background:var(--bg-section);padding:8px 12px;border-radius:8px;font-size:12px;color:var(--text-muted);">
            ملاحظة المزارع: <?= htmlspecialchars($hcr['farmer_note']) ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Modal رفض طلب تغيير الحصاد -->
<div id="rejectHCRModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:900;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:420px;margin:20px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <div style="background:var(--red);padding:16px 20px;color:#fff;font-family:'Amiri',serif;font-size:17px;font-weight:700;">❌ رفض طلب تغيير الحصاد</div>
    <form method="POST" action="Farmer.php" style="padding:20px;">
      <input type="hidden" name="act" value="decide_harvest_change">
      <input type="hidden" name="decision" value="rejected">
      <input type="hidden" name="hcr_id" id="rejectHCRId">
      <label style="font-size:13px;font-weight:600;color:var(--brown-dark);display:block;margin-bottom:8px;">سبب الرفض (اختياري)</label>
      <textarea name="farmer_note" class="form-textarea" placeholder="اكتب ملاحظتك للمستثمر..." style="width:100%;min-height:90px;margin-bottom:14px;"></textarea>
      <div style="display:flex;gap:10px;">
        <button type="button" onclick="document.getElementById('rejectHCRModal').style.display='none'"
          style="flex:1;background:transparent;border:1.5px solid var(--border);border-radius:var(--radius);padding:10px;cursor:pointer;font-family:'Noto Naskh Arabic',serif;">إلغاء</button>
        <button type="submit"
          style="flex:2;background:var(--red);color:#fff;border:none;border-radius:var(--radius);padding:10px;cursor:pointer;font-family:'Noto Naskh Arabic',serif;font-weight:700;">تأكيد الرفض</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     صفحة إشعارات المزارع
     ============================================================ -->
<div class="page" id="page-farmer-notifications">
  <nav>
    <button class="nav-back" onclick="showPage('dashboard')">العودة</button>
    <div class="nav-links">
      <button class="nav-link" onclick="showPage('dashboard')">لوحة التحكم</button>
      <button class="nav-link" onclick="showPage('harvest-changes')">🔄 تغيير الحصاد<?php if($pendingHCR>0):?> <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;"><?= $pendingHCR ?></span><?php endif;?></button>
      <button class="nav-link active" onclick="showPage('farmer-notifications')">🔔 الإشعارات</button>
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
      <h1 class="page-title">🔔 الإشعارات</h1>
      <div class="title-ornament"><div class="orn-line" style="width:60px"></div><div class="orn-diamond"></div><div class="orn-line" style="width:24px"></div></div>
    </div>
    <?php if (empty($farmerNotifications)): ?>
      <div class="empty-state-msg">لا توجد إشعارات.</div>
    <?php else: ?>
      <?php
      $nIcons = ['harvest_change_request'=>'🔄','request_accepted'=>'✅','request_rejected'=>'❌','farm_update'=>'📡'];
      foreach ($farmerNotifications as $n):
      ?>
        <div style="background:<?= $n['is_read']?'#fff':'#f0fdf4' ?>;border:1px solid <?= $n['is_read']?'var(--border-light)':'#bbf7d0' ?>;border-radius:10px;padding:14px 16px;margin-bottom:10px;display:flex;gap:12px;align-items:flex-start;">
          <span style="font-size:22px;"><?= $nIcons[$n['notif_type']] ?? '🔔' ?></span>
          <div style="flex:1;">
            <div style="font-weight:700;font-size:13px;color:var(--brown-dark);"><?= htmlspecialchars($n['title']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:3px;"><?= htmlspecialchars($n['message']) ?></div>
            <div style="font-size:11px;color:var(--text-faint);margin-top:4px;">📅 <?= date('Y-m-d H:i', strtotime($n['created_at'])) ?></div>
          </div>
          <?php if ($n['notif_type'] === 'harvest_change_request'): ?>
            <button onclick="showPage('harvest-changes')" style="background:var(--green-dark);color:#fff;border:none;border-radius:8px;padding:6px 12px;font-family:'Noto Naskh Arabic',serif;font-size:12px;cursor:pointer;white-space:nowrap;">عرض الطلب</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <button onclick="markFarmerNotifsRead()" style="margin-top:6px;background:transparent;border:1px solid var(--border);border-radius:var(--radius);padding:8px 18px;font-family:'Noto Naskh Arabic',serif;font-size:13px;cursor:pointer;color:var(--text-muted);">✓ تحديد الكل كمقروء</button>
    <?php endif; ?>
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

function openRejectHCR(hcrId) {
  document.getElementById('rejectHCRId').value = hcrId;
  document.getElementById('rejectHCRModal').style.display = 'flex';
}

function markFarmerNotifsRead() {
  fetch('investor.php', { method:'POST', body: (() => { const f=new FormData(); f.append('act','mark_notifications_read'); return f; })() })
    .catch(()=>{});
  document.querySelectorAll('#page-farmer-notifications [style*="f0fdf4"]').forEach(el => {
    el.style.background='#fff'; el.style.borderColor='var(--border-light)';
  });
  showToast('✅ تم تحديد كل الإشعارات كمقروءة');
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
