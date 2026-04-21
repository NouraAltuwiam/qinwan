<?php
// ============================================================
// قِنوان — register.php  (US-Farmer-01 / US-Investor-01)
// Full validation: password strength, confirm, duplicate email
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    $r = $_SESSION['role'] ?? 'investor';
    header('Location: ' . ($r === 'farmer' ? 'Farmer.php' : ($r === 'admin' ? 'admin.php' : 'investor.php')));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = trim($_POST['full_name']        ?? '');
    $email            = trim(strtolower($_POST['email'] ?? ''));
    $password         = $_POST['password']               ?? '';
    $confirm_password = $_POST['confirm_password']       ?? '';
    $national_id      = trim($_POST['national_id']      ?? '');
    $phone            = trim($_POST['phone']             ?? '');
    $role             = $_POST['role']                   ?? 'investor';

    // Validation chain (US-01 acceptance criteria)
    if (!$full_name || !$email || !$password || !$confirm_password) {
        $error = 'يرجى ملء جميع الحقول المطلوبة.';
    } elseif (!preg_match('/^[A-Za-z0-9_.]+@[A-Za-z0-9.]+\.[A-Za-z]{2,}$/', $email)) {
        $error = 'صيغة البريد الإلكتروني غير صحيحة. مثال: xxxx@xxx.xxx';
    } elseif (strlen($password) < 8) {
        $error = 'كلمة المرور يجب أن تحتوي على 8 أحرف على الأقل.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = 'كلمة المرور يجب أن تحتوي على حرف كبير على الأقل.';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = 'كلمة المرور يجب أن تحتوي على حرف صغير على الأقل.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = 'كلمة المرور يجب أن تحتوي على رقم على الأقل.';
    } elseif (!preg_match('/[\W_]/', $password)) {
        $error = 'كلمة المرور يجب أن تحتوي على رمز خاص على الأقل (!@#$%).';
    } elseif ($password !== $confirm_password) {
        $error = 'كلمتا المرور غير متطابقتين. يرجى التحقق والمحاولة مجدداً.';
    } elseif (!in_array($role, ['farmer', 'investor'])) {
        $error = 'نوع الحساب غير صحيح.';
    } else {
        $pdo = getDB();
        $s   = $pdo->prepare("SELECT user_id FROM qw_user WHERE email = ?");
        $s->execute([$email]);
        if ($s->fetch()) {
            $error = 'هذا البريد الإلكتروني مسجل مسبقاً. يرجى استخدام بريد مختلف أو تسجيل الدخول.';
        } else {
            $parts      = explode(' ', $full_name, 2);
            $first_name = $parts[0];
            $last_name  = $parts[1] ?? '.';
            $hash       = password_hash($password, PASSWORD_BCRYPT);
            $phone      = $phone ?: '0500000000';

            $s = $pdo->prepare("INSERT INTO qw_user (first_name, last_name, email, phone, password_hash, role) VALUES (?,?,?,?,?,?)");
            $s->execute([$first_name, $last_name, $email, $phone, $hash, $role]);
            $user_id = (int)$pdo->lastInsertId();

            $_SESSION['user_id']    = $user_id;
            $_SESSION['role']       = $role;
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name']  = $last_name;

            if ($role === 'farmer') {
                $natid = $national_id ?: ('F' . $user_id . rand(1000,9999));
                $s = $pdo->prepare("INSERT INTO qw_farmer (user_id, national_id, verification_status) VALUES (?,?,'pending')");
                $s->execute([$user_id, $natid]);
                $_SESSION['farmer_id'] = (int)$pdo->lastInsertId();
                logActivity($user_id, 'register', 'farmer', $user_id);
                header('Location: Farmer.php'); exit;
            } else {
                $natid = $national_id ?: ('I' . $user_id . rand(1000,9999));
                $s = $pdo->prepare("INSERT INTO qw_investor (user_id, national_id) VALUES (?,?)");
                $s->execute([$user_id, $natid]);
                $investor_id = (int)$pdo->lastInsertId();
                $_SESSION['investor_id'] = $investor_id;
                $s = $pdo->prepare("INSERT INTO qw_cart (investor_id, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
                $s->execute([$investor_id]);
                logActivity($user_id, 'register', 'investor', $user_id);
                header('Location: investor.php'); exit;
            }
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
    <div class="auth-box" style="max-width:480px;">

      <div class="auth-logo">
        <img class="auth-logo-img" src="logo.png" alt="قِنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
        <div class="auth-logo-fallback" style="display:none">ق</div>
      </div>

      <h1>إنشاء حساب جديد</h1>
      <p>انضم إلى قِنوان كمستثمر أو مزارع</p>

      <div class="auth-ornament">
        <div class="line"></div><div class="diamond"></div>
        <div class="line" style="transform:scaleX(-1)"></div>
      </div>

      <?php if ($error): ?>
        <div class="auth-error">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="register.php" id="registerForm" novalidate>

        <label>الاسم الكامل <span style="color:red">*</span></label>
        <input type="text" name="full_name" placeholder="أدخل اسمك الكامل" required
               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" />

        <label>البريد الإلكتروني <span style="color:red">*</span></label>
        <input type="email" name="email" placeholder="xxxx@xxx.xxx" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />

        <label>كلمة المرور <span style="color:red">*</span></label>
        <div class="password-wrap" style="position:relative;margin-bottom:0;">
          <input type="password" name="password" id="pw1" placeholder="••••••••" required autocomplete="new-password"
                 style="margin-bottom:0;" />
          <button type="button" class="toggle-pw" onclick="togglePw('pw1',this)">👁</button>
        </div>
        <div class="pw-hint">8+ أحرف، حرف كبير، صغير، رقم، ورمز خاص مثل (!@#$%)</div>

        <label>تأكيد كلمة المرور <span style="color:red">*</span></label>
        <div class="password-wrap" style="position:relative;">
          <input type="password" name="confirm_password" id="pw2" placeholder="••••••••" required autocomplete="new-password"
                 style="margin-bottom:0;" />
          <button type="button" class="toggle-pw" onclick="togglePw('pw2',this)">👁</button>
        </div>

        <label>رقم الهوية الوطنية</label>
        <input type="text" name="national_id" placeholder="10XXXXXXXX"
               value="<?= htmlspecialchars($_POST['national_id'] ?? '') ?>" />

        <label>رقم الجوال</label>
        <input type="tel" name="phone" placeholder="05XXXXXXXX"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" />

        <div class="role-label">أنا <span style="color:red">*</span></div>
        <div class="role-buttons">
          <button type="button" class="role-btn <?= (($_POST['role'] ?? 'investor') === 'investor') ? 'active' : '' ?>"
                  onclick="setRole('investor',this)">🌿 مستثمر</button>
          <button type="button" class="role-btn <?= (($_POST['role'] ?? '') === 'farmer') ? 'active' : '' ?>"
                  onclick="setRole('farmer',this)">🌾 مزارع</button>
        </div>
        <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($_POST['role'] ?? 'investor') ?>" />

        <button type="submit" class="auth-btn">إنشاء الحساب</button>
      </form>

      <div class="auth-link">لديك حساب؟ <a href="login.php">تسجيل الدخول</a></div>
    </div>
  </div>

  <style>
    .password-wrap { position:relative; margin-bottom:18px; }
    .password-wrap input { width:100%; margin-bottom:0 !important; }
    .toggle-pw { position:absolute; left:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:16px; padding:0; }
    .pw-hint { font-size:11px; color:var(--text-muted); margin-bottom:18px; text-align:right; padding-right:2px; }
  </style>

  <script>
    function setRole(val, btn) {
      document.getElementById('roleInput').value = val;
      document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    }
    function togglePw(id, btn) {
      const inp = document.getElementById(id);
      if (inp.type === 'password') { inp.type = 'text';     btn.textContent = '🙈'; }
      else                         { inp.type = 'password'; btn.textContent = '👁';  }
    }
    document.getElementById('registerForm').addEventListener('submit', function(e) {
      const pw  = document.getElementById('pw1').value;
      const cpw = document.getElementById('pw2').value;
      if (pw !== cpw) { e.preventDefault(); alert('كلمتا المرور غير متطابقتين'); }
    });
  </script>
</body>
</html>