<?php
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }
$pdo = getDB();
$transactions = $pdo->query("
    SELECT tx.transaction_id, tx.amount, tx.payment_status, tx.paid_at,
           ir.area_sqm, ir.duration, ir.harvest_method, ir.req_status, ir.submitted_at,
           f.name AS farm_name, f.region,
           ui.first_name AS inv_first, ui.last_name AS inv_last,
           uf.first_name AS far_first, uf.last_name AS far_last
    FROM qw_transaction tx
    JOIN qw_investment_request ir ON tx.request_id = ir.request_id
    JOIN qw_farm_offer fo  ON ir.offer_id   = fo.offer_id
    JOIN qw_farm f         ON fo.farm_id    = f.farm_id
    JOIN qw_farmer fr      ON f.farmer_id   = fr.farmer_id
    JOIN qw_user uf        ON fr.user_id    = uf.user_id
    JOIN qw_investor inv   ON ir.investor_id = inv.investor_id
    JOIN qw_user ui        ON inv.user_id   = ui.user_id
    ORDER BY tx.transaction_id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مراقبة المعاملات - قنوان</title>
    <link rel="stylesheet" href="style.css">

</head>
<body>

<header>
    <nav class="navbar">
        <h1 class="logo">Qinwan</h1>
        <ul class="nav-links">
            <li><a href="index.html">تصفح المزارع</a></li>
            <li><a href="investor.php">المستثمر</a></li>
            <li><a href="Farmer.php">المزارع</a></li>
            <li><a href="admin.php" class="active-admin-link">الإدارة</a></li>
            <li><a href="login.php">تسجيل الدخول</a></li>
            <li><a href="register.php" class="btn">ابدأ الآن</a></li>
        </ul>
    </nav>
</header>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>مراقبة المعاملات</h2>
        <p>عرض جميع المعاملات الاستثمارية والتحقق من سلامتها المالية.</p>
    </section>

   <section class="admin-toolbar">
    <select id="transactionStatusFilter">
        <option value="all">كل الحالات</option>
        <option value="Pending">قيد المراجعة</option>
        <option value="Accepted">مقبولة</option>
        <option value="Rejected">مرفوضة</option>
        <option value="Completed">مكتملة</option>
    </select>

    <input type="date" id="transactionDateFrom" placeholder="من تاريخ">
    <input type="date" id="transactionDateTo" placeholder="إلى تاريخ">
</section>

    <section class="admin-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>اسم المستثمر</th>
                    <th>اسم المزارع</th>
                    <th>اسم المزرعة</th>
                    <th>المساحة</th>
                    <th>المدة</th>
                    <th>طريقة الحصاد</th>
                    <th>الحالة</th>
                    <th>التاريخ</th>
                    <th>التفاصيل</th>
                    <th>التنبيه</th>
                </tr>
            </thead>
            <tbody id="transactionsTableBody"></tbody>
        </table>
    </section>
</main>

<script src="script2.js"></script>
</body>
</html>