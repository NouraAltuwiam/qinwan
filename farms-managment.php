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
    $valid = ['approved','rejected','deactivated'];
    if ($farm_id && in_array($decision, $valid)) {
        $pdo->prepare("UPDATE qw_farm SET farm_status=?, approved_by=? WHERE farm_id=?")
            ->execute([$decision, $_SESSION['user_id'], $farm_id]);
        try {
            $pdo->prepare("INSERT INTO qw_activity_log (user_id,action_type,entity_type,entity_id) VALUES (?,?,\'farm\',?)")
                ->execute([$_SESSION['user_id'], $decision . '_farm', $farm_id]);
        } catch(Exception $e) {}
    }
    header('Location: farms-managment.php'); exit;
}

$filter = $_GET['status'] ?? 'all';
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
        <img class="logo-img" src="logo.png" alt="قنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
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