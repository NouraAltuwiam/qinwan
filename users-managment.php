<?php
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }
$pdo = getDB();
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

<header>
    <nav class="navbar">
        <h1 class="logo">قنوان</h1>
        <ul class="nav-links">
            <li><a href="admin.php" class="active-admin-link">لوحة التحكم</a></li>
        </ul>
    </nav>
</header>

<main class="admin-page">

    <section class="admin-heading">
        <h2>إدارة المستخدمين</h2>
        <p>عرض جميع المستثمرين والمزارعين، مع إمكانية البحث بالاسم والتصفية حسب الدور أو الحالة.</p>
    </section>

    <!-- البحث والفلاتر -->
    <section class="admin-toolbar">

        <input type="text" id="userSearchInput" placeholder="ابحث باسم المستخدم">

        <select id="userRoleFilter">
            <option value="all">كل الأدوار</option>
            <option value="Investor">مستثمر</option>
            <option value="Farm Owner">مزارع</option>
        </select>

        <select id="userStatusFilter">
            <option value="all">كل الحالات</option>
            <option value="Active">نشط</option>
            <option value="Suspended">موقوف</option>
        </select>

    </section>

    <!-- الجدول -->
    <section class="admin-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>الاسم</th>
                    <th>الدور</th>
                    <th>تاريخ التسجيل</th>
                    <th>الحالة</th>
                    <th>عرض الملف</th>
                </tr>
            </thead>
            <tbody id="usersManagementTableBody"></tbody>
        </table>
    </section>

</main>

<script src="script2.js"></script>
</body>
</html>