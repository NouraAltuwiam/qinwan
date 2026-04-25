<?php
// ============================================================
// قِنوان — activity-logs.php  (US-Admin-10)
// Creates qw_activity_log table if not exists, displays real logs
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();

// Create activity log table if it does not exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS qw_activity_log (
        log_id       INT          NOT NULL AUTO_INCREMENT,
        user_id      INT          NOT NULL,
        action_type  VARCHAR(80)  NOT NULL,
        entity_type  VARCHAR(80)  DEFAULT NULL,
        entity_id    INT          DEFAULT NULL,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (log_id),
        KEY idx_user  (user_id),
        KEY idx_time  (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Seed some useful baseline logs from existing data if table is empty
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM qw_activity_log")->fetchColumn();
if ($cnt === 0) {
    // Seed from investment requests (accepted/rejected)
    $pdo->exec("
        INSERT IGNORE INTO qw_activity_log (user_id, action_type, entity_type, entity_id, created_at)
        SELECT u.user_id,
               CASE ir.req_status WHEN 'accepted' THEN 'accept_request' WHEN 'rejected' THEN 'reject_request' ELSE 'submit_request' END,
               'investment_request', ir.request_id, ir.submitted_at
        FROM qw_investment_request ir
        JOIN qw_investor inv ON ir.investor_id = inv.investor_id
        JOIN qw_user u ON inv.user_id = u.user_id
        LIMIT 50
    ");
    // Seed farm approvals
    $pdo->exec("
        INSERT IGNORE INTO qw_activity_log (user_id, action_type, entity_type, entity_id, created_at)
        SELECT COALESCE(f.approved_by, 1), 'approve_farm', 'farm', f.farm_id, f.created_at
        FROM qw_farm f WHERE f.farm_status = 'approved' LIMIT 20
    ");
    // Seed farmer verifications
    $pdo->exec("
        INSERT IGNORE INTO qw_activity_log (user_id, action_type, entity_type, entity_id, created_at)
        SELECT COALESCE(fr.verified_by, 1), 'verified_farmer', 'farmer', fr.farmer_id, fr.verified_at
        FROM qw_farmer fr WHERE fr.verified_at IS NOT NULL LIMIT 20
    ");
}

// Date filters
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
$search   = trim($_GET['search'] ?? '');

$where = [];
$params = [];

if ($dateFrom) { $where[] = 'al.created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo)   { $where[] = 'al.created_at <= ?'; $params[] = $dateTo   . ' 23:59:59'; }
if ($search)   {
    $where[]  = '(u.first_name LIKE ? OR u.last_name LIKE ? OR al.action_type LIKE ? OR al.entity_type LIKE ?)';
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s, $s);
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$logsStmt = $pdo->prepare("
    SELECT al.log_id, al.user_id, al.action_type, al.entity_type, al.entity_id, al.created_at,
           u.first_name, u.last_name, u.role
    FROM qw_activity_log al
    JOIN qw_user u ON al.user_id = u.user_id
    $whereSQL
    ORDER BY al.created_at DESC
    LIMIT 500
");
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll();

$actionLabels = [
    'login'               => 'تسجيل دخول',
    'register'            => 'تسجيل حساب جديد',
    'add_farm'            => 'إضافة مزرعة',
    'edit_farm'           => 'تعديل مزرعة',
    'approve_farm'        => 'اعتماد مزرعة',
    'approved_farm'       => 'اعتماد مزرعة',
    'rejected_farm'       => 'رفض مزرعة',
    'deactivated_farm'    => 'تعطيل مزرعة',
    'submit_request'      => 'تقديم طلب استثمار',
    'accept_request'      => 'قبول طلب استثمار',
    'reject_request'      => 'رفض طلب استثمار',
    'post_update'         => 'نشر تحديث مزرعة',
    'delete_content'      => 'حذف محتوى مخالف',
    'edit_content'        => 'تعديل محتوى',
    'update_complaint'    => 'تحديث حالة شكوى',
    'verified_farmer'     => 'توثيق مزارع',
    'verified_farmer'     => 'توثيق مزارع',
    'rejected_farmer'     => 'رفض توثيق مزارع',
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجل النشاط - قنوان</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<nav>
    <a href="admin.php" class="nav-back">← لوحة الإدارة</a>
    <div class="nav-links">
        <a href="admin.php"          class="nav-link">لوحة الإدارة</a>
        <a href="activity-logs.php"  class="nav-link active">سجل النشاط</a>
        <a href="logout.php"         class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='admin.php'">
        <img class="logo-img" src="images\logo.png" alt="قنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>سجل النشاط</h2>
        <p>تدقيق جميع إجراءات النظام والمستخدمين — السجلات للقراءة فقط ولا يمكن تعديلها أو حذفها.</p>
    </section>

    <!-- Filters -->
    <form method="GET" action="activity-logs.php">
        <section class="admin-toolbar">
            <input type="text"  name="search"    value="<?= htmlspecialchars($search) ?>"   placeholder="ابحث بالاسم أو نوع الإجراء">
            <input type="date"  name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"  title="من تاريخ">
            <input type="date"  name="date_to"   value="<?= htmlspecialchars($dateTo) ?>"    title="إلى تاريخ">
            <button type="submit" class="admin-action-btn btn-primary" style="flex-shrink:0;">🔍 بحث</button>
            <a href="activity-logs.php" class="admin-action-btn btn-secondary" style="text-decoration:none;padding:13px 16px;">إعادة تعيين</a>
        </section>
    </form>

    <div style="color:var(--text-faint);font-size:13px;margin-bottom:14px;text-align:left;">
        عدد السجلات: <?= count($logs) ?>
    </div>

    <section class="admin-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>معرف المستخدم</th>
                    <th>اسم المستخدم</th>
                    <th>الدور</th>
                    <th>نوع الإجراء</th>
                    <th>الكيان المتأثر</th>
                    <th>الوقت</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--text-faint);padding:32px;">لا توجد سجلات تطابق البحث.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="color:var(--text-faint);font-size:12px;"><?= $log['log_id'] ?></td>
                    <td><?= $log['user_id'] ?></td>
                    <td><?= htmlspecialchars($log['first_name'].' '.$log['last_name']) ?></td>
                    <td><?php
                        $r=['farmer'=>'مزارع','investor'=>'مستثمر','admin'=>'مدير'];
                        echo $r[$log['role']] ?? $log['role'];
                    ?></td>
                    <td>
                        <span style="background:var(--bg-section);padding:3px 10px;border-radius:6px;font-size:13px;">
                            <?= $actionLabels[$log['action_type']] ?? htmlspecialchars($log['action_type']) ?>
                        </span>
                    </td>
                    <td style="font-size:13px;color:var(--text-muted);">
                        <?php if ($log['entity_type']): ?>
                            <?= htmlspecialchars($log['entity_type']) ?> #<?= $log['entity_id'] ?>
                        <?php else: echo '—'; endif; ?>
                    </td>
                    <td style="font-size:13px;white-space:nowrap;"><?= date('Y-m-d H:i', strtotime($log['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>