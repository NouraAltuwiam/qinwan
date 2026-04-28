<?php
// ============================================================
// قِنوان — users-managment.php  (US-Admin-01)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();

// ✅ إضافة عمود status لو ما موجود
try {
    $pdo->exec("ALTER TABLE qw_user ADD COLUMN IF NOT EXISTS status ENUM('active','suspended') NOT NULL DEFAULT 'active'");
} catch(Exception $e) {}

// ============================================================
// ✅ معالجة suspend/activate - يحدث الـ DB فعلاً
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($uid && in_array($action, ['suspend','activate'])) {
        $newStatus = ($action === 'suspend') ? 'suspended' : 'active';

        // ✅ تحديث الـ status فعلاً في الـ DB
        $pdo->prepare("UPDATE qw_user SET status=? WHERE user_id=?")
            ->execute([$newStatus, $uid]);

        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id,created_at) VALUES (?,?,'user',?,NOW())")
                ->execute([$_SESSION['user_id'], $action, $uid]);
        } catch(Exception $e) {}
    }

    header('Location: users-managment.php'); exit;
}

// ✅ جلب المستخدمين مع الـ status
$users = $pdo->query("
    SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone, u.role, u.created_at,
           COALESCE(u.status, 'active') AS status,
           COALESCE(fr.verification_status, '-') AS verification_status
    FROM qw_user u
    LEFT JOIN qw_farmer fr ON u.user_id = fr.user_id
    ORDER BY u.created_at DESC
")->fetchAll();

// ✅ جلب activity logs لكل مستخدم
$activityMap = [];
try {
    $actLogs = $pdo->query("
        SELECT al.user_id, al.action_type, al.entity_type, al.entity_id, al.created_at
        FROM qw_activity_log al
        ORDER BY al.created_at DESC
    ")->fetchAll();
    foreach ($actLogs as $log) {
        $activityMap[$log['user_id']][] = $log;
    }
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المستخدمين - قنوان</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ✅ Modal Profile */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center; }
        .modal-overlay.active { display:flex; }
        .modal-box { background:#fff; border-radius:16px; padding:28px 32px; max-width:620px; width:95%; max-height:85vh; overflow-y:auto; direction:rtl; }
        .modal-box h3 { font-size:1.2rem; font-weight:700; margin-bottom:20px; color:#1a1a1a; border-bottom:2px solid #f3f4f6; padding-bottom:12px; }
        .profile-row { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #f3f4f6; font-size:0.92rem; }
        .profile-row:last-child { border-bottom:none; }
        .profile-label { color:#6b7280; font-weight:600; min-width:140px; }
        .profile-value { color:#1a1a1a; }
        .activity-log-section { margin-top:20px; border-top:2px solid #f3f4f6; padding-top:16px; }
        .activity-log-section h4 { font-size:1rem; font-weight:700; margin-bottom:12px; color:#374151; }
        .log-item { display:flex; justify-content:space-between; font-size:12px; padding:6px 10px; border-radius:6px; margin-bottom:4px; background:#f9fafb; }
        .log-action { color:#374151; font-weight:600; }
        .log-time   { color:#9ca3af; }
        .btn-close  { background:#6b7280; color:#fff; border:none; border-radius:8px; padding:10px 24px; font-size:0.95rem; cursor:pointer; margin-top:20px; width:100%; }
        /* ✅ Status badges */
        .status-active    { background:#dcfce7; color:#16a34a; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
        .status-suspended { background:#fee2e2; color:#dc2626; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
    </style>
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
        <!-- ✅ مسار الصورة حسب نسختها -->
        <img class="logo-img" src="images\logo.png" alt="قنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>إدارة المستخدمين</h2>
        <p>عرض جميع المستثمرين والمزارعين مع إمكانية البحث والتصفية وعرض الملف الكامل.</p>
    </section>

    <!-- ✅ TC2: Search + Filter -->
    <section class="admin-toolbar">
        <input type="text" id="userSearch" placeholder="ابحث بالاسم أو البريد" oninput="filterUsers()">
        <select id="roleFilter" onchange="filterUsers()">
            <option value="all">كل الأدوار</option>
            <option value="farmer">مزارع</option>
            <option value="investor">مستثمر</option>
            <option value="admin">مدير</option>
        </select>
        <!-- ✅ TC1: Filter بالـ status -->
        <select id="statusFilter" onchange="filterUsers()">
            <option value="all">كل الحالات</option>
            <option value="active">نشط</option>
            <option value="suspended">موقوف</option>
        </select>
        <select id="verifyFilter" onchange="filterUsers()">
            <option value="all">كل التوثيق</option>
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
                    <!-- ✅ TC1: عمود الحالة -->
                    <th>الحالة</th>
                    <th>حالة التوثيق</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr data-role="<?= $u['role'] ?>"
                    data-verify="<?= $u['verification_status'] ?>"
                    data-status="<?= $u['status'] ?>"
                    data-name="<?= strtolower($u['first_name'] . ' ' . $u['last_name'] . ' ' . $u['email']) ?>">
                    <td><?= $u['user_id'] ?></td>
                    <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
                    <td style="direction:ltr;text-align:left;"><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['phone']) ?></td>
                    <td>
                        <span class="status-badge <?= $u['role'] === 'admin' ? 'status-accepted' : ($u['role'] === 'farmer' ? 'status-pending' : 'status-active') ?>">
                            <?php $roleMap=['farmer'=>'مزارع','investor'=>'مستثمر','admin'=>'مدير']; echo $roleMap[$u['role']] ?? $u['role']; ?>
                        </span>
                    </td>
                    <td><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                    <!-- ✅ TC1: عرض الـ status -->
                    <td>
                        <?php if ($u['status'] === 'suspended'): ?>
                            <span class="status-suspended">🔴 موقوف</span>
                        <?php else: ?>
                            <span class="status-active">🟢 نشط</span>
                        <?php endif; ?>
                    </td>
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
                    <td style="display:flex;gap:6px;flex-wrap:wrap;">
                        <!-- ✅ TC2: زر الملف الكامل -->
                        <button class="admin-action-btn btn-secondary"
                                style="font-size:12px;padding:6px 10px;"
                                onclick="showProfile(<?= $u['user_id'] ?>)">
                            👤 الملف
                        </button>
                        <?php if ($u['role'] !== 'admin'): ?>
                            <!-- ✅ TC1: زر Suspend/Activate -->
                            <?php if ($u['status'] === 'active'): ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('تأكيد إيقاف هذا المستخدم؟')">
                                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                <input type="hidden" name="action"  value="suspend">
                                <button type="submit" class="admin-action-btn btn-danger" style="font-size:12px;padding:6px 10px;">🔴 إيقاف</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('تأكيد تفعيل هذا المستخدم؟')">
                                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                <input type="hidden" name="action"  value="activate">
                                <button type="submit" class="admin-action-btn btn-approve" style="font-size:12px;padding:6px 10px;">🟢 تفعيل</button>
                            </form>
                            <?php endif; ?>
                            <a href="mailto:<?= htmlspecialchars($u['email']) ?>"
                               class="admin-action-btn btn-secondary"
                               style="font-size:12px;padding:6px 10px;text-decoration:none;">📧 تواصل</a>
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

<!-- ✅ TC2: Modal الملف الكامل + النشاط -->
<div class="modal-overlay" id="profileModal">
    <div class="modal-box">
        <h3>👤 الملف الكامل للمستخدم</h3>
        <div id="profileContent"></div>
        <div class="activity-log-section">
            <h4>📋 سجل النشاط</h4>
            <div id="activityContent"></div>
        </div>
        <button class="btn-close" onclick="closeModal()">✖ إغلاق</button>
    </div>
</div>

<script>
const usersData = <?= json_encode(array_combine(
    array_column($users, 'user_id'),
    $users
), JSON_UNESCAPED_UNICODE) ?>;

const activityData = <?= json_encode($activityMap, JSON_UNESCAPED_UNICODE) ?>;

const roleMap   = {farmer:'مزارع', investor:'مستثمر', admin:'مدير'};
const statusMap = {active:'🟢 نشط', suspended:'🔴 موقوف'};
const verifyMap = {verified:'✅ موثق', pending:'⏳ قيد المراجعة', rejected:'❌ مرفوض', '-':'—'};
const actionLabels = {
    login:'تسجيل دخول', register:'تسجيل حساب',
    submit_request:'تقديم طلب استثمار', accept_request:'قبول طلب',
    cancel_request:'إلغاء طلب', add_farm:'إضافة مزرعة',
    edit_farm:'تعديل مزرعة', post_update:'نشر تحديث',
    submit_complaint:'تقديم شكوى', suspend:'إيقاف مستخدم',
    activate:'تفعيل مستخدم',
};

function showProfile(uid) {
    const u = usersData[uid];
    if (!u) return;

    document.getElementById('profileContent').innerHTML = `
        <div class="profile-row"><span class="profile-label">الاسم الكامل</span><span class="profile-value">${u.first_name} ${u.last_name}</span></div>
        <div class="profile-row"><span class="profile-label">البريد الإلكتروني</span><span class="profile-value" style="direction:ltr;">${u.email}</span></div>
        <div class="profile-row"><span class="profile-label">الهاتف</span><span class="profile-value">${u.phone}</span></div>
        <div class="profile-row"><span class="profile-label">الدور</span><span class="profile-value">${roleMap[u.role] ?? u.role}</span></div>
        <div class="profile-row"><span class="profile-label">الحالة</span><span class="profile-value">${statusMap[u.status] ?? u.status}</span></div>
        <div class="profile-row"><span class="profile-label">حالة التوثيق</span><span class="profile-value">${verifyMap[u.verification_status] ?? u.verification_status}</span></div>
        <div class="profile-row"><span class="profile-label">تاريخ التسجيل</span><span class="profile-value">${u.created_at ? u.created_at.substring(0,10) : '—'}</span></div>
    `;

    const logs = activityData[uid] ?? [];
    if (logs.length === 0) {
        document.getElementById('activityContent').innerHTML =
            '<div style="color:#9ca3af;font-size:13px;text-align:center;padding:16px;">لا يوجد نشاط مسجل</div>';
    } else {
        let logHtml = '';
        logs.slice(0, 10).forEach(log => {
            const action = actionLabels[log.action_type] ?? log.action_type;
            const entity = log.entity_type ? ` — ${log.entity_type} #${log.entity_id}` : '';
            const time   = log.created_at ? log.created_at.substring(0, 16) : '';
            logHtml += `<div class="log-item"><span class="log-action">${action}${entity}</span><span class="log-time">${time}</span></div>`;
        });
        if (logs.length > 10) {
            logHtml += `<div style="text-align:center;color:#9ca3af;font-size:12px;margin-top:8px;">... و ${logs.length - 10} إجراء إضافي</div>`;
        }
        document.getElementById('activityContent').innerHTML = logHtml;
    }

    document.getElementById('profileModal').classList.add('active');
}

function closeModal() {
    document.getElementById('profileModal').classList.remove('active');
}

document.getElementById('profileModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function filterUsers() {
    const q       = document.getElementById('userSearch').value.toLowerCase();
    const role    = document.getElementById('roleFilter').value;
    const status  = document.getElementById('statusFilter').value;
    const verify  = document.getElementById('verifyFilter').value;
    document.querySelectorAll('#usersTable tbody tr').forEach(row => {
        const matchName   = row.dataset.name.includes(q);
        const matchRole   = (role   === 'all' || row.dataset.role   === role);
        const matchStatus = (status === 'all' || row.dataset.status === status);
        const matchVerify = (verify === 'all' || row.dataset.verify === verify);
        row.style.display = (matchName && matchRole && matchStatus && matchVerify) ? '' : 'none';
    });
}
</script>
</body>
</html><head>
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
