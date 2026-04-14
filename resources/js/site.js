import Alpine from 'alpinejs';
import intersect from '@alpinejs/intersect';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';
Alpine.plugin(intersect);
gsap.registerPlugin(ScrollTrigger);

// ── Lenis smooth scroll ──────────────────────────────────────
const lenis = new Lenis({
  duration: 1.2,
  easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
  smoothWheel: true,
});

gsap.ticker.add((time) => lenis.raf(time * 1000));
gsap.ticker.lagSmoothing(0);
lenis.on('scroll', ScrollTrigger.update);

// ── Hero entrance ────────────────────────────────────────────
function initHero() {
  const eyebrow = document.querySelector('.hero-eyebrow');
  const words   = document.querySelectorAll('.reveal-word');
  const sub     = document.querySelector('.hero-subtitle');
  const btns    = document.querySelector('.hero-btns');
  const stats   = document.querySelector('.hero-stats');

  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, delay: 0.1 });

  if (eyebrow) tl.from(eyebrow, { y: 16, opacity: 0, duration: 0.55 });
  if (words.length) tl.from(words, { y: 64, opacity: 0, stagger: 0.055, duration: 0.75 }, '-=0.25');
  if (sub)    tl.from(sub,    { y: 20, opacity: 0, duration: 0.55 }, '-=0.4');
  if (btns)   tl.from(btns,   { y: 18, opacity: 0, duration: 0.5  }, '-=0.35');
  if (stats)  tl.from(stats,  { y: 16, opacity: 0, duration: 0.5  }, '-=0.25');
}

// ── Counter animation ────────────────────────────────────────
function initCounters() {
  document.querySelectorAll('[data-counter]').forEach(el => {
    const target = parseFloat(el.dataset.counter);
    const suffix = el.dataset.suffix ?? '';
    const obj = { val: 0 };

    ScrollTrigger.create({
      trigger: el,
      start: 'top 88%',
      once: true,
      onEnter() {
        gsap.to(obj, {
          val: target,
          duration: 2,
          ease: 'power2.out',
          onUpdate() { el.textContent = Math.round(obj.val) + suffix; },
        });
      },
    });
  });
}

// ── Scroll reveals ───────────────────────────────────────────
function initReveals() {
  // Single element reveals
  gsap.utils.toArray('[data-reveal]').forEach(el => {
    gsap.from(el, {
      y: 36,
      opacity: 0,
      duration: 0.75,
      ease: 'power3.out',
      scrollTrigger: { trigger: el, start: 'top 87%', once: true },
    });
  });

  // Stagger grid reveals
  gsap.utils.toArray('[data-stagger]').forEach(container => {
    const items = container.querySelectorAll('[data-stagger-item]');
    if (!items.length) return;
    gsap.from(items, {
      y: 48,
      opacity: 0,
      stagger: 0.09,
      duration: 0.7,
      ease: 'power3.out',
      scrollTrigger: { trigger: container, start: 'top 82%', once: true },
    });
  });
}

// ── Eyebrow line reveal ──────────────────────────────────────
function initLineReveals() {
  gsap.utils.toArray('.eyebrow-line').forEach(line => {
    gsap.from(line, {
      scaleX: 0,
      transformOrigin: 'left center',
      duration: 0.55,
      ease: 'power3.out',
      scrollTrigger: { trigger: line, start: 'top 88%', once: true },
    });
  });
}

// ── Parallax ────────────────────────────────────────────────
function initParallax() {
  gsap.utils.toArray('[data-parallax]').forEach(el => {
    const speed = parseFloat(el.dataset.parallax) || 0.15;
    gsap.to(el, {
      yPercent: speed * 80,
      ease: 'none',
      scrollTrigger: {
        trigger: el.closest('section') || el,
        start: 'top bottom',
        end: 'bottom top',
        scrub: true,
      },
    });
  });
}

// ── Magnetic buttons ────────────────────────────────────────
function initMagnetic() {
  document.querySelectorAll('[data-magnetic]').forEach(btn => {
    btn.addEventListener('mousemove', (e) => {
      const rect = btn.getBoundingClientRect();
      const x = e.clientX - rect.left - rect.width / 2;
      const y = e.clientY - rect.top - rect.height / 2;
      gsap.to(btn, { x: x * 0.32, y: y * 0.32, duration: 0.28, ease: 'power2.out' });
    });
    btn.addEventListener('mouseleave', () => {
      gsap.to(btn, { x: 0, y: 0, duration: 0.65, ease: 'elastic.out(1, 0.4)' });
    });
  });
}

// ── Cursor glow (hero) ───────────────────────────────────────
function initCursorGlow() {
  const hero = document.querySelector('.hero');
  const glow = document.querySelector('.hero-cursor-glow');
  if (!hero || !glow) return;

  gsap.set(glow, { x: '-50%', y: '-50%', left: '50%', top: '50%' });

  hero.addEventListener('mouseenter', () => {
    gsap.to(glow, { opacity: 1, duration: 0.6, ease: 'power2.out' });
  });
  hero.addEventListener('mouseleave', () => {
    gsap.to(glow, { opacity: 0, duration: 0.4 });
  });
  hero.addEventListener('mousemove', (e) => {
    const rect = hero.getBoundingClientRect();
    gsap.to(glow, {
      left: e.clientX - rect.left,
      top: e.clientY - rect.top,
      duration: 0.7,
      ease: 'power2.out',
    });
  });
}

// ── Torch / spotlight reveal op hero secties ─────────────────
function initHeroParallax() {
  document.querySelectorAll('.js-torch-hero').forEach(hero => {
    const mask = hero.querySelector('.js-torch-mask');
    if (!mask) return;

    const RADIUS  = 340;   // px straal van het licht-gat
    const DARK    = 'rgba(22,10,6,0.96)';
    const LERP    = 0.09;  // volgsnelheid (0-1, hoger = snapper)

    let cur = { x: 0, y: 0 };
    let tgt = { x: 0, y: 0 };
    let running = false;

    // Hulpfunctie: bouw de background-string op
    function paint(x, y) {
      mask.style.background = [
        `radial-gradient(circle ${RADIUS}px at ${x}px ${y}px,`,
        `  transparent 0%,`,
        `  transparent 30%,`,
        `  rgba(22,10,6,0.55) 55%,`,
        `  ${DARK} 75%`,
        `)`,
      ].join(' ');
    }

    // Initieel: volledig donker (geen cursor)
    mask.style.background = DARK;

    function tick() {
      cur.x += (tgt.x - cur.x) * LERP;
      cur.y += (tgt.y - cur.y) * LERP;
      paint(cur.x, cur.y);
      if (running) requestAnimationFrame(tick);
    }

    hero.addEventListener('mouseenter', (e) => {
      const r = hero.getBoundingClientRect();
      // Spring direct naar cursor — geen sweep vanuit hoek
      cur.x = tgt.x = e.clientX - r.left;
      cur.y = tgt.y = e.clientY - r.top;
      paint(cur.x, cur.y);
      running = true;
      requestAnimationFrame(tick);
    });

    hero.addEventListener('mousemove', (e) => {
      const r = hero.getBoundingClientRect();
      tgt.x = e.clientX - r.left;
      tgt.y = e.clientY - r.top;
    });

    hero.addEventListener('mouseleave', () => {
      // Fade terug naar volledig donker
      running = false;
      mask.style.transition = 'background 0.9s ease';
      mask.style.background = DARK;
      setTimeout(() => { mask.style.transition = ''; }, 950);
    });

    // Touch
    hero.addEventListener('touchmove', (e) => {
      const r = hero.getBoundingClientRect();
      tgt.x = e.touches[0].clientX - r.left;
      tgt.y = e.touches[0].clientY - r.top;
      if (!running) {
        cur.x = tgt.x; cur.y = tgt.y;
        running = true;
        requestAnimationFrame(tick);
      }
    }, { passive: true });

    hero.addEventListener('touchend', () => {
      running = false;
      mask.style.transition = 'background 0.9s ease';
      mask.style.background = DARK;
      setTimeout(() => { mask.style.transition = ''; }, 950);
    });
  });
}

// ── Marquee pause on hover ───────────────────────────────────
function initMarquee() {
  document.querySelectorAll('.marquee-strip').forEach(strip => {
    const track = strip.querySelector('.marquee-track');
    if (!track) return;
    strip.addEventListener('mouseenter', () => {
      gsap.to(track, { playbackRate: 0, duration: 0.4 });
      track.style.animationPlayState = 'paused';
    });
    strip.addEventListener('mouseleave', () => {
      track.style.animationPlayState = 'running';
    });
  });
}

// ── Project card image parallax ──────────────────────────────
function initCardParallax() {
  gsap.utils.toArray('.project-card-img img').forEach(img => {
    gsap.fromTo(img,
      { yPercent: -6 },
      {
        yPercent: 6,
        ease: 'none',
        scrollTrigger: {
          trigger: img,
          start: 'top bottom',
          end: 'bottom top',
          scrub: true,
        },
      }
    );
  });
}

// ── About section split reveal ───────────────────────────────
function initAboutReveal() {
  const text = document.querySelector('.about-text');
  const img  = document.querySelector('.about-img-wrapper');
  if (!text || !img) return;

  const tl = gsap.timeline({
    scrollTrigger: { trigger: text, start: 'top 78%', once: true },
  });
  tl.from(text, { x: -40, opacity: 0, duration: 0.8, ease: 'power3.out' });
  tl.from(img,  { x: 40,  opacity: 0, duration: 0.8, ease: 'power3.out' }, '-=0.6');
}

// ── Horizontal scroll hint for mobile ───────────────────────
function initScrollProgress() {
  const line = document.querySelector('.scroll-progress-line');
  if (!line) return;
  gsap.to(line, {
    scaleX: 1,
    ease: 'none',
    scrollTrigger: { trigger: document.body, start: 'top top', end: 'bottom bottom', scrub: true },
  });
}

// ── Custom cursor ────────────────────────────────────────────
function initCursor() {
  const dot  = document.querySelector('.cursor-dot');
  const ring = document.querySelector('.cursor-ring');
  if (!dot || !ring) return;

  let mx = window.innerWidth / 2, my = window.innerHeight / 2;
  let rx = mx, ry = my;

  document.addEventListener('mousemove', (e) => {
    mx = e.clientX; my = e.clientY;
    gsap.to(dot, { x: mx, y: my, duration: 0.08, ease: 'none' });
  });

  // ring lags slightly behind
  gsap.ticker.add(() => {
    rx += (mx - rx) * 0.12;
    ry += (my - ry) * 0.12;
    gsap.set(ring, { x: rx, y: ry });
  });

  // hover states
  document.querySelectorAll('a, button, [data-magnetic]').forEach(el => {
    el.addEventListener('mouseenter', () => document.body.classList.add('cursor-link'));
    el.addEventListener('mouseleave', () => document.body.classList.remove('cursor-link'));
  });

  document.querySelectorAll('.proj-card, .bento-card, .team-card').forEach(el => {
    el.addEventListener('mouseenter', () => document.body.classList.add('cursor-hover'));
    el.addEventListener('mouseleave', () => document.body.classList.remove('cursor-hover'));
  });
}

// ── Interactive blob hero ─────────────────────────────────────
function initHeroGradient() {
  const blobA = document.getElementById('blob-a');
  const blobB = document.getElementById('blob-b');
  const blobC = document.getElementById('blob-c');
  const hero  = document.querySelector('.hero');
  if (!blobA || !hero) return;

  const W = () => hero.offsetWidth;
  const H = () => hero.offsetHeight;

  // Huidige posities (pixels, centerpunt van blob)
  let ax = W() * 0.55, ay = H() * 0.45;
  let bx = W() * 0.25, by = H() * 0.35;
  let cx = W() * 0.50, cy = H() * 0.75;

  // Doelposities
  let tax = ax, tay = ay;
  let tbx = bx, tby = by;

  let driftT  = 0;
  let hasMouse = false;

  function place(el, x, y) {
    const w = el.offsetWidth;
    const h = el.offsetHeight;
    el.style.transform = `translate(${x - w/2}px, ${y - h/2}px)`;
  }

  // Genormaliseerde muispositie (0–1), standaard midden
  let mx = 0.5, my = 0.5;

  hero.addEventListener('mousemove', (e) => {
    const r = hero.getBoundingClientRect();
    mx = (e.clientX - r.left) / r.width;
    my = (e.clientY - r.top)  / r.height;
  });
  hero.addEventListener('touchmove', (e) => {
    const r = hero.getBoundingClientRect();
    mx = (e.touches[0].clientX - r.left) / r.width;
    my = (e.touches[0].clientY - r.top)  / r.height;
  }, { passive: true });

  function tick() {
    driftT += 0.005;

    // Drift-banen per blob, muis geeft flinke push (±40%)
    const inf = 0.40;

    tax = W() * (0.58 + Math.sin(driftT * 0.45) * 0.18 + (mx - 0.5) * inf);
    tay = H() * (0.42 + Math.cos(driftT * 0.38) * 0.16 + (my - 0.5) * inf);

    tbx = W() * (0.28 + Math.sin(driftT * 0.32 + 2) * 0.18 - (mx - 0.5) * inf);
    tby = H() * (0.38 + Math.cos(driftT * 0.40 + 1) * 0.16 - (my - 0.5) * inf);

    // Blob A — reageert snel
    ax += (tax - ax) * 0.09;
    ay += (tay - ay) * 0.09;

    // Blob B — iets trager, tegengesteld
    bx += (tbx - bx) * 0.06;
    by += (tby - by) * 0.06;

    // Blob C — diagonaal tegengesteld
    cx += (W() * (0.50 + Math.sin(driftT * 0.25 + 3) * 0.15 - (mx - 0.5) * inf * 0.6) - cx) * 0.05;
    cy += (H() * (0.70 + Math.cos(driftT * 0.20 + 1) * 0.14 - (my - 0.5) * inf * 0.6) - cy) * 0.05;

    place(blobA, ax, ay);
    place(blobB, bx, by);
    place(blobC, cx, cy);

    requestAnimationFrame(tick);
  }

  place(blobA, ax, ay);
  place(blobB, bx, by);
  place(blobC, cx, cy);
  requestAnimationFrame(tick);
}

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initHeroGradient();
  initHero();
  initCounters();
  initReveals();
  initLineReveals();
  initParallax();
  initMagnetic();
  initCursorGlow();
  initHeroParallax();
  initMarquee();
  initCardParallax();
  initAboutReveal();
  initScrollProgress();
  initCursor();

  // Give Alpine time to mount before refreshing triggers
  setTimeout(() => ScrollTrigger.refresh(), 600);
});

// ── Alpine components ────────────────────────────────────────

Alpine.data('siteHeader', () => ({
  scrolled: false,
  onDark: true,   // true = header is over dark bg → use white logo/links
  mobileOpen: false,
  init() {
    this.$nextTick(() => { this.update(); });
    window.addEventListener('scroll', () => this.update(), { passive: true });
  },
  update() {
    this.scrolled = window.scrollY > 40;
    if (this.scrolled) { this.onDark = false; return; }
    // Find which section sits behind the header (top 100px)
    const sections = document.querySelectorAll('[data-header-bg]');
    for (const s of sections) {
      const r = s.getBoundingClientRect();
      if (r.top <= 100 && r.bottom > 0) {
        this.onDark = s.dataset.headerBg === 'dark';
        return;
      }
    }
    this.onDark = true; // default: assume dark hero
  },
  toggle() {
    this.mobileOpen = !this.mobileOpen;
    document.body.style.overflow = this.mobileOpen ? 'hidden' : '';
  },
  close() {
    this.mobileOpen = false;
    document.body.style.overflow = '';
  },
}));

Alpine.data('reveal', () => ({ visible: false }));

Alpine.data('megaMenu', () => ({
  active: null,
  hoveredService: null,
  closeTimer: null,
  services: [
    { name: 'Branding',           slug: 'branding',         desc: 'Van logo tot merkstrategie',        image: '/assets/team/team-groep-grijs.jpg' },
    { name: 'Marketing',          slug: 'marketing',        desc: 'Gerichte campagnes, meer bereik',   image: '/assets/team/team-tafel.jpg' },
    { name: 'Strategie',          slug: 'strategie',        desc: 'Positie, richting en onderbouwing', image: '/assets/team/team-duo-tablet.jpg' },
    { name: 'Fotografie & Video', slug: 'fotografie-video', desc: 'Beeldmateriaal dat staat',          image: '/assets/team/team-camera.jpg' },
    { name: 'Websites',           slug: 'websites',         desc: 'Snel, toegankelijk en merkgericht', image: '/assets/team/team-laptop.jpg' },
    { name: 'Drukwerk',           slug: 'drukwerk',         desc: 'Print in lijn met jouw huisstijl',  image: '/assets/team/team-document.jpg' },
  ],
  open(name) {
    clearTimeout(this.closeTimer);
    this.active = name;
    if (!this.hoveredService) this.hoveredService = this.services[0];
  },
  closeMenu() {
    this.closeTimer = setTimeout(() => { this.active = null; }, 120);
  },
  hoverService(service) {
    this.hoveredService = service;
  },
}));

Alpine.data('contactForm', () => ({
  name: '', email: '', message: '',
  sending: false, sent: false, error: false,

  async submit() {
    if (!this.name || !this.email || !this.message) return;
    this.sending = true;
    this.error = false;
    try {
      const res = await fetch('/contact/verstuur', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ name: this.name, email: this.email, message: this.message }),
      });
      if (res.ok) {
        this.sent = true;
        this.name = this.email = this.message = '';
      } else { this.error = true; }
    } catch { this.error = true; }
    finally { this.sending = false; }
  },
}));

window.Alpine = Alpine;
Alpine.start();
