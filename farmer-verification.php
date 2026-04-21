<?php
// ============================================================
// قِنوان — farmer-verification.php  (US-Admin-02)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $farmer_id = (int)($_POST['farmer_id'] ?? 0);
    $decision  = $_POST['decision'] ?? '';
    $note      = trim($_POST['note'] ?? '');
    if ($farmer_id && in_array($decision, ['verified','rejected'])) {
        $pdo->prepare("UPDATE qw_farmer SET verification_status=?, verified_by=?, verified_at=NOW() WHERE farmer_id=?")
            ->execute([$decision, $_SESSION['user_id'], $farmer_id]);
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,?,\'farmer\',?)")
                ->execute([$_SESSION['user_id'], $decision . '_farmer', $farmer_id]);
        } catch(Exception $e) {}
    }
    header('Location: farmer-verification.php'); exit;
}

$farmers = $pdo->query("
    SELECT fr.farmer_id, fr.national_id, fr.verification_status, fr.verified_at,
           u.first_name, u.last_name, u.email, u.phone, u.created_at
    FROM qw_farmer fr
    JOIN qw_user u ON fr.user_id = u.user_id
    ORDER BY fr.farmer_id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>توثيق المزارعين - قنوان</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<nav>
    <a href="admin.php" class="nav-back">← لوحة الإدارة</a>
    <div class="nav-links">
        <a href="admin.php"               class="nav-link">لوحة الإدارة</a>
        <a href="users-managment.php"     class="nav-link">المستخدمون</a>
        <a href="farms-managment.php"     class="nav-link">المزارع</a>
        <a href="farmer-verification.php" class="nav-link active">التوثيق</a>
        <a href="logout.php"              class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='admin.php'">
        <img class="logo-img" src="logo.png" alt="قنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>توثيق حسابات المزارعين</h2>
        <p>مراجعة الهوية الوطنية ومستندات ملكية المزرعة، ثم اعتماد أو رفض الطلب.</p>
    </section>

    <section class="admin-toolbar">
        <input type="text" id="verifySearch" placeholder="ابحث باسم المزارع أو رقم الهوية" oninput="filterVerifications()">
        <select id="verifyStatusFilter" onchange="filterVerifications()">
            <option value="all">كل الحالات</option>
            <option value="pending">قيد المراجعة</option>
            <option value="verified">موثق</option>
            <option value="rejected">مرفوض</option>
        </select>
    </section>

    <section class="admin-cards-list" id="verificationList">
    <?php foreach ($farmers as $fr):
        $statusMap = ['pending'=>'قيد المراجعة','verified'=>'موثق','rejected'=>'مرفوض'];
    ?>
        <div class="admin-record-card"
             data-status="<?= $fr['verification_status'] ?>"
             data-name="<?= strtolower($fr['first_name'].' '.$fr['last_name'].' '.$fr['national_id']) ?>">

            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3>👤 <?= htmlspecialchars($fr['first_name'].' '.$fr['last_name']) ?></h3>
                    <p>📧 <?= htmlspecialchars($fr['email']) ?></p>
                    <p>📞 <?= htmlspecialchars($fr['phone']) ?></p>
                    <p>📅 تاريخ التسجيل: <?= date('Y-m-d', strtotime($fr['created_at'])) ?></p>
                </div>
                <span class="status-badge status-<?= $fr['verification_status'] === 'verified' ? 'accepted' : ($fr['verification_status'] === 'pending' ? 'pending' : 'rejected') ?>">
                    <?= $statusMap[$fr['verification_status']] ?? $fr['verification_status'] ?>
                </span>
            </div>

            <!-- الهوية الوطنية -->
            <div class="verification-documents" style="margin-top:16px;">
                <div class="verification-doc-box">
                    <h4>🪪 رقم الهوية الوطنية</h4>
                    <div class="national-id-number"><?= htmlspecialchars($fr['national_id']) ?></div>
                </div>
                <?php if ($fr['verified_at']): ?>
                <div class="verification-doc-box">
                    <h4>📅 تاريخ مراجعة التوثيق</h4>
                    <div style="font-size:15px;color:var(--text-main);"><?= date('Y-m-d H:i', strtotime($fr['verified_at'])) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($fr['verification_status'] === 'pending'): ?>
            <div class="admin-card-actions">
                <form method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="farmer_id" value="<?= $fr['farmer_id'] ?>">
                    <input type="text" name="note" placeholder="ملاحظة (اختياري)" class="form-input" style="flex:2;min-width:200px;padding:8px 12px;margin:0;">
                    <button type="submit" name="decision" value="verified"  class="admin-action-btn btn-approve">✅ اعتماد</button>
                    <button type="submit" name="decision" value="rejected"  class="admin-action-btn btn-danger"  onclick="return confirm('تأكيد رفض التوثيق؟')">❌ رفض</button>
                </form>
            </div>
            <?php elseif ($fr['verification_status'] === 'verified'): ?>
                <div style="margin-top:12px;"><span class="verified-badge">✅ تم التوثيق — الشارة المعتمدة نشطة على المنصة</span></div>
            <?php else: ?>
                <div style="margin-top:12px;"><span class="flag-badge">❌ مرفوض</span></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (empty($farmers)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-faint);">لا يوجد مزارعون مسجلون بعد.</div>
    <?php endif; ?>
    </section>
</main>

<script>
function filterVerifications() {
    const q      = document.getElementById('verifySearch').value.toLowerCase();
    const status = document.getElementById('verifyStatusFilter').value;
    document.querySelectorAll('#verificationList .admin-record-card').forEach(card => {
        const matchName   = card.dataset.name.includes(q);
        const matchStatus = (status === 'all' || card.dataset.status === status);
        card.style.display = (matchName && matchStatus) ? '' : 'none';
    });
}
</script>
</body>
</html>