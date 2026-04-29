<?php
// ============================================================
// قِنوان — index.php  (Home page with real stats)
// ============================================================
require_once 'db_connect.php';
$pdo = getDB();
$approvedFarms = (int)$pdo->query("SELECT COUNT(*) FROM qw_farm WHERE farm_status='approved'")->fetchColumn();
$totalUsers    = (int)$pdo->query("SELECT COUNT(*) FROM qw_user")->fetchColumn();
$txVolume      = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM qw_transaction WHERE payment_status='paid'")->fetchColumn();
$totalInvestors = (int)$pdo->query("SELECT COUNT(*) FROM qw_investor")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>قِنوان | المنصة الرقمية الأولى لاستثمار النخيل</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>

<!-- شريط التنقل -->
<nav>
  <div class="nav-logo" onclick="window.location.href='index.php'">
    <img class="logo-img" src="images\logo.png" alt="قِنوان"
         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
    <div class="logo-fallback" style="display:none">ق</div>
    <div>
      <span class="logo-name">قِنوان</span>
      <span class="logo-sub">المنصة الرقمية للاستثمار الزراعي</span>
    </div>
  </div>
  <div class="nav-links">
    <a href="index.php"    class="nav-link active">الرئيسية</a>
    <a href="investor.php" class="nav-link">المستثمر</a>
    <a href="Farmer.php"   class="nav-link">المزارع</a>
    <a href="admin.php"    class="nav-link">المشرف</a>
    <a href="login.php"    class="nav-link">تسجيل الدخول</a>
    <a href="register.php" class="nav-link btn-nav">ابدأ الآن</a>
  </div>
</nav>

<!-- Hero -->
<section class="hero-section">
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <div class="hero-logo-wrap">
      <img src="images/logo.png" alt="قِنوان"
           onerror="this.style.display='none';" />
    </div>
    <h1 class="hero-title">استثمر في مزارع النخيل<br>بثقة وشفافية</h1>
    <p class="hero-subtitle">منصة رقمية متكاملة تربط المستثمرين بأصحاب مزارع النخيل في المملكة العربية السعودية</p>
    <div class="hero-buttons">
      <a href="register.php" class="btn-primary" style="width:auto;padding:13px 32px;font-size:16px;border-radius:40px;">ابدأ الآن</a>
      <a href="#about"       class="btn-outline">تعرف علينا</a>
    </div>
  </div>
</section>
</section>

<!-- من نحن -->
<section class="about-section" id="about">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title">ما هي قِنوان؟</h2>
      <div class="title-ornament" style="justify-content:center;">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-dot"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:60px"></div>
      </div>
    </div>
    <p class="about-text">
      قِنوان هي منصة استثمار زراعي رقمية تهدف إلى سد الفجوة بين المستثمرين الباحثين عن فرص استثمارية مستدامة وأخلاقية،
      وبين مزارعي النخيل في المملكة العربية السعودية. نوفر الشفافية والثقة والشراكات المباشرة.
    </p>
  </div>
</section>

<!-- المميزات -->
<section class="features-section">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title">لماذا قِنوان؟</h2>
    </div>
    <div class="features-grid">
      <div class="feature-card">
        <div class="feature-icon">🔍</div>
        <h3 class="feature-title">استثمار شفاف</h3>
        <p class="feature-text">يتم تتبع كل استثمار بتوثيق واضح وتقارير دورية عن التقدم والعوائد المالية.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">🤝</div>
        <h3 class="feature-title">تواصل مباشر</h3>
        <p class="feature-text">تواصل مباشر مع مالك المزرعة. بدون وسطاء أو رسوم خفية — شراكات نزيهة فقط.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">🌱</div>
        <h3 class="feature-title">عوائد مستدامة</h3>
        <p class="feature-text">النخيل محصول طويل الأمد ومستدام. استثمر في زراعة تنمو مع الوقت.</p>
      </div>
    </div>
  </div>
</section>

<!-- خطوات العمل -->
<section class="steps-section">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title">كيف تعمل المنصة؟</h2>
    </div>
    <div class="steps-grid">
      <div class="step-card"><div class="step-number">١</div><h3 class="step-title">إنشاء حساب</h3><p class="step-text">سجل كمستثمر أو مالك مزرعة في دقائق</p></div>
      <div class="step-card"><div class="step-number">٢</div><h3 class="step-title">استكشاف واختيار</h3><p class="step-text">استعرض المزارع المتاحة واطلع على التفاصيل الكاملة</p></div>
      <div class="step-card"><div class="step-number">٣</div><h3 class="step-title">تقديم طلب</h3><p class="step-text">اختر المبلغ المناسب وأرسل طلبك إلى مالك المزرعة</p></div>
      <div class="step-card"><div class="step-number">٤</div><h3 class="step-title">تتبع ونمو</h3><p class="step-text">تابع استثماراتك واستلم التحديثات وشاهد محفظتك تنمو</p></div>
    </div>
  </div>
</section>

<!-- الإحصائيات — بيانات حقيقية من DB -->
<section class="stats-section">
  <div class="container">
    <div class="stats-grid-home">
      <div class="stat-card-home">
        <div class="stat-number">+<?= $approvedFarms ?></div>
        <div class="stat-label" style="color:rgba(255,255,255,0.8);">مزرعة نخيل معتمدة</div>
      </div>
      <div class="stat-card-home">
        <div class="stat-number">+<?= $totalInvestors ?></div>
        <div class="stat-label" style="color:rgba(255,255,255,0.8);">مستثمر</div>
      </div>
      <div class="stat-card-home">
        <div class="stat-number">+<?= number_format($txVolume / 1000000, 1) ?> م</div>
        <div class="stat-label" style="color:rgba(255,255,255,0.8);">ريال استثمارات</div>
      </div>
      <div class="stat-card-home">
        <div class="stat-number">+<?= $totalUsers ?></div>
        <div class="stat-label" style="color:rgba(255,255,255,0.8);">مستخدم مسجل</div>
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="cta-section">
  <div class="container">
    <div class="cta-content">
      <h2 class="cta-title">انضم إلى قِنوان اليوم</h2>
      <p class="cta-text">سواء كنت مستثمراً تبحث عن فرص زراعية مجزية، أو مزارعاً ترغب في توسيع مزرعتك، فإن قِنوان هي شريكك المثالي</p>
      <div class="cta-buttons">
        <a href="register.php" class="btn-primary" style="width:auto;padding:12px 28px;border-radius:40px;">سجل كمستثمر</a>
        <a href="register.php" class="btn-outline-light">سجل كمزارع</a>
      </div>
    </div>
  </div>
</section>

<!-- Footer -->
<footer class="footer">
  <div class="footer-container">
    <div class="footer-column">
      <div class="footer-logo">
        <img src="images\logo.png" alt="قِنوان" style="height:44px;width:auto;border-radius:4px;"
             onerror="this.style.display='none';">
        <h3>قِنوان</h3>
      </div>
      <p class="footer-text">منصة رقمية شفافة تربط المستثمرين بأصحاب مزارع النخيل في المملكة العربية السعودية.</p>
    </div>
    <div class="footer-column">
      <h4>المنصة</h4>
      <ul>
        <li><a href="investor.php">لوحة المستثمر</a></li>
        <li><a href="Farmer.php">لوحة المزارع</a></li>
        <li><a href="register.php">ابدأ الآن</a></li>
      </ul>
    </div>
    <div class="footer-column">
      <h4>الشركة</h4>
      <ul>
        <li><a href="#about">من نحن</a></li>
        <li><a href="#">اتصل بنا</a></li>
        <li><a href="#">سياسة الخصوصية</a></li>
      </ul>
    </div>
    <div class="footer-column">
      <h4>تواصل معنا</h4>
      <ul>
        <li>info@qinwan.sa</li>
        <li>الرياض، المملكة العربية السعودية</li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <p>© ٢٠٢٦ قِنوان. جميع الحقوق محفوظة</p>
  </div>
</footer>
</body>
</html>