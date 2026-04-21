<?php
// ============================================================
// قِنوان — content-moderation.php  (US-Admin-08, US-Admin-11)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act       = $_POST['act'] ?? '';
    $update_id = (int)($_POST['update_id'] ?? 0);
    $reason    = trim($_POST['reason'] ?? 'مخالفة إرشادات المنصة');

    if ($act === 'delete' && $update_id) {
        // Get farm info for logging before delete
        $info = $pdo->prepare("SELECT farm_id FROM qw_farm_update WHERE update_id=?");
        $info->execute([$update_id]);
        $row = $info->fetch();

        $pdo->prepare("DELETE FROM qw_farm_update WHERE update_id=?")->execute([$update_id]);
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,\'delete_content\',\'farm_update\',?)")
                ->execute([$_SESSION['user_id'], $update_id]);
        } catch(Exception $e) {}
        $_SESSION['flash'] = 'تم حذف التحديث المخالف بنجاح.';
    }

    if ($act === 'edit' && $update_id) {
        $new_content = trim($_POST['content'] ?? '');
        if ($new_content) {
            $pdo->prepare("UPDATE qw_farm_update SET content=? WHERE update_id=?")->execute([$new_content, $update_id]);
            try {
                $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,\'edit_content\',\'farm_update\',?)")
                    ->execute([$_SESSION['user_id'], $update_id]);
            } catch(Exception $e) {}
            $_SESSION['flash'] = 'تم تعديل المحتوى بنجاح.';
        }
    }
    header('Location: content-moderation.php'); exit;
}

$updates = $pdo->query("
    SELECT fu.update_id, fu.content, fu.created_at,
           f.name AS farm_name, f.farm_id,
           u.first_name, u.last_name
    FROM qw_farm_update fu
    JOIN qw_farm f    ON fu.farm_id  = f.farm_id
    JOIN qw_farmer fr ON f.farmer_id = fr.farmer_id
    JOIN qw_user u    ON fr.user_id  = u.user_id
    ORDER BY fu.created_at DESC
")->fetchAll();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المحتوى - قنوان</title>
    <link rel="stylesheet" href="style.css">
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
        <img class="logo-img" src="logo.png" alt="قنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>إدارة المحتوى</h2>
        <p>مراجعة تحديثات المزارع وحذف المخالفات أو تعديلها. يتم إشعار المزارع عند الحذف.</p>
    </section>

    <?php if ($flash): ?>
        <div style="background:#e2f1e1;border:1px solid rgba(45,95,51,0.3);border-right:4px solid var(--green-mid);color:var(--green-dark);padding:12px 16px;border-radius:8px;margin-bottom:20px;">✅ <?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <section class="admin-toolbar">
        <input type="text" id="contentSearch" placeholder="ابحث بالمزرعة أو اسم المزارع أو المحتوى" oninput="filterContent()">
    </section>

    <section class="admin-cards-list" id="contentList">
    <?php foreach ($updates as $u): ?>
        <div class="admin-record-card"
             data-name="<?= strtolower($u['farm_name'].' '.$u['first_name'].' '.$u['last_name'].' '.$u['content']) ?>">

            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;">
                    <h3>🌴 <?= htmlspecialchars($u['farm_name']) ?></h3>
                    <p style="color:var(--text-muted);font-size:13px;">👤 <?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?>
                       | 📅 <?= date('Y-m-d H:i', strtotime($u['created_at'])) ?>
                    </p>
                    <div style="background:var(--bg-section);padding:14px;border-radius:8px;margin-top:12px;font-size:14px;line-height:1.7;" id="content-display-<?= $u['update_id'] ?>">
                        <?= nl2br(htmlspecialchars($u['content'])) ?>
                    </div>
                    <!-- Edit form (hidden by default) -->
                    <div id="edit-form-<?= $u['update_id'] ?>" style="display:none;margin-top:12px;">
                        <form method="POST">
                            <input type="hidden" name="act"       value="edit">
                            <input type="hidden" name="update_id" value="<?= $u['update_id'] ?>">
                            <textarea name="content" class="form-textarea" style="width:100%;min-height:100px;"><?= htmlspecialchars($u['content']) ?></textarea>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <button type="submit" class="admin-action-btn btn-approve">💾 حفظ التعديل</button>
                                <button type="button" onclick="cancelEdit(<?= $u['update_id'] ?>)" class="admin-action-btn btn-secondary">إلغاء</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="admin-card-actions">
                <button class="admin-action-btn btn-secondary" onclick="startEdit(<?= $u['update_id'] ?>)">✏️ تعديل</button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('تأكيد حذف هذا التحديث؟ سيتم إشعار المزارع.')">
                    <input type="hidden" name="act"       value="delete">
                    <input type="hidden" name="update_id" value="<?= $u['update_id'] ?>">
                    <button type="submit" class="admin-action-btn btn-danger">🗑 حذف</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($updates)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-faint);">لا توجد تحديثات منشورة.</div>
    <?php endif; ?>
    </section>
</main>

<script>
function startEdit(id) {
    document.getElementById('content-display-' + id).style.display = 'none';
    document.getElementById('edit-form-' + id).style.display = 'block';
}
function cancelEdit(id) {
    document.getElementById('content-display-' + id).style.display = 'block';
    document.getElementById('edit-form-' + id).style.display = 'none';
}
function filterContent() {
    const q = document.getElementById('contentSearch').value.toLowerCase();
    document.querySelectorAll('#contentList .admin-record-card').forEach(card => {
        card.style.display = card.dataset.name.includes(q) ? '' : 'none';
    });
}
</script>
</body>
</html>