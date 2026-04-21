<?php
// ============================================================
// قِنوان — complaints-queue.php  (US-Admin-07)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $status       = $_POST['comp_status'] ?? '';
    $valid = ['open','under_investigation','resolved','dismissed'];
    if ($complaint_id && in_array($status, $valid)) {
        $pdo->prepare("UPDATE qw_complaint SET comp_status=? WHERE complaint_id=?")
            ->execute([$status, $complaint_id]);
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,\'update_complaint\',\'complaint\',?)")
                ->execute([$_SESSION['user_id'], $complaint_id]);
        } catch(Exception $e) {}
    }
    header('Location: complaints-queue.php'); exit;
}

$complaints = $pdo->query("
    SELECT c.*, u.first_name, u.last_name, u.email, u.role
    FROM qw_complaint c
    JOIN qw_user u ON c.user_id = u.user_id
    ORDER BY c.created_at DESC
")->fetchAll();

$statusLabel = ['open'=>'مفتوحة','under_investigation'=>'قيد التحقيق','resolved'=>'محلولة','dismissed'=>'مرفوضة'];
$statusClass = ['open'=>'status-pending','under_investigation'=>'status-pending','resolved'=>'status-accepted','dismissed'=>'status-rejected'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قائمة الشكاوى - قنوان</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<nav>
    <a href="admin.php" class="nav-back">← لوحة الإدارة</a>
    <div class="nav-links">
        <a href="admin.php"            class="nav-link">لوحة الإدارة</a>
        <a href="complaints-queue.php" class="nav-link active">الشكاوى</a>
        <a href="logout.php"           class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='admin.php'">
        <img class="logo-img" src="logo.png" alt="قنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>قائمة الشكاوى</h2>
        <p>استقبال الشكاوى والتحقيق فيها وتحديث حالتها. الشكاوى المحلولة تُحفظ ولا تُحذف.</p>
    </section>

    <section class="admin-toolbar">
        <input type="text" id="complaintSearch" placeholder="ابحث بالاسم أو الموضوع" oninput="filterComplaints()">
        <select id="complaintStatus" onchange="filterComplaints()">
            <option value="all">كل الحالات</option>
            <option value="open">مفتوحة</option>
            <option value="under_investigation">قيد التحقيق</option>
            <option value="resolved">محلولة</option>
            <option value="dismissed">مرفوضة</option>
        </select>
    </section>

    <section class="admin-cards-list" id="complaintsList">
    <?php foreach ($complaints as $c): ?>
        <div class="admin-record-card"
             data-status="<?= $c['comp_status'] ?>"
             data-name="<?= strtolower($c['first_name'].' '.$c['last_name'].' '.$c['subject']) ?>">

            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                <div style="flex:1;">
                    <h3>📋 <?= htmlspecialchars($c['subject']) ?></h3>
                    <p>👤 <?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?>
                       <span style="color:var(--text-faint);font-size:12px;">(<?= $c['role'] === 'farmer' ? 'مزارع' : 'مستثمر' ?>)</span>
                    </p>
                    <p>📧 <?= htmlspecialchars($c['email']) ?></p>
                    <p style="margin-top:10px;background:var(--bg-section);padding:12px;border-radius:8px;font-size:14px;line-height:1.6;">
                        <?= nl2br(htmlspecialchars($c['description'])) ?>
                    </p>
                    <p style="font-size:12px;color:var(--text-faint);margin-top:8px;">
                        📅 <?= date('Y-m-d H:i', strtotime($c['created_at'])) ?>
                    </p>
                </div>
                <span class="status-badge <?= $statusClass[$c['comp_status']] ?? '' ?>">
                    <?= $statusLabel[$c['comp_status']] ?? $c['comp_status'] ?>
                </span>
            </div>

            <?php if ($c['comp_status'] !== 'resolved'): ?>
            <form method="POST" class="admin-card-actions" style="margin-top:16px;">
                <input type="hidden" name="complaint_id" value="<?= $c['complaint_id'] ?>">
                <?php if ($c['comp_status'] !== 'under_investigation'): ?>
                <button type="submit" name="comp_status" value="under_investigation" class="admin-action-btn btn-secondary">🔍 بدء التحقيق</button>
                <?php endif; ?>
                <button type="submit" name="comp_status" value="resolved"    class="admin-action-btn btn-approve">✅ حل الشكوى</button>
                <button type="submit" name="comp_status" value="dismissed"   class="admin-action-btn btn-danger">❌ رفض</button>
            </form>
            <?php else: ?>
                <div style="margin-top:12px;color:var(--green-mid);font-size:13px;">✅ محفوظة — تمت معالجة هذه الشكوى</div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (empty($complaints)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-faint);">لا توجد شكاوى حتى الآن.</div>
    <?php endif; ?>
    </section>
</main>

<script>
function filterComplaints() {
    const q      = document.getElementById('complaintSearch').value.toLowerCase();
    const status = document.getElementById('complaintStatus').value;
    document.querySelectorAll('#complaintsList .admin-record-card').forEach(card => {
        const matchName   = card.dataset.name.includes(q);
        const matchStatus = (status === 'all' || card.dataset.status === status);
        card.style.display = (matchName && matchStatus) ? '' : 'none';
    });
}
</script>
</body>
</html>