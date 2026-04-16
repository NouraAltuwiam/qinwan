<?php
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }
$pdo = getDB();
$totalUsers  = (int)$pdo->query("SELECT COUNT(*) FROM qw_user")->fetchColumn();
$activeFarms = (int)$pdo->query("SELECT COUNT(*) FROM qw_farm WHERE farm_status='approved'")->fetchColumn();
$leasedArea  = (float)$pdo->query("SELECT COALESCE(SUM(area_sqm),0) FROM qw_investment_request WHERE req_status='accepted'")->fetchColumn();
$txVolume    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM qw_transaction WHERE payment_status='paid'")->fetchColumn();
$newMonth    = (int)$pdo->query("SELECT COUNT(*) FROM qw_user WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
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
    <button class="nav-back" onclick="window.location.href='index.html'">العودة للرئيسية</button>

    <div class="nav-links">
        <button class="nav-link active" onclick="window.location.href='admin.html'">لوحة الإدارة</button>
        <button class="nav-link" onclick="window.location.href='users-managment.html'">المستخدمون</button>
        <button class="nav-link" onclick="window.location.href='farms-managment.html'">المزارع</button>
        <button class="nav-link" onclick="window.location.href='transactions-monitor.html'">المعاملات</button>
        <button class="nav-link" onclick="window.location.href='activity-logs.html'">السجل</button>
    </div>

    <div class="nav-logo" onclick="window.location.href='admin.html'">
        <img class="logo-img" src="logo.png" alt="قنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
        <div class="logo-fallback" style="display:none">ق</div>
        <div>
            <span class="logo-name">قنوان</span>
            <span class="logo-sub">الإدارة</span>
        </div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <div class="page-content">

        <div class="page-title-wrap">
            <h1 class="page-title">لوحة الإدارة</h1>
            <div class="title-ornament">
                <div class="orn-line" style="width:60px"></div>
                <div class="orn-diamond"></div>
                <div class="orn-dot"></div>
                <div class="orn-diamond"></div>
                <div class="orn-line" style="width:24px"></div>
            </div>
        </div>

        <p style="color: var(--text-muted); margin-bottom: 30px;">
            متابعة الأداء العام للمنصة، وإدارة المستخدمين والمزارع والمعاملات والشكاوى وسجل النشاط من مكان واحد.
        </p>

        <section class="admin-stats-grid">
            <div class="admin-stat-card">
                <h3>إجمالي المستخدمين</h3>
                <p id="statTotalUsers">0</p>
            </div>

            <div class="admin-stat-card">
                <h3>إجمالي المزارع النشطة</h3>
                <p id="statActiveFarms">0</p>
            </div>

            <div class="admin-stat-card">
                <h3>إجمالي المساحة المؤجرة</h3>
                <p id="statLeasedArea">0 م²</p>
            </div>

            <div class="admin-stat-card">
                <h3>إجمالي حجم المعاملات</h3>
                <p id="statTransactionVolume">0 ريال</p>
            </div>

            <div class="admin-stat-card">
                <h3>التسجيلات الجديدة هذا الشهر</h3>
                <p id="statNewRegistrations">0</p>
            </div>
        </section>

        <section class="admin-chart-card">
            <div class="admin-chart-header">
                <h3>التسجيلات الشهرية</h3>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button class="admin-action-btn btn-primary" onclick="exportStatisticsCSV()">تصدير CSV</button>
                    <button class="admin-action-btn btn-primary" onclick="exportStatisticsPDF()">تصدير PDF</button>
                </div>
            </div>
            <div class="monthly-bars" id="monthlyBars"></div>
        </section>

        <section class="admin-panel-grid">
            <a href="users-managment.php" class="admin-panel-card">
                <h3>إدارة المستخدمين</h3>
                <p>عرض جميع المستخدمين، البحث، التصفية، ومراجعة حسابات المزارعين.</p>
            </a>

            <a href="farms-managment.php" class="admin-panel-card">
                <h3>إدارة المزارع</h3>
                <p>مراجعة طلبات المزارع، اعتمادها، تعديلها، تعطيلها أو حذفها.</p>
            </a>

            <a href="farmer-verification.php" class="admin-panel-card">
                <h3>توثيق المزارعين</h3>
                <p>مراجعة الهوية الوطنية ومستندات الملكية واعتماد أو رفض الطلب.</p>
            </a>

            <a href="transactions-monitor.php" class="admin-panel-card">
                <h3>مراقبة المعاملات</h3>
                <p>عرض جميع المعاملات الاستثمارية ومتابعة حالاتها والتنبيهات المشبوهة.</p>
            </a>

            <a href="complaints-queue.php" class="admin-panel-card">
                <h3>قائمة الشكاوى</h3>
                <p>استقبال الشكاوى، التحقيق فيها، وتحديث حالتها بشكل منظم.</p>
            </a>

            <a href="activity-logs.php" class="admin-panel-card">
                <h3>سجل النشاط</h3>
                <p>تتبع جميع الإجراءات المهمة مع الوقت والكيان المتأثر.</p>
            </a>

            <a href="content-moderation.php" class="admin-panel-card">
                <h3>إدارة المحتوى</h3>
                <p>حذف المنشورات المخالفة أو تعديل المخالفات البسيطة دون حذف المنشور كاملًا.</p>
            </a>
        </section>

    </div>
</main>

<script src="script2.js"></script>
</body>
</html>