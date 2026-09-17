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
