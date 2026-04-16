<?php
// ============================================================
// قِنوان — register.php
// إنشاء حساب — يطابق register.html بالكامل
// Tables: qw_user, qw_farmer, qw_investor, qw_cart
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name   = trim($_POST['full_name']   ?? '');
    $email       = trim($_POST['email']       ?? '');
    $password    = $_POST['password']          ?? '';
    $national_id = trim($_POST['national_id'] ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $role        = $_POST['role']              ?? 'investor';

    // ── Validation ──
    if (!$full_name || !$email || !$password) {
        $error = 'يرجى ملء جميع الحقول';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'صيغة البريد الإلكتروني غير صحيحة';
    } elseif (strlen($password) < 8) {
        $error = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
    } elseif (!in_array($role, ['farmer', 'investor'])) {
        $error = 'نوع الحساب غير صحيح';
    } else {
        $pdo = getDB();

        // تحقق من تكرار البريد
        $s = $pdo->prepare("SELECT user_id FROM qw_user WHERE email = ?");
        $s->execute([$email]);
        if ($s->fetch()) {
            $error = 'البريد الإلكتروني مسجل مسبقاً';
        } else {
            // تقسيم الاسم
            $parts      = explode(' ', $full_name, 2);
            $first_name = $parts[0];
            $last_name  = $parts[1] ?? '.';
            $hash       = password_hash($password, PASSWORD_BCRYPT);
            $phone      = $phone ?: '0500000000';

            // إدراج في qw_user
            $s = $pdo->prepare("
                INSERT INTO qw_user (first_name, last_name, email, phone, password_hash, role)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $s->execute([$first_name, $last_name, $email, $phone, $hash, $role]);
            $user_id = (int)$pdo->lastInsertId();

            $_SESSION['user_id']    = $user_id;
            $_SESSION['role']       = $role;
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name']  = $last_name;

            if ($role === 'farmer') {
                // إدراج في qw_farmer
                $natid = $national_id ?: ('F' . $user_id . rand(1000,9999));
                $s = $pdo->prepare("
                    INSERT INTO qw_farmer (user_id, national_id, verification_status)
                    VALUES (?, ?, 'pending')
                ");
                $s->execute([$user_id, $natid]);
                $_SESSION['farmer_id'] = (int)$pdo->lastInsertId();
                header('Location: Farmer.php');
            } else {
                // إدراج في qw_investor
                $natid = $national_id ?: ('I' . $user_id . rand(1000,9999));
                $s = $pdo->prepare("
                    INSERT INTO qw_investor (user_id, national_id)
                    VALUES (?, ?)
                ");
                $s->execute([$user_id, $natid]);
                $investor_id = (int)$pdo->lastInsertId();
                $_SESSION['investor_id'] = $investor_id;

                // إنشاء سلة للمستثمر
                $s = $pdo->prepare("
                    INSERT INTO qw_cart (investor_id, expires_at)
                    VALUES (?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
                ");
                $s->execute([$investor_id]);
                header('Location: investor.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>قِنوان | إنشاء حساب</title>
  <link rel="stylesheet" href="style.css" />
</head>

<body>
  <div class="auth-container">
    <div class="auth-box" style="max-width:460px;">

      <!-- اللوقو — الاسم موجود في الصورة، لا يوجد نص إضافي -->
      <div class="auth-logo">
        <img class="auth-logo-img" src="logo.png" alt="قِنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
        <div class="auth-logo-fallback" style="display:none">ق</div>
      </div>

      <h1>إنشاء حساب جديد</h1>
      <p>انضم إلى قِنوان كمستثمر أو مزارع</p>

      <div class="auth-ornament">
        <div class="line"></div>
        <div class="diamond"></div>
        <div class="line" style="transform:scaleX(-1)"></div>
      </div>

      <?php if ($error): ?>
        <div class="auth-error" style="color:#c0392b;background:#fdf0ef;border:1px solid #f5c6c6;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:14px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="register.php" id="registerForm">

        <!-- full_name → first_name + last_name في جدول qw_user -->
        <label>الاسم الكامل</label>
        <input type="text" name="full_name" placeholder="أدخل اسمك الكامل" required
               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" />

        <!-- email في جدول qw_user -->
        <label>البريد الإلكتروني</label>
        <input type="email" name="email" placeholder="example@email.com" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />

        <!-- password_hash في جدول qw_user -->
        <label>كلمة المرور</label>
        <input type="password" name="password" placeholder="••••••••" required />

        <!-- national_id في جدول qw_farmer / qw_investor -->
        <label>رقم الهوية الوطنية</label>
        <input type="text" name="national_id" placeholder="10XXXXXXXX"
               value="<?= htmlspecialchars($_POST['national_id'] ?? '') ?>" />

        <!-- phone في جدول qw_user -->
        <label>رقم الجوال</label>
        <input type="tel" name="phone" placeholder="05XXXXXXXX"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" />

        <!-- role في جدول qw_user -->
        <div class="role-label">أنا</div>
        <div class="role-buttons">
          <button type="button" class="role-btn active" onclick="setRole('investor',this)">مستثمر</button>
          <button type="button" class="role-btn"        onclick="setRole('farmer',this)">مزارع</button>
        </div>
        <input type="hidden" name="role" id="roleInput" value="investor" />

        <button type="submit" class="auth-btn">إنشاء الحساب</button>
      </form>

      <div class="auth-link">
        لديك حساب بالفعل؟ <a href="login.php">تسجيل الدخول</a>
      </div>

    </div>
  </div>

  <script src="script.js"></script>
  <script>
    function setRole(val, btn) {
      document.getElementById('roleInput').value = val;
      document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    }
  </script>
</body>
</html>
