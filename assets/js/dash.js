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

    /* genealogy tree: elbow connectors — straight vertical drop from the
       parent, a straight horizontal bus line, then curved corners into each
       child node (classic org-chart routing) */
    function elbowPath(px, py, cx, cy) {
        var dx = cx - px;
        var my = py + (cy - py) / 2; /* horizontal bus sits midway between levels */
        if (Math.abs(dx) < 2) { return 'M' + px + ',' + py + ' L' + cx + ',' + cy; }
        var r = Math.max(4, Math.min(12, (cy - py) / 2, Math.abs(dx) / 2));
        var s = dx > 0 ? 1 : -1;
        return 'M' + px + ',' + py +
            ' V' + (my - r) +
            ' Q' + px + ',' + my + ' ' + (px + r * s) + ',' + my +
            ' H' + (cx - r * s) +
            ' Q' + cx + ',' + my + ' ' + cx + ',' + (my + r) +
            ' V' + cy;
    }

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
                var path = document.createElementNS(NS, 'path');
                path.setAttribute('d', elbowPath(px, py, cp.x + cn.offsetWidth / 2, cp.y));
                frag.appendChild(path);
            });
        });
        while (svg.firstChild) { svg.removeChild(svg.firstChild); }
        svg.appendChild(frag);
    }

    /* member pill info popup: one floating popup appended to <body> so it is
       never clipped by the tree scroll area and never disturbs the layout */
    var treePopup = null, popTimer = null, popNode = null;
    function getTreePopup() {
        if (!treePopup) {
            treePopup = document.createElement('div');
            treePopup.className = 'tree-popup';
            treePopup.setAttribute('role', 'tooltip');
            document.body.appendChild(treePopup);
            treePopup.addEventListener('mouseenter', cancelPopHide);
            treePopup.addEventListener('mouseleave', schedulePopHide);
            treePopup.addEventListener('click', function (e) { if (e.target.closest('a')) { hideTreePopup(); } });
        }
        return treePopup;
    }
    function hideTreePopup() {
        clearTimeout(popTimer);
        if (treePopup) { treePopup.classList.remove('show'); }
        popNode = null;
    }
    function schedulePopHide() { clearTimeout(popTimer); popTimer = setTimeout(hideTreePopup, 250); }
    function cancelPopHide() { clearTimeout(popTimer); }
    function showTreePopup(node) {
        var pill = node.querySelector('.t-pill');
        var tip = node.querySelector('.t-tip');
        if (!pill || !tip) { return; }
        var pop = getTreePopup();
        pop.innerHTML = tip.innerHTML;
        pop.classList.add('show');
        pop.style.visibility = 'hidden';
        var r = pill.getBoundingClientRect();
        var pw = pop.offsetWidth, ph = pop.offsetHeight;
        var vw = window.innerWidth, vh = window.innerHeight;
        var x = r.left + r.width / 2 - pw / 2;
        x = Math.max(8, Math.min(x, vw - pw - 8));
        var y = r.bottom + 10;
        if (y + ph > vh - 8) { y = r.top - ph - 10; } /* flip above the pill */
        if (y < 8) { y = Math.max(8, vh - ph - 8); }
        pop.style.left = Math.round(x) + 'px';
        pop.style.top = Math.round(y) + 'px';
        pop.style.visibility = '';
        popNode = node;
    }
    document.querySelectorAll('.tree .t-node:not(.empty)').forEach(function (node) {
        var pill = node.querySelector('.t-pill');
        node.addEventListener('mouseenter', function () { cancelPopHide(); showTreePopup(node); });
        node.addEventListener('mouseleave', schedulePopHide);
        node.addEventListener('focusin', function () { cancelPopHide(); showTreePopup(node); });
        node.addEventListener('focusout', schedulePopHide);
        if (pill) {
            pill.addEventListener('click', function (e) {
                e.preventDefault();
                /* on touch devices the pill has no hover: tap toggles the popup.
                   on desktop hover/focus already shows it, so click is a no-op. */
                if (!window.matchMedia('(hover: none)').matches) { return; }
                if (popNode === node && treePopup && treePopup.classList.contains('show')) { hideTreePopup(); }
                else { cancelPopHide(); showTreePopup(node); }
            });
        }
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.t-node') && !e.target.closest('.tree-popup')) { hideTreePopup(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hideTreePopup(); } });
    window.addEventListener('resize', hideTreePopup);
    window.addEventListener('scroll', hideTreePopup, true);

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
            if (typeof hideTreePopup === 'function') { hideTreePopup(); }
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
