<?php
require_once 'db_connect.php';
$pdo = getDB();
$approvedFarms = (int)$pdo->query("SELECT COUNT(*) FROM qw_farm WHERE farm_status='approved'")->fetchColumn();
$totalUsers    = (int)$pdo->query("SELECT COUNT(*) FROM qw_user")->fetchColumn();
$txVolume      = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM qw_transaction WHERE payment_status='paid'")->fetchColumn();
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

<!-- SVG sprites — أيقونات وزخارف -->
<svg style="display:none" xmlns="http://www.w3.org/2000/svg">
  <symbol id="palm" viewBox="0 0 40 50">
    <rect x="17" y="26" width="6" height="22" rx="3" fill="currentColor" opacity="0.5"/>
    <path d="M20 26 Q20 10 20 3" stroke="currentColor" stroke-width="5" stroke-linecap="round" fill="none"/>
    <path d="M20 20 Q30 12 38 8 Q30 18 22 23" fill="currentColor"/>
    <path d="M20 20 Q10 12 2 8 Q10 18 18 23" fill="currentColor"/>
    <path d="M20 22 Q32 18 36 14 Q28 21 21 24" fill="currentColor"/>
    <path d="M20 22 Q8 18 4 14 Q12 21 19 24" fill="currentColor"/>
  </symbol>
  <symbol id="icon-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path d="M5 12h14M12 5l7 7-7 7"/>
  </symbol>
  <symbol id="icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <polyline points="20 6 9 17 4 12"/>
  </symbol>
</svg>

<!-- شريط التنقل العلوي - نفس أسلوب farmer.html -->
<nav>
  <div class="nav-logo" onclick="window.location.href='index.html'">
    <img class="logo-img" src="logo.png" alt="قِنوان"
         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
    <div class="logo-fallback" style="display:none">ق</div>
    <div>
      <span class="logo-name">قِنوان</span>
      <span class="logo-sub">المنصة الرقمية للاستثمار الزراعي</span>
    </div>
  </div>
  <div class="nav-links">
    <a href="index.html" class="nav-link active">الرئيسية</a>
    <a href="investor.php" class="nav-link">المستثمر</a>
    <a href="Farmer.php" class="nav-link">المزارع</a>
    <a href="admin.php" class="nav-link">المشرف</a>
    <a href="login.php" class="nav-link">تسجيل الدخول</a>
    <a href="register.php" class="nav-link btn-nav">ابدأ الآن</a>
  </div>
</nav>

<!-- القسم الرئيسي (Hero) -->
<section class="hero-section">
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <div class="hero-icon">
     
    </div>
    <h1 class="hero-title">استثمر في مزارع النخيل<br>بثقة وشفافية</h1>
    <p class="hero-subtitle">منصة رقمية متكاملة تربط المستثمرين بأصحاب مزارع النخيل في المملكة العربية السعودية</p>
    <div class="hero-buttons">
      <a href="register.php" class="btn-primary">ابدأ الآن</a>
      <a href="#about" class="btn-outline">تعرف علينا</a>
    </div>
  </div>
</section>

<!-- قسم من نحن -->
<section class="about-section" id="about">
  <div class="container">
    <div class="section-header">
      <div class="section-icon">
        <svg width="40" height="50" viewBox="0 0 40 50" fill="var(--green-mid)">
          <use href="#palm"/>
        </svg>
      </div>
      <h2 class="section-title">ما هي قِنوان؟</h2>
      <div class="title-ornament">
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

<!-- قسم المميزات -->
<section class="features-section">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title">لماذا قِنوان؟</h2>
      <div class="title-ornament">
        <div class="orn-line" style="width:40px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-dot"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:40px"></div>
      </div>
    </div>
    <div class="features-grid">
      <div class="feature-card">
        <div class="feature-icon">●</div>
        <h3 class="feature-title">استثمار شفاف</h3>
        <p class="feature-text">يتم تتبع كل استثمار بتوثيق واضح وتقارير دورية عن التقدم والعوائد المالية.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">●</div>
        <h3 class="feature-title">تواصل مباشر</h3>
        <p class="feature-text">تواصل مباشر مع مالك المزرعة. بدون وسطاء أو رسوم خفية — شراكات نزيهة فقط.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">●</div>
        <h3 class="feature-title">عوائد مستدامة</h3>
        <p class="feature-text">النخيل محصول طويل الأمد ومستدام. استثمر في زراعة تنمو مع الوقت.</p>
      </div>
    </div>
  </div>
</section>

<!-- قسم خطوات العمل -->
<section class="steps-section">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title">كيف تعمل المنصة؟</h2>
      <div class="title-ornament">
        <div class="orn-line" style="width:60px"></div>
        <div class="orn-diamond"></div>
        <div class="orn-dot"></div>
        <div class="orn-diamond"></div>
        <div class="orn-line" style="width:24px"></div>
      </div>
    </div>
    <div class="steps-grid">
      <div class="step-card">
        <div class="step-number">٠١</div>
        <h3 class="step-title">إنشاء حساب</h3>
        <p class="step-text">سجل كمستثمر أو مالك مزرعة في دقائق</p>
      </div>
      <div class="step-card">
        <div class="step-number">٠٢</div>
        <h3 class="step-title">استكشاف واختيار</h3>
        <p class="step-text">استعرض المزارع المتاحة، اطلع على التفاصيل، وأضف إلى قائمة رغباتك</p>
      </div>
      <div class="step-card">
        <div class="step-number">٠٣</div>
        <h3 class="step-title">تقديم طلب استثمار</h3>
        <p class="step-text">اختر المبلغ المناسب وأرسل طلبك إلى مالك المزرعة</p>
      </div>
      <div class="step-card">
        <div class="step-number">٠٤</div>
        <h3 class="step-title">تتبع ونمو</h3>
        <p class="step-text">تابع استثماراتك، استلم التحديثات، وشاهد محفظتك تنمو</p>
      </div>
    </div>
  </div>
</section>

<!-- قسم الإحصائيات -->
<section class="stats-section">
  <div class="container">
    <div class="stats-grid-home">
      <div class="stat-card-home">
        <div class="stat-number">+٥٠</div>
        <div class="stat-label">مزرعة نخيل</div>
      </div>
      <div class="stat-card-home">
        <div class="stat-number">+١,٠٠٠</div>
        <div class="stat-label">مستثمر</div>
      </div>
      <div class="stat-card-home">
        <div class="stat-number">+١٠ م</div>
        <div class="stat-label">ريال استثمارات</div>
      </div>
      <div class="stat-card-home">
        <div class="stat-number">+٩٥٪</div>
        <div class="stat-label">رضا العملاء</div>
      </div>
    </div>
  </div>
</section>

<!-- قسم الدعوة للتسجيل -->
<section class="cta-section">
  <div class="container">
    <div class="cta-content">
      <h2 class="cta-title">انضم إلى قِنوان اليوم</h2>
      <p class="cta-text">سواء كنت مستثمراً تبحث عن فرص زراعية مجزية، أو مزارعاً ترغب في توسيع مزرعتك، فإن قِنوان هي شريكك المثالي</p>
      <div class="cta-buttons">
        <a href="register.php" class="btn-primary">سجل كمستثمر</a>
        <a href="register.php" class="btn-outline-light">سجل كمزارع</a>
      </div>
    </div>
  </div>
</section>

<!-- تذييل الصفحة (Footer) -->
<footer class="footer">
  <div class="footer-container">
    <div class="footer-column">
      <div class="footer-logo">
        <div class="logo-fallback" style="display:flex;">ق</div>
        <h3>قِنوان</h3>
      </div>
      <p class="footer-text">منصة رقمية شفافة تربط المستثمرين بأصحاب مزارع النخيل في المملكة العربية السعودية.</p>
    </div>
    <div class="footer-column">
      <h4>المنصة</h4>
      <ul>
        <li><a href="investor.php">لوحة المستثمر</a></li>
        <li><a href="Farmer.php">لوحة المزارع</a></li>
        <li><a href="index.html#farms">استكشاف المزارع</a></li>
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

<script src="script.js"></script>

</body>
</html>