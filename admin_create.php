<?php
require 'db_connect.php';

$db = getDB();

$password = password_hash("123456", PASSWORD_DEFAULT);

$stmt = $db->prepare("INSERT INTO qw_user (first_name, last_name, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?, ?)");

$stmt->execute([
    "Admin",
    "New",
    "admin2@qinwan.sa",
    "0500000002",
    "admin",
    $password
]);

echo "Admin created!";
?>