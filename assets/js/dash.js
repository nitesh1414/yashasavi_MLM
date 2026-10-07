/* Yashasavi — dashboard JS (user/admin/superadmin) */
document.addEventListener('DOMContentLoaded', function () {

    /* sidebar toggle (mobile) — with dim overlay, closes on nav click / overlay tap */
    var mt = document.querySelector('.menu-toggle');
    var sb = document.querySelector('.sidebar');
    var so = document.querySelector('.side-overlay');
    function closeSidebar() {
        if (sb) { sb.classList.remove('open'); }
        if (so) { so.classList.remove('open'); }
        document.body.classList.remove('side-open');
    }
    function openSidebar() {
        if (sb) { sb.classList.add('open'); }
        if (so) { so.classList.add('open'); }
        document.body.classList.add('side-open');
    }
    if (mt && sb) {
        mt.addEventListener('click', function (e) {
            e.stopPropagation();
            if (sb.classList.contains('open')) { closeSidebar(); } else { openSidebar(); }
        });
        if (so) { so.addEventListener('click', closeSidebar); }
        var sc = document.querySelector('.side-close');
        if (sc) { sc.addEventListener('click', function (e) { e.stopPropagation(); closeSidebar(); }); }
        /* tapping a menu item closes the drawer, then the browser follows the link */
        sb.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', closeSidebar); });
        document.addEventListener('click', function (e) {
            if (sb.classList.contains('open') && !sb.contains(e.target) && e.target !== mt && !(so && so.contains(e.target))) {
                closeSidebar();
            }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSidebar(); } });
    }

    /* confirm dialogs — delegated so it also covers dynamically added elements */
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.dataset.confirm || 'Are you sure?')) {
            e.preventDefault();
            e.stopPropagation();
        }
        /* logout links without an explicit message */
        var a = e.target.closest('a[href]');
        if (a && !a.dataset.confirm && /logout/i.test(a.getAttribute('href')) &&
            !window.confirm('Logout from your account?')) {
            e.preventDefault();
        }
    });
    /* safety net: destructive POST forms (delete / reject / block / wallet adjust…)
       that don't carry their own data-confirm message */
    var CONFIRM_ACTIONS = { delete: 'Delete this item? This cannot be undone.', remove: 'Remove this item?',
        reject: 'Reject this? This cannot be undone.', cancel: 'Cancel this?',
        block: 'Block this account?', unblock: 'Unblock this account?',
        kyc_reject: 'Reject this KYC submission?', adjust: 'Adjust the wallet balance? Double-check the amount.',
        deactivate: 'Deactivate this?', toggle: 'Change the status?' };
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.dataset.confirm) { return; } /* already confirmed by the click handler */
        var btn = e.submitter;
        if (btn && btn.dataset.confirm) { return; }
        var act = f.querySelector('input[type=hidden][name=action]');
        var val = act ? act.value : (btn ? (btn.value || '') : '');
        var msg = CONFIRM_ACTIONS[val];
        if (msg && !window.confirm(msg)) { e.preventDefault(); }
    }, true);

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
                /* if the pill is a link (super admin tree), follow it to the
                   distributor view instead of toggling the popup */
                if (pill.closest('a[href]') || pill.tagName === 'A') { hideTreePopup(); return; }
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

    /* center the horizontal scroll on the VIEWED member (the tree root):
       wide trees would otherwise open at the far-left edge with the viewed
       member invisible somewhere in the middle */
    function centerTreeOnRoot() {
        var wrap = document.querySelector('.tree-wrap');
        var tree = document.querySelector('.tree[data-tree-root]');
        if (!wrap || !tree) { return; }
        var root = tree.querySelector(':scope > ul > li > .t-node');
        if (root) {
            var wr = wrap.getBoundingClientRect();
            var rr = root.getBoundingClientRect();
            wrap.scrollLeft += rr.left + rr.width / 2 - (wr.left + wr.width / 2);
        } else {
            wrap.scrollLeft = (wrap.scrollWidth - wrap.clientWidth) / 2;
        }
    }

    /* genealogy tree zoom: the chart always starts at FULL size (100%) with
       horizontal + vertical scroll bars — members never shrink to fit. The
       toolbar offers an optional overview (fit) and a one-click return to
       the readable 100% size. */
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
            else if (btn.dataset.treeZoom === 'full') { cur = 1; }
            else { cur = Math.min(1, (wrap.clientWidth - 16) / natural); }
            tree.dataset.zoom = cur;
            tree.style.zoom = cur;
            if (typeof hideTreePopup === 'function') { hideTreePopup(); }
            drawTreeLines();
            /* after a reset-style view (100% or Fit) put the viewed member
               back in the middle of the frame */
            if (btn.dataset.treeZoom === 'full' || btn.dataset.treeZoom === 'fit') {
                centerTreeOnRoot();
            }
        });
    });
    /* keep the connector lines correct on resize — the chosen zoom stays */
    var rzT;
    window.addEventListener('resize', function () { clearTimeout(rzT); rzT = setTimeout(drawTreeLines, 150); });
    drawTreeLines();
    centerTreeOnRoot();                                  /* viewed member starts centered */
    setTimeout(function () { drawTreeLines(); centerTreeOnRoot(); }, 350); /* once more after fonts settle */
});
