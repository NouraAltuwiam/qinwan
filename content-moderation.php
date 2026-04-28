<?php
// ============================================================
// قِنوان — content-moderation.php  (US-Admin-08, US-Admin-11)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act       = $_POST['act']       ?? '';
    $update_id = (int)($_POST['update_id'] ?? 0);
    $reason    = trim($_POST['reason'] ?? '');

    // ============================================================
    // قبول التحديث
    // ============================================================
    if ($act === 'approve' && $update_id) {
        $pdo->prepare("UPDATE qw_farm_update SET status='approved' WHERE update_id=?")
            ->execute([$update_id]);
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id,created_at) VALUES (?,'approve_content','farm_update',?,NOW())")
                ->execute([$_SESSION['user_id'], $update_id]);
        } catch (Exception $e) {}
        $_SESSION['flash']      = 'تم قبول التحديث بنجاح.';
        $_SESSION['flash_type'] = 'success';
    }

    // ============================================================
    // رفض التحديث
    // ============================================================
    if ($act === 'reject' && $update_id) {
        $pdo->prepare("UPDATE qw_farm_update SET status='rejected' WHERE update_id=?")
            ->execute([$update_id]);
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id,created_at) VALUES (?,'reject_content','farm_update',?,NOW())")
                ->execute([$_SESSION['user_id'], $update_id]);
        } catch (Exception $e) {}
        $_SESSION['flash']      = 'تم رفض التحديث.';
        $_SESSION['flash_type'] = 'success';
    }

    // ============================================================
    // ✅ تعديل المحتوى مع سبب إجباري + إشعار + TC3
    // ============================================================
    if ($act === 'edit' && $update_id) {
        $new_content = trim($_POST['content'] ?? '');

        // ✅ TC2: سبب التعديل إجباري
        if (empty($reason)) {
            $_SESSION['flash']      = 'يجب كتابة سبب التعديل قبل الحفظ.';
            $_SESSION['flash_type'] = 'error';
            header('Location: content-moderation.php'); exit;
        }

        if (empty($new_content)) {
            $_SESSION['flash']      = 'يجب كتابة المحتوى الجديد.';
            $_SESSION['flash_type'] = 'error';
            header('Location: content-moderation.php'); exit;
        }

        // ✅ TC3: المرفوض لا يُعدَّل
        $checkStmt = $pdo->prepare("SELECT status FROM qw_farm_update WHERE update_id=?");
        $checkStmt->execute([$update_id]);
        $current = $checkStmt->fetch();

        if (!$current) {
            $_SESSION['flash']      = 'التحديث غير موجود.';
            $_SESSION['flash_type'] = 'error';
            header('Location: content-moderation.php'); exit;
        }

        if ($current['status'] === 'rejected') {
            $_SESSION['flash']      = '⚠️ لا يمكن تعديل المحتوى المرفوض.';
            $_SESSION['flash_type'] = 'error';
            header('Location: content-moderation.php'); exit;
        }

        // ✅ TC1: تحديث المحتوى
        $pdo->prepare("UPDATE qw_farm_update SET content=? WHERE update_id=?")
            ->execute([$new_content, $update_id]);

        // ✅ TC1: تسجيل في activity_log
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id,created_at) VALUES (?,'edit_content','farm_update',?,NOW())")
                ->execute([$_SESSION['user_id'], $update_id]);
        } catch (Exception $e) {}

        // ✅ TC1: إشعار للمزارع بالتعديل
        try {
            $farmerUser = $pdo->prepare("
                SELECT u.user_id FROM qw_farm_update fu
                JOIN qw_farm f    ON fu.farm_id   = f.farm_id
                JOIN qw_farmer fr ON f.farmer_id  = fr.farmer_id
                JOIN qw_user u    ON fr.user_id   = u.user_id
                WHERE fu.update_id = ?
            ");
            $farmerUser->execute([$update_id]);
            $farmerUserId = $farmerUser->fetchColumn();
            if ($farmerUserId) {
                $pdo->prepare("
                    INSERT INTO qw_notification (user_id,notif_type,title,message,entity_type,entity_id,created_at)
                    VALUES (?,'content_edited','✏️ تم تعديل تحديثك',?,'farm_update',?,NOW())
                ")->execute([$farmerUserId, 'تم تعديل تحديث مزرعتك من قبل الإدارة. السبب: ' . $reason, $update_id]);
            }
        } catch (Exception $e) {}

        $_SESSION['flash']      = 'تم تعديل المحتوى بنجاح وإشعار المزارع.';
        $_SESSION['flash_type'] = 'success';
    }

    // ============================================================
    // ✅ حذف مع سبب إجباري + إشعار للمزارع
    // ============================================================
    if ($act === 'delete' && $update_id) {

        // ✅ سبب الحذف إجباري
        if (empty($reason)) {
            $_SESSION['flash']      = 'يجب كتابة سبب الحذف قبل المتابعة.';
            $_SESSION['flash_type'] = 'error';
            header('Location: content-moderation.php'); exit;
        }

        // ✅ إشعار للمزارع قبل الحذف
        try {
            $farmerUser = $pdo->prepare("
                SELECT u.user_id FROM qw_farm_update fu
                JOIN qw_farm f    ON fu.farm_id   = f.farm_id
                JOIN qw_farmer fr ON f.farmer_id  = fr.farmer_id
                JOIN qw_user u    ON fr.user_id   = u.user_id
                WHERE fu.update_id = ?
            ");
            $farmerUser->execute([$update_id]);
            $farmerUserId = $farmerUser->fetchColumn();
            if ($farmerUserId) {
                $pdo->prepare("
                    INSERT INTO qw_notification (user_id,notif_type,title,message,entity_type,entity_id,created_at)
                    VALUES (?,'content_deleted','🗑️ تم حذف تحديثك',?,'farm_update',?,NOW())
                ")->execute([$farmerUserId, 'تم حذف تحديث مزرعتك من قبل الإدارة. السبب: ' . $reason, $update_id]);
            }
        } catch (Exception $e) {}

        // ✅ تسجيل في activity_log
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id,created_at) VALUES (?,'delete_content','farm_update',?,NOW())")
                ->execute([$_SESSION['user_id'], $update_id]);
        } catch (Exception $e) {}

        $pdo->prepare("DELETE FROM qw_farm_update WHERE update_id=?")->execute([$update_id]);

        $_SESSION['flash']      = 'تم حذف التحديث وإشعار المزارع بالسبب.';
        $_SESSION['flash_type'] = 'success';
    }

    header('Location: content-moderation.php'); exit;
}

// ✅ جلب كل التحديثات مع الـ status
$updates = $pdo->query("
    SELECT fu.update_id, fu.content, fu.created_at, fu.status, fu.media_urls, fu.media_type,
           f.name AS farm_name, f.farm_id,
           u.first_name, u.last_name
    FROM qw_farm_update fu
    JOIN qw_farm f    ON fu.farm_id  = f.farm_id
    JOIN qw_farmer fr ON f.farmer_id = fr.farmer_id
    JOIN qw_user u    ON fr.user_id  = u.user_id
    ORDER BY fu.created_at DESC
")->fetchAll();

$flash      = $_SESSION['flash']      ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash'], $_SESSION['flash_type']);

$statusLabel = ['pending'=>'معلق','approved'=>'مقبول','rejected'=>'مرفوض'];
$statusClass = ['pending'=>'status-pending','approved'=>'status-accepted','rejected'=>'status-rejected'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المحتوى - قنوان</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .approve-btn { background:#2e7d32;color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:14px;font-weight:700;cursor:pointer; }
        .approve-btn:hover { background:#256a2a; }
        .reject-btn  { background:#c96d5f;color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:14px;font-weight:700;cursor:pointer; }
        .reject-btn:hover  { background:#b95b4d; }
        .delete-btn  { background:#7f1d1d;color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:14px;font-weight:700;cursor:pointer; }
        .delete-btn:hover  { background:#6b1a1a; }
        .edit-btn    { background:#1d4ed8;color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:14px;font-weight:700;cursor:pointer; }
        .edit-btn:hover    { background:#1e40af; }
        .action-box { display:none;margin-top:12px;padding:14px;border-radius:10px;border:1px solid #e5e7eb;background:#f9fafb;width:100%;box-sizing:border-box; }
        .action-box.edit-box   { border-color:#bfdbfe;background:#eff6ff; }
        .action-box.delete-box { border-color:#fecaca;background:#fff8f8; }
        .action-box textarea, .action-box input[type=text] { width:100%;padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:14px;margin-bottom:8px;box-sizing:border-box;font-family:inherit; }
        .action-box textarea { resize:vertical;min-height:80px; }
        .action-box label { font-size:13px;font-weight:600;color:#374151;display:block;margin-bottom:4px; }
        .alert-success { background:#e2f1e1;border:1px solid rgba(45,95,51,0.3);border-right:4px solid var(--green-mid);color:var(--green-dark);padding:12px 16px;border-radius:8px;margin-bottom:20px; }
        .alert-error   { background:#fee2e2;color:#dc2626;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600; }
        .no-edit-notice { background:#f3f4f6;color:#6b7280;padding:8px 14px;border-radius:8px;font-size:13px;display:inline-block; }
        .status-filter-bar { display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px; }
        .filter-btn { padding:6px 16px;border-radius:20px;border:1px solid #d1d5db;background:#f9fafb;cursor:pointer;font-size:13px;font-weight:600; }
        .filter-btn.active { background:#16a34a;color:#fff;border-color:#16a34a; }
    </style>
</head>
<body>
<nav>
    <a href="admin.php" class="nav-back">← لوحة الإدارة</a>
    <div class="nav-links">
        <a href="admin.php"              class="nav-link">لوحة الإدارة</a>
        <a href="content-moderation.php" class="nav-link active">المحتوى</a>
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
        <h2>إدارة المحتوى</h2>
        <p>مراجعة تحديثات المزارع — قبول أو رفض أو تعديل أو حذف. جميع الإجراءات تُسجَّل.</p>
    </section>

    <!-- Flash Message -->
    <?php if ($flash): ?>
        <div class="alert-<?= $flash_type ?>">
            <?= $flash_type === 'success' ? '✅' : '⚠️' ?> <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <section class="admin-toolbar">
        <input type="text" id="contentSearch" placeholder="ابحث بالمزرعة أو اسم المزارع أو المحتوى" oninput="filterContent()">
    </section>

    <!-- ✅ Filter بالحالة -->
    <div class="status-filter-bar">
        <button class="filter-btn active" onclick="setFilter('all',this)">الكل (<?= count($updates) ?>)</button>
        <button class="filter-btn" onclick="setFilter('pending',this)">معلق (<?= count(array_filter($updates,fn($u)=>$u['status']==='pending')) ?>)</button>
        <button class="filter-btn" onclick="setFilter('approved',this)">مقبول (<?= count(array_filter($updates,fn($u)=>$u['status']==='approved')) ?>)</button>
        <button class="filter-btn" onclick="setFilter('rejected',this)">مرفوض (<?= count(array_filter($updates,fn($u)=>$u['status']==='rejected')) ?>)</button>
    </div>

    <section class="admin-cards-list" id="contentList">
    <?php foreach ($updates as $u): ?>
        <div class="admin-record-card"
             data-status="<?= $u['status'] ?>"
             data-name="<?= strtolower($u['farm_name'].' '.$u['first_name'].' '.$u['last_name'].' '.$u['content']) ?>">

            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;">
                    <h3>🌴 <?= htmlspecialchars($u['farm_name']) ?></h3>
                    <p style="color:var(--text-muted);font-size:13px;">
                        👤 <?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?>
                        | 📅 <?= date('Y-m-d H:i', strtotime($u['created_at'])) ?>
                    </p>
                    <!-- ✅ عرض المحتوى (يُخفى عند التعديل) -->
                    <div style="background:var(--bg-section);padding:14px;border-radius:8px;margin-top:12px;font-size:14px;line-height:1.7;" id="content-display-<?= $u['update_id'] ?>">
                        <?= nl2br(htmlspecialchars($u['content'])) ?>
                    </div>
                    <?php if (!empty($u['media_urls'])): ?>
                        <div style="font-size:13px;color:#6b7280;margin-top:8px;">📎 مرفقات (<?= htmlspecialchars($u['media_type']??'ملف') ?>)</div>
                    <?php endif; ?>
                </div>
                <span class="status-badge <?= $statusClass[$u['status']]??'' ?>"><?= $statusLabel[$u['status']]??$u['status'] ?></span>
            </div>

            <div class="admin-card-actions" style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">

                <!-- قبول ورفض للمعلق فقط -->
                <?php if ($u['status'] === 'pending'): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('قبول هذا التحديث؟')">
                    <input type="hidden" name="act" value="approve">
                    <input type="hidden" name="update_id" value="<?= $u['update_id'] ?>">
                    <button type="submit" class="approve-btn">✅ قبول</button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('رفض هذا التحديث؟')">
                    <input type="hidden" name="act" value="reject">
                    <input type="hidden" name="update_id" value="<?= $u['update_id'] ?>">
                    <button type="submit" class="reject-btn">🚫 رفض</button>
                </form>
                <?php endif; ?>

                <!-- ✅ TC1+TC3: تعديل - غير متاح للمرفوض -->
                <?php if ($u['status'] !== 'rejected'): ?>
                <button class="edit-btn" onclick="toggleBox('edit_<?= $u['update_id'] ?>'); document.getElementById('content-display-<?= $u['update_id'] ?>').style.display='none';">✏️ تعديل</button>
                <div id="edit_<?= $u['update_id'] ?>" class="action-box edit-box" style="width:100%;">
                    <form method="POST">
                        <input type="hidden" name="act"       value="edit">
                        <input type="hidden" name="update_id" value="<?= $u['update_id'] ?>">
                        <label>المحتوى الجديد *</label>
                        <textarea name="content" placeholder="اكتب المحتوى المعدّل..."><?= htmlspecialchars($u['content']) ?></textarea>
                        <!-- ✅ TC2: سبب التعديل إجباري -->
                        <label>سبب التعديل * (إجباري — سيُرسل للمزارع)</label>
                        <input type="text" name="reason" id="editReason_<?= $u['update_id'] ?>" placeholder="مثال: تعديل محتوى مخالف للسياسة">
                        <div style="display:flex;gap:8px;margin-top:4px;">
                            <button type="submit" class="edit-btn"
                                    onclick="var r=document.getElementById('editReason_<?= $u['update_id'] ?>').value.trim();if(!r){alert('يجب كتابة سبب التعديل');return false;}return confirm('تأكيد تعديل المحتوى؟');">
                                💾 حفظ
                            </button>
                            <button type="button" class="admin-action-btn btn-secondary"
                                    onclick="toggleBox('edit_<?= $u['update_id'] ?>'); document.getElementById('content-display-<?= $u['update_id'] ?>').style.display='block';">
                                إلغاء
                            </button>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <!-- ✅ TC3: المرفوض لا يمكن تعديله -->
                <span class="no-edit-notice">🚫 المحتوى المرفوض لا يمكن تعديله</span>
                <?php endif; ?>

                <!-- ✅ حذف مع سبب إجباري -->
                <button class="delete-btn" onclick="toggleBox('del_<?= $u['update_id'] ?>')">🗑️ حذف</button>
                <div id="del_<?= $u['update_id'] ?>" class="action-box delete-box" style="width:100%;">
                    <form method="POST">
                        <input type="hidden" name="act"       value="delete">
                        <input type="hidden" name="update_id" value="<?= $u['update_id'] ?>">
                        <label>سبب الحذف * (إجباري — سيُرسل للمزارع)</label>
                        <input type="text" name="reason" id="delReason_<?= $u['update_id'] ?>" placeholder="مثال: محتوى مخالف للسياسة">
                        <div style="display:flex;gap:8px;margin-top:4px;">
                            <button type="submit" class="delete-btn"
                                    onclick="var r=document.getElementById('delReason_<?= $u['update_id'] ?>').value.trim();if(!r){alert('يجب كتابة سبب الحذف');return false;}return confirm('تأكيد الحذف النهائي؟');">
                                🗑️ تأكيد
                            </button>
                            <button type="button" class="admin-action-btn btn-secondary" onclick="toggleBox('del_<?= $u['update_id'] ?>')">إلغاء</button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($updates)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-faint);">لا توجد تحديثات.</div>
    <?php endif; ?>
    </section>
    <div style="color:var(--text-faint);font-size:13px;margin-top:12px;text-align:left;">إجمالي التحديثات: <?= count($updates) ?></div>
</main>

<script>
let currentFilter = 'all';
function toggleBox(id) {
    const box = document.getElementById(id);
    box.style.display = box.style.display === 'block' ? 'none' : 'block';
}
function setFilter(status, btn) {
    currentFilter = status;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterContent();
}
function filterContent() {
    const q = document.getElementById('contentSearch').value.toLowerCase();
    document.querySelectorAll('#contentList .admin-record-card').forEach(card => {
        const matchName   = card.dataset.name.includes(q);
        const matchStatus = (currentFilter === 'all' || card.dataset.status === currentFilter);
        card.style.display = (matchName && matchStatus) ? '' : 'none';
    });
}
</script>
</body>
</html>
