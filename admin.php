<?php
// ============================================================
// قِنوان — admin.php  (US-Admin-06: Platform Statistics)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();
$totalUsers  = (int)$pdo->query("SELECT COUNT(*) FROM qw_user")->fetchColumn();
$activeFarms = (int)$pdo->query("SELECT COUNT(*) FROM qw_farm WHERE farm_status='approved'")->fetchColumn();
$leasedArea  = (float)$pdo->query("SELECT COALESCE(SUM(area_sqm),0) FROM qw_investment_request WHERE req_status='accepted'")->fetchColumn();
$txVolume    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM qw_transaction WHERE payment_status='paid'")->fetchColumn();
$newMonth    = (int)$pdo->query("SELECT COUNT(*) FROM qw_user WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();

// Monthly registrations for chart (last 12 months)
$monthlyStmt = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS cnt
    FROM qw_user
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month ORDER BY month
");
$monthlyData = $monthlyStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>لوحة الإدارة - قنوان</title>
    <link rel="stylesheet" href="style.css" />
</head>
<body>

<nav>
    <button class="nav-back" onclick="window.location.href='index.php'">العودة للرئيسية</button>
    <div class="nav-links">
        <a href="admin.php"              class="nav-link active">لوحة الإدارة</a>
        <a href="users-managment.php"    class="nav-link">المستخدمون</a>
        <a href="farms-managment.php"    class="nav-link">المزارع</a>
        <a href="farmer-verification.php" class="nav-link">التوثيق</a>
        <a href="transactions-monitor.php" class="nav-link">المعاملات</a>
        <a href="complaints-queue.php"   class="nav-link">الشكاوى</a>
        <a href="activity-logs.php"      class="nav-link">السجل</a>
        <a href="content-moderation.php" class="nav-link">المحتوى</a>
        <a href="logout.php"             class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='admin.php'">
        <img class="logo-img" src="images\logo.png" alt="قنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
        <div class="logo-fallback" style="display:none">ق</div>
        <div>
            <span class="logo-name">قنوان</span>
            <span class="logo-sub">الإدارة</span>
        </div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <div class="page-content" style="padding:0;">

        <div class="page-title-wrap" style="margin-bottom:28px;">
            <h1 class="page-title">لوحة الإدارة</h1>
            <div class="title-ornament">
                <div class="orn-line" style="width:60px"></div>
                <div class="orn-diamond"></div>
                <div class="orn-dot"></div>
                <div class="orn-diamond"></div>
                <div class="orn-line" style="width:24px"></div>
            </div>
        </div>

        <p style="color:var(--text-muted);margin-bottom:28px;">
            متابعة الأداء العام للمنصة، وإدارة المستخدمين والمزارع والمعاملات والشكاوى وسجل النشاط من مكان واحد.
        </p>

        <!-- US-Admin-06: Real stats from DB -->
        <section class="admin-stats-grid">
            <div class="admin-stat-card">
                <h3>إجمالي المستخدمين</h3>
                <p><?= number_format($totalUsers) ?></p>
            </div>
            <div class="admin-stat-card">
                <h3>المزارع النشطة</h3>
                <p><?= number_format($activeFarms) ?></p>
            </div>
            <div class="admin-stat-card">
                <h3>المساحة المؤجرة (م²)</h3>
                <p><?= number_format($leasedArea, 0) ?></p>
            </div>
            <div class="admin-stat-card">
                <h3>حجم المعاملات (ر.س)</h3>
                <p><?= number_format($txVolume, 0) ?></p>
            </div>
            <div class="admin-stat-card">
                <h3>تسجيلات هذا الشهر</h3>
                <p><?= number_format($newMonth) ?></p>
            </div>
        </section>

        <!-- Chart -->
        <section class="admin-chart-card">
            <div class="admin-chart-header">
                <h3>التسجيلات الشهرية (آخر 12 شهراً)</h3>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="admin-action-btn btn-primary" onclick="exportCSV()">📥 تصدير CSV</button>
                    <button class="admin-action-btn btn-primary" onclick="exportPDF()">📄 تصدير PDF</button>
                </div>
            </div>
            <div class="monthly-bars" id="monthlyBars">
                <?php
                $maxCnt = max(array_column($monthlyData, 'cnt') ?: [1]);
                foreach ($monthlyData as $m):
                    $heightPct = ($maxCnt > 0) ? round($m['cnt'] / $maxCnt * 100) : 0;
                    $monthLabel = date('M', mktime(0,0,0,(int)substr($m['month'],5),1));
                ?>
                <div class="bar-item">
                    <div class="bar-value"><?= $m['cnt'] ?></div>
                    <div class="bar" style="height:<?= max($heightPct, 6) ?>px;"></div>
                    <div class="bar-label"><?= substr($m['month'],5) ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($monthlyData)): ?>
                    <div style="color:var(--text-faint);text-align:center;width:100%;padding:40px 0;">لا توجد بيانات بعد</div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Admin panels -->
        <section class="admin-panel-grid">
            <a href="users-managment.php" class="admin-panel-card">
                <h3>👥 إدارة المستخدمين</h3>
                <p>عرض جميع المستخدمين، البحث، التصفية، ومراجعة حسابات المزارعين.</p>
            </a>
            <a href="farms-managment.php" class="admin-panel-card">
                <h3>🌴 إدارة المزارع</h3>
                <p>مراجعة طلبات المزارع، اعتمادها، تعديلها، تعطيلها أو حذفها.</p>
            </a>
            <a href="farmer-verification.php" class="admin-panel-card">
                <h3>✅ توثيق المزارعين</h3>
                <p>مراجعة الهوية الوطنية ومستندات الملكية واعتماد أو رفض الطلب.</p>
            </a>
            <a href="transactions-monitor.php" class="admin-panel-card">
                <h3>💳 مراقبة المعاملات</h3>
                <p>عرض جميع المعاملات الاستثمارية ومتابعة حالاتها والتنبيهات المشبوهة.</p>
            </a>
            <a href="complaints-queue.php" class="admin-panel-card">
                <h3>📋 قائمة الشكاوى</h3>
                <p>استقبال الشكاوى، التحقيق فيها، وتحديث حالتها بشكل منظم.</p>
            </a>
            <a href="activity-logs.php" class="admin-panel-card">
                <h3>📑 سجل النشاط</h3>
                <p>تتبع جميع الإجراءات المهمة مع الوقت والكيان المتأثر.</p>
            </a>
            <a href="content-moderation.php" class="admin-panel-card">
                <h3>🛡 إدارة المحتوى</h3>
                <p>حذف المنشورات المخالفة أو تعديل المخالفات البسيطة دون حذف المنشور كاملاً.</p>
            </a>
        </section>

    </div>
</main>

<script>
// US-Admin-06: Export functions
function exportCSV() {
    const rows = [
        ['إجمالي المستخدمين', <?= $totalUsers ?>],
        ['المزارع النشطة',    <?= $activeFarms ?>],
        ['المساحة المؤجرة',   <?= $leasedArea ?>],
        ['حجم المعاملات',    <?= $txVolume ?>],
        ['تسجيلات هذا الشهر', <?= $newMonth ?>],
    ];
    let csv = 'الإحصائية,القيمة\n';
    rows.forEach(r => csv += `"${r[0]}","${r[1]}"\n`);
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'qinwan_stats.csv';
    a.click();
}

function exportPDF() {
    window.print();
}
</script>
</body>
</html>