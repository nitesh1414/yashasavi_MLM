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

    /* genealogy tree: straight SVG connector lines between parent & child pills */
    function drawTreeLines() {
        var tree = document.querySelector('.tree[data-tree-lines]');
        if (!tree) { return; }
        var svg = tree.querySelector('svg.tree-lines');
        if (!svg) { return; }
        function posIn(el, ancestor) {
            var x = 0, y = 0, cur = el;
            while (cur && cur !== ancestor) {
                x += cur.offsetLeft;
                y += cur.offsetTop;
                cur = cur.offsetParent;
            }
            return { x: x, y: y };
        }
        var W = tree.offsetWidth, H = tree.offsetHeight;
        svg.setAttribute('width', W);
        svg.setAttribute('height', H);
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
        var NS = 'http://www.w3.org/2000/svg';
        var frag = document.createDocumentFragment();
        tree.querySelectorAll('li').forEach(function (li) {
            var ul = li.querySelector(':scope > ul');
            if (!ul) { return; }
            var pn = li.querySelector(':scope > .t-node');
            if (!pn) { return; }
            var pp = posIn(pn, tree);
            var px = pp.x + pn.offsetWidth / 2, py = pp.y + pn.offsetHeight;
            ul.querySelectorAll(':scope > li').forEach(function (cli) {
                var cn = cli.querySelector(':scope > .t-node');
                if (!cn) { return; }
                var cp = posIn(cn, tree);
                var line = document.createElementNS(NS, 'line');
                line.setAttribute('x1', px);
                line.setAttribute('y1', py);
                line.setAttribute('x2', cp.x + cn.offsetWidth / 2);
                line.setAttribute('y2', cp.y);
                frag.appendChild(line);
            });
        });
        while (svg.firstChild) { svg.removeChild(svg.firstChild); }
        svg.appendChild(frag);
    }

    /* member pill tooltip: tap toggles on touch devices */
    document.querySelectorAll('.tree .t-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            if (!window.matchMedia('(hover: none)').matches) { return; }
            var node = pill.closest('.t-node');
            var wasOpen = node.classList.contains('open');
            document.querySelectorAll('.tree .t-node.open').forEach(function (n) { n.classList.remove('open'); });
            if (!wasOpen) { node.classList.add('open'); }
        });
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.t-node')) {
            document.querySelectorAll('.tree .t-node.open').forEach(function (n) { n.classList.remove('open'); });
        }
    });

    /* genealogy tree zoom (fit the tree in one window) */
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
            drawTreeLines();
        });
    });
    /* auto-fit on load + resize, then draw the connector lines */
    var fitBtn = document.querySelector('[data-tree-zoom="fit"]');
    if (fitBtn) {
        var rz;
        window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(function () { fitBtn.click(); }, 150); });
        fitBtn.click();
    }
    drawTreeLines();
    setTimeout(drawTreeLines, 350); /* once more after fonts settle */
});
