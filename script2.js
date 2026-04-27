/* ══════════════════════════════════════════════════════
   قِنوان — Scroll Reveal & Micro-Interactions
   Include this script in all pages:
   <script src="qinwan-animations.js" defer></script>
   ══════════════════════════════════════════════════════ */

(function () {
  'use strict';


  /* ─ Hero logo wrap — يضيف اللوجو في بانر الهيرو تلقائياً ── */
  const heroContent = document.querySelector('.hero-content');
  if (heroContent && !heroContent.querySelector('.hero-logo-wrap')) {
    // حاول مسارين: images/logo.png (الموصى به) أو logo.png (القديم)
    const logoSrc = document.querySelector('.logo-img')
                    ? (document.querySelector('.logo-img').src || 'logo.png')
                    : 'logo.png';
    const wrap = document.createElement('div');
    wrap.className = 'hero-logo-wrap';
    const img = document.createElement('img');
    img.src = logoSrc;
    img.alt = 'قِنوان';
    img.style.cssText = 'height:88px;width:auto;object-fit:contain;filter:brightness(1.1) drop-shadow(0 3px 14px rgba(0,0,0,.22))';
    img.onerror = function() { this.parentElement.style.display = 'none'; };
    wrap.appendChild(img);
    heroContent.insertBefore(wrap, heroContent.firstChild);
  }

  /* ─ Scroll-reveal observer ─────────────────────────── */
  const io = new IntersectionObserver(
    (entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) {
          e.target.classList.add('visible');
          io.unobserve(e.target);
        }
      });
    },
    { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
  );

  /* Mark every major section element for reveal */
  const REVEAL = [
    '.section-card', '.farm-card', '.stat-card', '.admin-stat-card',
    '.admin-panel-card', '.admin-record-card', '.feature-card',
    '.step-card', '.request-card', '.wishlist-item', '.cart-item',
    '.update-item', '.investment-item', '.section-header',
    '.form-card', '.about-text', '.hero-content',
  ];

  document.querySelectorAll(REVEAL.join(',')).forEach((el) => {
    el.classList.add('fade-in-section');
    io.observe(el);
  });

  /* ─ Nav active link highlight ──────────────────────── */
  const current = location.pathname.split('/').pop();
  document.querySelectorAll('.nav-link').forEach((a) => {
    const href = (a.getAttribute('href') || '').split('/').pop().split('?')[0];
    if (href && href === current) a.classList.add('active');
  });

  /* ─ Smooth button ripple effect ───────────────────── */
  document.querySelectorAll(
    '.btn-primary,.btn-explore,.btn-invest,.btn-accept,.btn-nav,.auth-btn,.filter-btn.active'
  ).forEach((btn) => {
    btn.addEventListener('click', function (e) {
      const r = document.createElement('span');
      const rect = btn.getBoundingClientRect();
      const size = Math.max(rect.width, rect.height);
      r.style.cssText = `
        position:absolute; border-radius:50%; pointer-events:none;
        width:${size}px; height:${size}px;
        left:${e.clientX - rect.left - size / 2}px;
        top:${e.clientY - rect.top - size / 2}px;
        background:rgba(255,255,255,.25);
        transform:scale(0); animation:ripple .5s ease-out forwards;
      `;
      if (getComputedStyle(btn).position === 'static') btn.style.position = 'relative';
      btn.style.overflow = 'hidden';
      btn.appendChild(r);
      setTimeout(() => r.remove(), 600);
    });
  });

  /* ─ Inject ripple keyframe ─────────────────────────── */
  if (!document.getElementById('qw-ripple-style')) {
    const s = document.createElement('style');
    s.id = 'qw-ripple-style';
    s.textContent = '@keyframes ripple{to{transform:scale(2.5);opacity:0}}';
    document.head.appendChild(s);
  }

  /* ─ Admin stats counter animation ─────────────────── */
  function animateCounter(el, target, duration) {
    const start = performance.now();
    const isFloat = String(target).includes('.');
    (function step(now) {
      const p = Math.min((now - start) / duration, 1);
      const ease = 1 - Math.pow(1 - p, 3); // ease-out cubic
      const val = isFloat
        ? (ease * target).toFixed(1)
        : Math.round(ease * target).toLocaleString('ar-SA');
      el.textContent = val;
      if (p < 1) requestAnimationFrame(step);
    })(start);
  }

  /* Trigger counters when stat cards enter viewport */
  const statIO = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (!e.isIntersecting) return;
      const p = e.target.querySelector('p');
      if (!p) return;
      const raw = p.textContent.replace(/[,٬\s]/g, '').replace(/[٠-٩]/g, d => String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48));
      const num = parseFloat(raw);
      if (!isNaN(num) && num > 0) animateCounter(p, num, 1200);
      statIO.unobserve(e.target);
    });
  }, { threshold: 0.4 });

  document.querySelectorAll('.admin-stat-card, .stat-card').forEach((c) => statIO.observe(c));

  /* ─ Table row stagger on load ──────────────────────── */
  document.querySelectorAll('.admin-table tbody tr').forEach((tr, i) => {
    tr.style.opacity = '0';
    tr.style.transform = 'translateY(10px)';
    tr.style.transition = 'opacity .3s ease, transform .3s ease';
    setTimeout(() => {
      tr.style.opacity = '1';
      tr.style.transform = 'translateY(0)';
    }, 60 + i * 40);
  });

  /* ─ Filter buttons smooth switch ──────────────────── */
  document.querySelectorAll('.filter-bar').forEach((bar) => {
    bar.addEventListener('click', (e) => {
      const btn = e.target.closest('.filter-btn');
      if (!btn) return;
      bar.querySelectorAll('.filter-btn').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
    });
  });

  /* ─ Sticky nav shrink on scroll ───────────────────── */
  const nav = document.querySelector('nav');
  if (nav) {
    let lastScroll = 0;
    window.addEventListener('scroll', () => {
      const y = window.scrollY;
      if (y > 80) {
        nav.style.height = '62px';
        nav.style.boxShadow = '0 4px 24px rgba(62,44,30,.12)';
      } else {
        nav.style.height = '';
        nav.style.boxShadow = '';
      }
      lastScroll = y;
    }, { passive: true });
  }

})();
