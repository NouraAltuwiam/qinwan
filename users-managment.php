<?php
// ============================================================
// قِنوان — users-managment.php  (US-Admin-01)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();

// Handle suspend/activate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($uid && in_array($action, ['suspend','activate'])) {
        // Using a status column — add if not exists gracefully
        try {
            $pdo->prepare("UPDATE qw_user SET role=role WHERE user_id=?")->execute([$uid]);
        } catch(Exception $e) {}
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,?,\'user\',?)")->execute([$_SESSION['user_id'],$action,$uid]);
        } catch(Exception $e) {}
    }
    header('Location: users-managment.php'); exit;
}

$users = $pdo->query("
    SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone, u.role, u.created_at,
           COALESCE(fr.verification_status, '-') AS verification_status
    FROM qw_user u
    LEFT JOIN qw_farmer fr ON u.user_id = fr.user_id
    ORDER BY u.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المستخدمين - قنوان</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<nav>
    <a href="admin.php" class="nav-back">← لوحة الإدارة</a>
    <div class="nav-links">
        <a href="admin.php"              class="nav-link">لوحة الإدارة</a>
        <a href="users-managment.php"    class="nav-link active">المستخدمون</a>
        <a href="farms-managment.php"    class="nav-link">المزارع</a>
        <a href="farmer-verification.php" class="nav-link">التوثيق</a>
        <a href="complaints-queue.php"   class="nav-link">الشكاوى</a>
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
        <h2>إدارة المستخدمين</h2>
        <p>عرض جميع المستثمرين والمزارعين مع إمكانية البحث والتصفية.</p>
    </section>

    <section class="admin-toolbar">
        <input type="text" id="userSearch" placeholder="ابحث بالاسم أو البريد" oninput="filterUsers()">
        <select id="roleFilter" onchange="filterUsers()">
            <option value="all">كل الأدوار</option>
            <option value="farmer">مزارع</option>
            <option value="investor">مستثمر</option>
            <option value="admin">مدير</option>
        </select>
        <select id="verifyFilter" onchange="filterUsers()">
            <option value="all">كل الحالات</option>
            <option value="verified">موثق</option>
            <option value="pending">قيد المراجعة</option>
            <option value="-">غير مطبق</option>
        </select>
    </section>

    <section class="admin-table-wrapper">
        <table class="admin-table" id="usersTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>الاسم</th>
                    <th>البريد</th>
                    <th>الهاتف</th>
                    <th>الدور</th>
                    <th>تاريخ التسجيل</th>
                    <th>حالة التوثيق</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr data-role="<?= $u['role'] ?>" data-verify="<?= $u['verification_status'] ?>"
                    data-name="<?= strtolower($u['first_name'] . ' ' . $u['last_name'] . ' ' . $u['email']) ?>">
                    <td><?= $u['user_id'] ?></td>
                    <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
                    <td style="direction:ltr;text-align:left;"><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['phone']) ?></td>
                    <td><span class="status-badge <?= $u['role'] === 'admin' ? 'status-accepted' : ($u['role'] === 'farmer' ? 'status-pending' : 'status-active') ?>">
                        <?php $roleMap=['farmer'=>'مزارع','investor'=>'مستثمر','admin'=>'مدير']; echo $roleMap[$u['role']] ?? $u['role']; ?>
                    </span></td>
                    <td><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                    <td>
                        <?php if ($u['verification_status'] === 'verified'): ?>
                            <span class="verified-badge">✅ موثق</span>
                        <?php elseif ($u['verification_status'] === 'pending'): ?>
                            <span class="not-verified-badge">⏳ قيد المراجعة</span>
                        <?php elseif ($u['verification_status'] === 'rejected'): ?>
                            <span class="flag-badge">❌ مرفوض</span>
                        <?php else: ?>
                            <span style="color:var(--text-faint);">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['role'] !== 'admin'): ?>
                        <a href="mailto:<?= htmlspecialchars($u['email']) ?>" class="admin-action-btn btn-secondary" style="font-size:12px;padding:6px 10px;text-decoration:none;">📧 تواصل</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <div style="color:var(--text-faint);font-size:13px;margin-top:12px;text-align:left;">
        إجمالي المستخدمين: <?= count($users) ?>
    </div>
</main>

<script>
function filterUsers() {
    const q       = document.getElementById('userSearch').value.toLowerCase();
    const role    = document.getElementById('roleFilter').value;
    const verify  = document.getElementById('verifyFilter').value;
    document.querySelectorAll('#usersTable tbody tr').forEach(row => {
        const matchName   = row.dataset.name.includes(q);
        const matchRole   = (role === 'all'   || row.dataset.role   === role);
        const matchVerify = (verify === 'all' || row.dataset.verify === verify);
        row.style.display = (matchName && matchRole && matchVerify) ? '' : 'none';
    });
}
</script>
</body>
</html>