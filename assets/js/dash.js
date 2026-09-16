/* Yashasavi — dashboard JS (user/admin/superadmin) */
document.addEventListener('DOMContentLoaded', function () {

    /* sidebar toggle (mobile) */
    var mt = document.querySelector('.menu-toggle');
    var sb = document.querySelector('.sidebar');
    if (mt && sb) {
        mt.addEventListener('click', function (e) { e.stopPropagation(); sb.classList.toggle('open'); });
        document.addEventListener('click', function (e) {
            if (sb.classList.contains('open') && !sb.contains(e.target) && e.target !== mt) {
                sb.classList.remove('open');
            }
        });
    }

    /* confirm dialogs */
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!window.confirm(el.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    /* flash auto-dismiss */
    document.querySelectorAll('.alert').forEach(function (a) {
        setTimeout(function () { a.style.transition = 'opacity .5s'; a.style.opacity = '0'; setTimeout(function () { a.remove(); }, 500); }, 6000);
    });

    /* image upload preview */
    document.querySelectorAll('input[type=file][data-preview]').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var img = document.getElementById(inp.dataset.preview);
            if (img && inp.files && inp.files[0]) {
                img.src = URL.createObjectURL(inp.files[0]);
                img.style.display = 'block';
            }
        });
    });

    /* generic amount/percent inputs: numbers only */
    document.querySelectorAll('input[data-numeric]').forEach(function (inp) {
        inp.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9.]/g, '');
        });
    });

    /* sponsor lookup on panel add-member forms */
    var spField = document.getElementById('sponsor');
    var spName = document.getElementById('sponsor_name');
    if (spField && spName && spField.dataset.sponsorApi) {
        var debounce;
        spField.addEventListener('input', function () {
            clearTimeout(debounce);
            var v = spField.value.trim();
            if (v.length < 3) { spName.textContent = ''; return; }
            debounce = setTimeout(function () {
                fetch(spField.dataset.sponsorApi + '?action=sponsor&sid=' + encodeURIComponent(v))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        spName.textContent = d.found ? ('✓ ' + d.name) : '✗ Sponsor not found';
                    })
                    .catch(function () { spName.textContent = ''; });
            }, 350);
        });
    }

    /* genealogy tree zoom (fit the 5-level tree in one window) */
    document.querySelectorAll('[data-tree-zoom]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tree = document.querySelector('.tree[data-tree-root]');
            var wrap = document.querySelector('.tree-wrap');
            if (!tree || !wrap) { return; }
            var natural = parseFloat(tree.dataset.naturalWidth || '0');
            if (!natural) {
                tree.style.zoom = 1;
                tree.dataset.naturalWidth = natural = tree.scrollWidth || tree.getBoundingClientRect().width;
            }
            var cur = parseFloat(tree.dataset.zoom || '1');
            if (btn.dataset.treeZoom === 'in') { cur = Math.min(1.4, cur + 0.15); }
            else if (btn.dataset.treeZoom === 'out') { cur = Math.max(0.2, cur - 0.15); }
            else { cur = Math.min(1, (wrap.clientWidth - 16) / natural); }
            tree.dataset.zoom = cur;
            tree.style.zoom = cur;
        });
    });
    /* auto-fit on load + resize */
    var fitBtn = document.querySelector('[data-tree-zoom="fit"]');
    if (fitBtn) {
        var rz;
        window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(function () { fitBtn.click(); }, 150); });
        fitBtn.click();
    }
});
