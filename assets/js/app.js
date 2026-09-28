/* Yashasavi — public site JS */
document.addEventListener('DOMContentLoaded', function () {

    /* mobile nav */
    var toggle = document.querySelector('.nav-toggle');
    var menu = document.querySelector('.nav-menu');
    var overlay = document.querySelector('.nav-overlay');
    var closeBtn = document.querySelector('.nav-close');
    function setNav(open) {
        if (!menu) { return; }
        menu.classList.toggle('open', open);
        if (overlay) { overlay.classList.toggle('open', open); }
        document.body.classList.toggle('nav-open', open);
        if (toggle) {
            toggle.textContent = open ? '✕' : '☰';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        }
    }
    if (toggle && menu) {
        toggle.addEventListener('click', function () { setNav(!menu.classList.contains('open')); });
        if (closeBtn) { closeBtn.addEventListener('click', function () { setNav(false); }); }
        if (overlay) { overlay.addEventListener('click', function () { setNav(false); }); }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setNav(false); } });
        menu.addEventListener('click', function (e) { if (e.target.closest('a')) { setNav(false); } });
    }

    /* hero slider */
    var slides = document.querySelectorAll('.hero-slide');
    var dotsWrap = document.querySelector('.hero-dots');
    if (slides.length > 1) {
        var idx = 0, timer;
        if (dotsWrap) {
            slides.forEach(function (_, i) {
                var b = document.createElement('button');
                if (i === 0) b.classList.add('active');
                b.addEventListener('click', function () { go(i); restart(); });
                dotsWrap.appendChild(b);
            });
        }
        function go(n) {
            idx = (n + slides.length) % slides.length;
            slides.forEach(function (s, i) { s.classList.toggle('active', i === idx); });
            if (dotsWrap) {
                dotsWrap.querySelectorAll('button').forEach(function (d, i) { d.classList.toggle('active', i === idx); });
            }
        }
        function restart() { clearInterval(timer); timer = setInterval(function () { go(idx + 1); }, 5500); }
        restart();
    }

    /* flash auto-dismiss */
    document.querySelectorAll('.alert').forEach(function (a) {
        setTimeout(function () { a.style.transition = 'opacity .5s'; a.style.opacity = '0'; setTimeout(function () { a.remove(); }, 500); }, 6000);
    });

    /* product tabs */
    document.querySelectorAll('.pd-tabs').forEach(function (tabs) {
        tabs.querySelectorAll('.tab-head button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                tabs.querySelectorAll('.tab-head button').forEach(function (b) { b.classList.remove('active'); });
                tabs.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                tabs.querySelector('.tab-pane[data-tab="' + btn.dataset.tab + '"]').classList.add('active');
            });
        });
    });

    /* sponsor lookup on register form */
    var sponsorField = document.getElementById('sponsor');
    var sponsorName = document.getElementById('sponsor_name');
    if (sponsorField && sponsorName) {
        var debounce;
        sponsorField.addEventListener('input', function () {
            clearTimeout(debounce);
            var v = sponsorField.value.trim();
            if (v.length < 3) { sponsorName.textContent = ''; sponsorName.style.color = ''; return; }
            debounce = setTimeout(function () {
                fetch('api.php?action=sponsor&sid=' + encodeURIComponent(v))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.found) {
                            sponsorName.textContent = '✓ ' + d.name;
                            sponsorName.style.color = '#2e7d32';
                        } else {
                            sponsorName.textContent = '✗ Sponsor not found';
                            sponsorName.style.color = '#c62828';
                        }
                    })
                    .catch(function () { sponsorName.textContent = ''; });
            }, 350);
        });
    }
});

/* ---- corporate interactive layer: reveal on scroll, counters, navbar ---- */
document.addEventListener('DOMContentLoaded', function () {
    /* tag section children for reveal */
    var sections = document.querySelectorAll('.section .container, .slogan-band .container, .stats-band .container');
    sections.forEach(function (sec) {
        var groups = sec.querySelectorAll('.income-grid, .product-grid, .cat-grid, .steps-grid, .testi-grid, .award-grid, .features, .about-grid, .plan-cols, .plan-table-wrap, .car-fund-band, .sec-head, .cta-band');
        groups.forEach(function (g, i) {
            g.classList.add('reveal', 'd' + Math.min(3, i % 4).toString().replace('0', ''));
        });
    });

    var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
            if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
        });
    }, { threshold: 0.12 }) : null;

    document.querySelectorAll('.reveal').forEach(function (el) {
        if (io) { io.observe(el); } else { el.classList.add('in'); }
    });

    /* animated number counters (e.g. "5000+" -> counts up) */
    function animateCount(el) {
        var m = el.textContent.match(/^([\d,]+)(\+?)$/);
        if (!m) { return; }
        var target = parseInt(m[1].replace(/,/g, ''), 10);
        if (isNaN(target) || target === 0) { return; }
        var suffix = m[2] || '';
        var start = null, dur = 1400;
        function step(ts) {
            if (!start) { start = ts; }
            var p = Math.min(1, (ts - start) / dur);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(target * eased).toLocaleString('en-IN') + (p === 1 ? suffix : '');
            if (p < 1) { requestAnimationFrame(step); }
        }
        requestAnimationFrame(step);
    }
    var cio = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
            if (en.isIntersecting) { animateCount(en.target); cio.unobserve(en.target); }
        });
    }, { threshold: 0.4 }) : null;
    document.querySelectorAll('.stats-grid b').forEach(function (el) {
        if (cio) { cio.observe(el); }
    });

    /* navbar shadow on scroll */
    var nav = document.querySelector('.navbar');
    if (nav) {
        var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 8); };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }
});
