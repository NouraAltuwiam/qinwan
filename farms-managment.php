<?php
// ============================================================
// قِنوان — farms-managment.php  (US-Admin-03, US-Admin-04, US-Admin-09)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $farm_id  = (int)($_POST['farm_id']  ?? 0);
    $decision = $_POST['decision'] ?? '';
    $reason   = trim($_POST['reason'] ?? '');
    $moreinfo = trim($_POST['moreinfo'] ?? '');

    // ============================================================
    // ✅ تعديل بيانات المزرعة
    // ============================================================
    if ($farm_id && $decision === 'edit') {
        $name        = trim($_POST['edit_name']        ?? '');
        $description = trim($_POST['edit_description'] ?? '');
        $area        = trim($_POST['edit_area']        ?? '');

        if (empty($name)) {
            header('Location: farms-managment.php?error=name_required'); exit;
        }

        $pdo->prepare("UPDATE qw_farm SET name=?, description=?, total_area_sqm=? WHERE farm_id=?")
            ->execute([$name, $description, $area, $farm_id]);

        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id,created_at) VALUES (?,'edit_farm','farm',?,NOW())")
                ->execute([$_SESSION['user_id'], $farm_id]);
        } catch(Exception $e) {}

        // ✅ إشعار للمزارع
        try {
            $farmerUser = $pdo->prepare("SELECT u.user_id FROM qw_farm f JOIN qw_farmer fr ON f.farmer_id=fr.farmer_id JOIN qw_user u ON fr.user_id=u.user_id WHERE f.farm_id=?");
            $farmerUser->execute([$farm_id]);
            $farmerUserId = $farmerUser->fetchColumn();
            if ($farmerUserId) {
                $pdo->prepare("INSERT INTO qw_notification (user_id,notif_type,title,message,entity_type,entity_id,created_at) VALUES (?,'farm_edit','✏️ تم تعديل بيانات مزرعتك','قام الأدمن بتعديل بيانات مزرعتك على المنصة.','farm',?,NOW())")
                    ->execute([$farmerUserId, $farm_id]);
            }
        } catch(Exception $e) {}

        header('Location: farms-managment.php?success=edited'); exit;
    }

    // ============================================================
    // ✅ حذف نهائي مع سبب إجباري
    // ============================================================
    if ($farm_id && $decision === 'delete') {
        if (empty($reason)) {
            header('Location: farms-managment.php?error=delete_reason_required'); exit;
        }
        try {
            $farmerUser = $pdo->prepare("SELECT u.user_id FROM qw_farm f JOIN qw_farmer fr ON f.farmer_id=fr.farmer_id JOIN qw_user u ON fr.user_id=u.user_id WHERE f.farm_id=?");
            $farmerUser->execute([$farm_id]);
            $farmerUserId = $farmerUser->fetchColumn();
            if ($farmerUserId) {
                $pdo->prepare("INSERT INTO qw_notification (user_id,notif_type,title,message,entity_type,entity_id,created_at) VALUES (?,'farm_deleted','🗑️ تم حذف مزرعتك',?,'farm',?,NOW())")
                    ->execute([$farmerUserId, 'تم حذف مزرعتك نهائياً. السبب: ' . $reason, $farm_id]);
            }
        } catch(Exception $e) {}
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id,created_at) VALUES (?,'delete_farm','farm',?,NOW())")
                ->execute([$_SESSION['user_id'], $farm_id]);
        } catch(Exception $e) {}
        $pdo->prepare("DELETE FROM qw_farm WHERE farm_id=?")->execute([$farm_id]);
        header('Location: farms-managment.php?success=deleted'); exit;
    }

    // ============================================================
    // ✅ طلب معلومات إضافية
    // ============================================================
    if ($farm_id && $decision === 'moreinfo') {
        if (empty($moreinfo)) {
            header('Location: farms-managment.php?error=info_required'); exit;
        }
        try {
            $farmerUser = $pdo->prepare("SELECT u.user_id FROM qw_farm f JOIN qw_farmer fr ON f.farmer_id=fr.farmer_id JOIN qw_user u ON fr.user_id=u.user_id WHERE f.farm_id=?");
            $farmerUser->execute([$farm_id]);
            $farmerUserId = $farmerUser->fetchColumn();
            if ($farmerUserId) {
                $pdo->prepare("INSERT INTO qw_notification (user_id,notif_type,title,message,entity_type,entity_id,created_at) VALUES (?,'farm_review','طلب معلومات إضافية 📋',?,'farm',?,NOW())")
                    ->execute([$farmerUserId, 'يرجى تزويدنا بمعلومات إضافية: ' . $moreinfo, $farm_id]);
            }
        } catch(Exception $e) {}
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id,created_at) VALUES (?,'request_more_info','farm',?,NOW())")
                ->execute([$_SESSION['user_id'], $farm_id]);
        } catch(Exception $e) {}
        header('Location: farms-managment.php?success=info_sent'); exit;
    }

    // ============================================================
    // ✅ اعتماد أو رفض أو تعطيل مع سبب إجباري
    // ============================================================
    $valid = ['approved','rejected','deactivated'];
    if ($farm_id && in_array($decision, $valid)) {

        // ✅ سبب إجباري عند الرفض والتعطيل
        if (in_array($decision, ['rejected','deactivated']) && empty($reason)) {
            header('Location: farms-managment.php?error=reason_required'); exit;
        }

        $pdo->prepare("UPDATE qw_farm SET farm_status=?, approved_by=? WHERE farm_id=?")
            ->execute([$decision, $_SESSION['user_id'], $farm_id]);

        // ✅ تسجيل مع timestamp و admin ID
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id,created_at) VALUES (?,?,'farm',?,NOW())")
                ->execute([$_SESSION['user_id'], $decision . '_farm', $farm_id]);
        } catch(Exception $e) {}

        // ✅ إشعار للمزارع
        try {
            $farmerUser = $pdo->prepare("SELECT u.user_id FROM qw_farm f JOIN qw_farmer fr ON f.farmer_id=fr.farmer_id JOIN qw_user u ON fr.user_id=u.user_id WHERE f.farm_id=?");
            $farmerUser->execute([$farm_id]);
            $farmerUserId = $farmerUser->fetchColumn();
            if ($farmerUserId) {
                if ($decision === 'approved') {
                    $title   = 'تم اعتماد مزرعتك ✅';
                    $message = 'تهانينا! تم اعتماد مزرعتك وأصبحت ظاهرة للمستثمرين.';
                } elseif ($decision === 'rejected') {
                    $title   = 'تم رفض مزرعتك ❌';
                    $message = 'عذراً، تم رفض مزرعتك. السبب: ' . $reason;
                } else {
                    $title   = 'تم تعطيل مزرعتك ⏸';
                    $message = 'تم تعطيل مزرعتك مؤقتاً. السبب: ' . $reason;
                }
                $pdo->prepare("INSERT INTO qw_notification (user_id,notif_type,title,message,entity_type,entity_id,created_at) VALUES (?,'farm_review',?,?,'farm',?,NOW())")
                    ->execute([$farmerUserId, $title, $message, $farm_id]);
            }
        } catch(Exception $e) {}
    }

    header('Location: farms-managment.php'); exit;
}

$farms = $pdo->query("
    SELECT f.*, u.first_name, u.last_name
    FROM qw_farm f
    JOIN qw_farmer fr ON f.farmer_id = fr.farmer_id
    JOIN qw_user u    ON fr.user_id  = u.user_id
    ORDER BY f.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المزارع - قنوان</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .moreinfo-box, .edit-box { margin-top:10px; display:none; background:#f9fafb; padding:16px; border-radius:10px; border:1px solid #e5e7eb; }
        .moreinfo-box textarea, .edit-box textarea { width:100%; padding:10px; border-radius:8px; border:1px solid #d1d5db; font-size:14px; resize:vertical; }
        .edit-box input[type=text], .edit-box input[type=number] { width:100%; padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; font-size:14px; margin-bottom:8px; box-sizing:border-box; }
        .edit-box label { font-size:13px; color:#6b7280; margin-bottom:4px; display:block; }
        .alert-error   { background:#fee2e2; color:#dc2626; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-weight:600; }
        .alert-success { background:#dcfce7; color:#16a34a; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-weight:600; }
    </style>
</head>
<body>
<nav>
    <a href="admin.php" class="nav-back">← لوحة الإدارة</a>
    <div class="nav-links">
        <a href="admin.php"              class="nav-link">لوحة الإدارة</a>
        <a href="users-managment.php"    class="nav-link">المستخدمون</a>
        <a href="farms-managment.php"    class="nav-link active">المزارع</a>
        <a href="farmer-verification.php" class="nav-link">التوثيق</a>
        <a href="logout.php"             class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='admin.php'">
        <!-- ✅ مسار الصورة حسب نسختها -->
        <img class="logo-img" src="images\logo.png" alt="قنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>إدارة المزارع</h2>
        <p>مراجعة المزارع واعتمادها وتعديلها وحذفها.</p>
    </section>

    <!-- رسائل الخطأ والنجاح -->
    <?php if (isset($_GET['error'])): ?>
        <?php $errMap = ['reason_required'=>'يجب كتابة سبب الرفض أو التعطيل','delete_reason_required'=>'يجب كتابة سبب الحذف','info_required'=>'يجب كتابة المعلومات المطلوبة','name_required'=>'اسم المزرعة إجباري']; ?>
        <div class="alert-error">⚠️ <?= $errMap[$_GET['error']] ?? 'خطأ غير معروف' ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['success'])): ?>
        <?php $succMap = ['edited'=>'تم تعديل بيانات المزرعة بنجاح','deleted'=>'تم حذف المزرعة نهائياً','info_sent'=>'تم إرسال طلب المعلومات للمزارع']; ?>
        <div class="alert-success">✅ <?= $succMap[$_GET['success']] ?? 'تمت العملية بنجاح' ?></div>
    <?php endif; ?>

    <section class="admin-toolbar">
        <input type="text" id="farmSearch" placeholder="ابحث باسم المزرعة أو اسم المالك" oninput="filterFarms()">
        <select id="statusFilter" onchange="filterFarms()">
            <option value="all">كل الحالات</option>
            <option value="pending">قيد المراجعة</option>
            <option value="approved">معتمدة</option>
            <option value="rejected">مرفوضة</option>
            <option value="deactivated">معطلة</option>
        </select>
    </section>

    <section class="admin-cards-list" id="farmsList">
    <?php
    $statusLabel = ['pending'=>'قيد المراجعة','approved'=>'معتمدة','rejected'=>'مرفوضة','deactivated'=>'معطلة'];
    foreach ($farms as $f):
    ?>
        <div class="admin-record-card" data-status="<?= $f['farm_status'] ?>"
             data-name="<?= strtolower($f['name'] . ' ' . $f['first_name'] . ' ' . $f['last_name']) ?>">

            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3>🌴 <?= htmlspecialchars($f['name']) ?></h3>
                    <p>👤 المالك: <?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></p>
                    <p>📍 المنطقة: <?= htmlspecialchars($f['region']) ?></p>
                    <p>📐 المساحة: <?= number_format($f['total_area_sqm'], 0) ?> م²</p>
                    <p>🌿 نوع النخيل: <?= htmlspecialchars($f['palm_type']) ?> / <?= htmlspecialchars($f['date_type']) ?></p>
                    <?php if ($f['description']): ?>
                        <p style="font-size:13px;color:var(--text-faint);margin-top:6px;"><?= htmlspecialchars(mb_substr($f['description'],0,120)) ?>...</p>
                    <?php endif; ?>
                </div>
                <span class="status-badge status-<?= $f['farm_status'] ?>"><?= $statusLabel[$f['farm_status']] ?? $f['farm_status'] ?></span>
            </div>

            <!-- ✅ timestamp و admin ID -->
            <p style="font-size:12px;color:var(--text-faint);margin-top:8px;">
                📅 <?= date('Y-m-d H:i', strtotime($f['created_at'])) ?> — Admin ID: <?= $_SESSION['user_id'] ?>
            </p>

            <div class="admin-card-actions" style="flex-wrap:wrap;gap:8px;">

                <!-- اعتماد -->
                <?php if ($f['farm_status'] !== 'approved'): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="farm_id"  value="<?= $f['farm_id'] ?>">
                    <input type="hidden" name="decision" value="approved">
                    <button class="admin-action-btn btn-approve">✅ اعتماد</button>
                </form>
                <?php endif; ?>

                <!-- ✅ رفض مع سبب إجباري -->
                <?php if ($f['farm_status'] !== 'rejected'): ?>
                <form method="POST" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="farm_id"  value="<?= $f['farm_id'] ?>">
                    <input type="hidden" name="decision" value="rejected">
                    <input type="text" name="reason" id="reason_<?= $f['farm_id'] ?>"
                           placeholder="سبب الرفض (إجباري)" class="form-input"
                           style="min-width:180px;padding:8px 12px;margin:0;">
                    <button type="submit" class="admin-action-btn btn-danger"
                            onclick="var r=document.getElementById('reason_<?= $f['farm_id'] ?>').value.trim();if(!r){alert('يجب كتابة سبب الرفض');return false;}return confirm('تأكيد رفض المزرعة؟');">
                        ❌ رفض</button>
                </form>
                <?php endif; ?>

                <!-- ✅ تعديل -->
                <button class="admin-action-btn btn-secondary"
                        onclick="document.getElementById('edit_<?= $f['farm_id'] ?>').style.display='block'">
                    ✏️ تعديل
                </button>
                <div id="edit_<?= $f['farm_id'] ?>" class="edit-box">
                    <form method="POST">
                        <input type="hidden" name="farm_id"  value="<?= $f['farm_id'] ?>">
                        <input type="hidden" name="decision" value="edit">
                        <label>اسم المزرعة</label>
                        <input type="text" name="edit_name" value="<?= htmlspecialchars($f['name']) ?>" required>
                        <label>الوصف</label>
                        <textarea name="edit_description" rows="3"><?= htmlspecialchars($f['description'] ?? '') ?></textarea>
                        <label>المساحة (م²)</label>
                        <input type="number" name="edit_area" value="<?= $f['total_area_sqm'] ?>" min="1">
                        <div style="display:flex;gap:8px;margin-top:8px;">
                            <button type="submit" class="admin-action-btn btn-approve">💾 حفظ</button>
                            <button type="button" class="admin-action-btn btn-secondary"
                                    onclick="document.getElementById('edit_<?= $f['farm_id'] ?>').style.display='none'">إلغاء</button>
                        </div>
                    </form>
                </div>

                <!-- ✅ تعطيل مع سبب إجباري -->
                <?php if ($f['farm_status'] !== 'deactivated'): ?>
                <form method="POST" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="farm_id"  value="<?= $f['farm_id'] ?>">
                    <input type="hidden" name="decision" value="deactivated">
                    <input type="text" name="reason" id="deact_<?= $f['farm_id'] ?>"
                           placeholder="سبب التعطيل (إجباري)" class="form-input"
                           style="min-width:180px;padding:8px 12px;margin:0;">
                    <button type="submit" class="admin-action-btn btn-secondary"
                            onclick="var r=document.getElementById('deact_<?= $f['farm_id'] ?>').value.trim();if(!r){alert('يجب كتابة سبب التعطيل');return false;}return confirm('تأكيد تعطيل المزرعة؟');">
                        ⏸ تعطيل</button>
                </form>
                <?php endif; ?>

                <!-- ✅ حذف نهائي مع سبب إجباري -->
                <form method="POST" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="farm_id"  value="<?= $f['farm_id'] ?>">
                    <input type="hidden" name="decision" value="delete">
                    <input type="text" name="reason" id="del_<?= $f['farm_id'] ?>"
                           placeholder="سبب الحذف (إجباري)" class="form-input"
                           style="min-width:180px;padding:8px 12px;margin:0;">
                    <button type="submit" class="admin-action-btn btn-danger"
                            onclick="var r=document.getElementById('del_<?= $f['farm_id'] ?>').value.trim();if(!r){alert('يجب كتابة سبب الحذف');return false;}return confirm('تحذير: الحذف نهائي. متأكد؟');">
                        🗑️ حذف نهائي</button>
                </form>

                <!-- ✅ طلب معلومات إضافية (للمعلق فقط) -->
                <?php if ($f['farm_status'] === 'pending'): ?>
                <button class="admin-action-btn btn-secondary"
                        onclick="document.getElementById('moreinfo_<?= $f['farm_id'] ?>').style.display='block'">
                    📋 طلب معلومات إضافية
                </button>
                <div id="moreinfo_<?= $f['farm_id'] ?>" class="moreinfo-box">
                    <form method="POST">
                        <input type="hidden" name="farm_id"  value="<?= $f['farm_id'] ?>">
                        <input type="hidden" name="decision" value="moreinfo">
                        <textarea name="moreinfo" rows="3" placeholder="اكتب المعلومات المطلوبة..."></textarea>
                        <button type="submit" class="admin-action-btn btn-primary" style="margin-top:8px;">📤 إرسال</button>
                    </form>
                </div>
                <?php endif; ?>

            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($farms)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-faint);">لا توجد مزارع مسجلة بعد.</div>
    <?php endif; ?>
    </section>
    <div style="color:var(--text-faint);font-size:13px;margin-top:12px;text-align:left;">إجمالي المزارع: <?= count($farms) ?></div>
</main>

<script>
function filterFarms() {
    const q      = document.getElementById('farmSearch').value.toLowerCase();
    const status = document.getElementById('statusFilter').value;
    document.querySelectorAll('#farmsList .admin-record-card').forEach(card => {
        const matchName   = card.dataset.name.includes(q);
        const matchStatus = (status === 'all' || card.dataset.status === status);
        card.style.display = (matchName && matchStatus) ? '' : 'none';
    });
}
</script>
</body>
</html><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المزارع - قنوان</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<nav>
    <a href="admin.php" class="nav-back">← لوحة الإدارة</a>
    <div class="nav-links">
        <a href="admin.php"              class="nav-link">لوحة الإدارة</a>
        <a href="users-managment.php"    class="nav-link">المستخدمون</a>
        <a href="farms-managment.php"    class="nav-link active">المزارع</a>
        <a href="farmer-verification.php" class="nav-link">التوثيق</a>
        <a href="logout.php"             class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='admin.php'">
        <img class="logo-img" src="images\logo.png" alt="قنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>إدارة المزارع</h2>
        <p>مراجعة المزارع واعتمادها وتعديل حالتها.</p>
    </section>

    <section class="admin-toolbar">
        <input type="text" id="farmSearch" placeholder="ابحث باسم المزرعة أو اسم المالك" oninput="filterFarms()">
        <select id="statusFilter" onchange="filterFarms()">
            <option value="all">كل الحالات</option>
            <option value="pending">قيد المراجعة</option>
            <option value="approved">معتمدة</option>
            <option value="rejected">مرفوضة</option>
            <option value="deactivated">معطلة</option>
        </select>
    </section>

    <section class="admin-cards-list" id="farmsList">
    <?php
    $statusLabel = ['pending'=>'قيد المراجعة','approved'=>'معتمدة','rejected'=>'مرفوضة','deactivated'=>'معطلة'];
    foreach ($farms as $f):
    ?>
        <div class="admin-record-card" data-status="<?= $f['farm_status'] ?>"
             data-name="<?= strtolower($f['name'] . ' ' . $f['first_name'] . ' ' . $f['last_name']) ?>">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3>🌴 <?= htmlspecialchars($f['name']) ?></h3>
                    <p>👤 المالك: <?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></p>
                    <p>📍 المنطقة: <?= htmlspecialchars($f['region']) ?></p>
                    <p>📐 المساحة: <?= number_format($f['total_area_sqm'], 0) ?> م²</p>
                    <p>🌿 نوع النخيل: <?= htmlspecialchars($f['palm_type']) ?> / <?= htmlspecialchars($f['date_type']) ?></p>
                    <?php if ($f['description']): ?>
                        <p style="font-size:13px;color:var(--text-faint);margin-top:6px;"><?= htmlspecialchars(mb_substr($f['description'],0,120)) ?>...</p>
                    <?php endif; ?>
                </div>
                <span class="status-badge status-<?= $f['farm_status'] ?>"><?= $statusLabel[$f['farm_status']] ?? $f['farm_status'] ?></span>
            </div>
            <p style="font-size:12px;color:var(--text-faint);margin-top:8px;">📅 <?= date('Y-m-d', strtotime($f['created_at'])) ?></p>

            <div class="admin-card-actions">
                <?php if ($f['farm_status'] !== 'approved'): ?>
                <form method="POST">
                    <input type="hidden" name="farm_id" value="<?= $f['farm_id'] ?>">
                    <input type="hidden" name="decision" value="approved">
                    <button class="admin-action-btn btn-approve">✅ اعتماد</button>
                </form>
                <?php endif; ?>
                <?php if ($f['farm_status'] !== 'rejected'): ?>
                <form method="POST" onsubmit="return confirm('تأكيد رفض المزرعة؟')">
                    <input type="hidden" name="farm_id" value="<?= $f['farm_id'] ?>">
                    <input type="hidden" name="decision" value="rejected">
                    <button class="admin-action-btn btn-danger">❌ رفض</button>
                </form>
                <?php endif; ?>
                <?php if ($f['farm_status'] !== 'deactivated'): ?>
                <form method="POST" onsubmit="return confirm('تأكيد تعطيل المزرعة؟')">
                    <input type="hidden" name="farm_id" value="<?= $f['farm_id'] ?>">
                    <input type="hidden" name="decision" value="deactivated">
                    <button class="admin-action-btn btn-secondary">⏸ تعطيل</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($farms)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-faint);">لا توجد مزارع مسجلة بعد.</div>
    <?php endif; ?>
    </section>
    <div style="color:var(--text-faint);font-size:13px;margin-top:12px;text-align:left;">إجمالي المزارع: <?= count($farms) ?></div>
</main>

<script>
function filterFarms() {
    const q      = document.getElementById('farmSearch').value.toLowerCase();
    const status = document.getElementById('statusFilter').value;
    document.querySelectorAll('#farmsList .admin-record-card').forEach(card => {
        const matchName   = card.dataset.name.includes(q);
        const matchStatus = (status === 'all' || card.dataset.status === status);
        card.style.display = (matchName && matchStatus) ? '' : 'none';
    });
}
</script>
</body>
</html>
