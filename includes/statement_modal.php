<?php
/**
 * Statement popup — shared by the member payout report pages
 * (user/payout.php and user/payout-rewards.php).
 *
 * Include AFTER the page content, BEFORE dash_footer.php. Renders the
 * modal shell + scoped styles and sets $pageScripts, wiring every
 * [data-statement] button:
 *   data-statement="FROM|TO"  data-kind="incentive|reward"  data-label="…"
 * Popup behaviour matches the order-invoice popup: the document keeps its
 * fixed 720px paper layout (scaled to fit narrow screens, never reflowed),
 * Print renders only the statement (hidden iframe) and the PDF button
 * downloads the generated PDF.
 */
$stUrl = url('user/payout-statement.php');
$pageScripts = <<<JS
(function () {
    var modal = document.getElementById('stModal');
    if (!modal) { return; }
    var bodyEl = document.getElementById('stModalBody');
    var subEl = document.getElementById('stModalSub');
    var base = '{$stUrl}';
    var cur = null; /* { kind, from, to } */

    function open(kind, from, to, label) {
        cur = { kind: kind, from: from, to: to };
        subEl.textContent = label || '';
        bodyEl.innerHTML = '<div class="stmodal-load">Loading statement…</div>';
        modal.hidden = false;
        document.documentElement.style.overflow = 'hidden';
        fetch(base + '?kind=' + encodeURIComponent(kind) + '&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to) + '&ajax=1', { credentials: 'same-origin' })
            .then(function (r) {
                if (r.redirected || !r.ok) { throw new Error('failed'); }
                return r.text();
            })
            .then(function (html) {
                if (!cur) { return; }
                bodyEl.innerHTML = html;
                wrapDoc();
                fitDoc();
            })
            .catch(function () {
                if (!cur) { return; }
                bodyEl.innerHTML = '<div class="stmodal-err">Could not load the statement. Please try again.</div>';
            });
    }

    function close() {
        cur = null;
        modal.hidden = true;
        bodyEl.innerHTML = '';
        document.documentElement.style.overflow = '';
    }

    /* the statement keeps its fixed paper layout; on narrow screens the
       whole sheet is scaled down to fit — never reflowed */
    function wrapDoc() {
        var doc = bodyEl.querySelector('.pst-doc');
        if (!doc || (doc.parentNode && doc.parentNode.classList && doc.parentNode.classList.contains('stmodal-fit'))) { return; }
        var fit = document.createElement('div');
        fit.className = 'stmodal-fit';
        doc.parentNode.replaceChild(fit, doc);
        fit.appendChild(doc);
    }

    function fitDoc() {
        var fit = bodyEl.querySelector('.stmodal-fit');
        if (!fit) { return; }
        var doc = fit.querySelector('.pst-doc');
        if (!doc) { return; }
        var inner = fit.clientWidth - 16;
        if (!inner || inner >= 720) {
            doc.style.width = ''; doc.style.margin = ''; doc.style.transform = ''; doc.style.transformOrigin = ''; fit.style.height = '';
            return;
        }
        var k = inner / 720;
        doc.style.width = '720px';
        doc.style.margin = '0';
        doc.style.transformOrigin = 'top left';
        doc.style.transform = 'scale(' + k + ')';
        if (doc.offsetHeight) { fit.style.height = Math.ceil(doc.offsetHeight * k) + 'px'; }
    }

    /* print exactly the statement: hidden iframe, no panel chrome */
    function printDoc() {
        var doc = bodyEl.querySelector('.pst-doc');
        if (!doc) { return; }
        var f = document.createElement('iframe');
        f.setAttribute('aria-hidden', 'true');
        f.style.cssText = 'position:fixed;left:-9999px;top:0;width:760px;height:640px;border:0';
        var printed = false;
        function go() {
            if (printed) { return; }
            printed = true;
            try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {}
            setTimeout(function () { if (f.parentNode) { f.parentNode.removeChild(f); } }, 60000);
        }
        f.onload = function () { setTimeout(go, 350); };
        document.body.appendChild(f);
        var d = f.contentDocument || f.contentWindow.document;
        d.open();
        d.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Payout Statement</title>'
            + '<style>@page{size:A4;margin:12mm}@media print{body{margin:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}}</style>'
            + '</head><body style="margin:0">' + doc.outerHTML + '</body></html>');
        d.close();
        setTimeout(go, 3000);
    }

    function downloadPdf() {
        if (!cur) { return; }
        window.location.href = base + '?kind=' + encodeURIComponent(cur.kind)
            + '&from=' + encodeURIComponent(cur.from) + '&to=' + encodeURIComponent(cur.to) + '&pdf=1';
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target && ev.target.closest ? ev.target.closest('[data-statement]') : null;
        if (t) {
            ev.preventDefault();
            var per = (t.getAttribute('data-statement') || '').split('|');
            open(t.getAttribute('data-kind') || 'incentive', per[0] || '', per[1] || '', t.getAttribute('data-label') || '');
            return;
        }
        if (!modal || modal.hidden) { return; }
        if (ev.target.closest('[data-stprint]')) { ev.preventDefault(); printDoc(); return; }
        if (ev.target.closest('[data-stpdf]')) { ev.preventDefault(); downloadPdf(); return; }
        if (ev.target.closest('[data-stclose]')) { ev.preventDefault(); close(); }
    });

    window.addEventListener('resize', function () {
        if (modal && !modal.hidden) { fitDoc(); }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && modal && !modal.hidden) { close(); }
    });
})();
JS;
?>
<div class="stmodal" id="stModal" hidden>
    <div class="stmodal-backdrop" data-stclose></div>
    <div class="stmodal-box" role="dialog" aria-modal="true" aria-label="Payout statement">
        <div class="stmodal-head">
            <div class="stmodal-ht">
                <div class="stmodal-title">🧾 Payout Statement</div>
                <div class="stmodal-sub" id="stModalSub"></div>
            </div>
            <div class="stmodal-actions">
                <button type="button" class="btn btn-outline btn-sm" data-stprint>🖨️ Print</button>
                <button type="button" class="btn btn-primary btn-sm" data-stpdf>⬇️ Generate PDF</button>
                <button type="button" class="stmodal-x" data-stclose aria-label="Close statement">&times;</button>
            </div>
        </div>
        <div class="stmodal-body" id="stModalBody"></div>
    </div>
</div>

<style>
/* Statement popup — scoped styles (payout report pages only) */
.stmodal{position:fixed;inset:0;z-index:9995;display:flex;align-items:center;justify-content:center;padding:18px}
.stmodal[hidden]{display:none}
.stmodal-backdrop{position:absolute;inset:0;background:rgba(8,20,12,.72);backdrop-filter:blur(2px)}
.stmodal-box{position:relative;display:flex;flex-direction:column;width:min(94vw,800px);max-height:92vh;background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(0,0,0,.4);overflow:hidden;animation:stmodalIn .28s ease}
.stmodal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;background:var(--card,#fff);border-bottom:1px solid var(--line,#e2e8de);flex:0 0 auto}
.stmodal-title{font-family:var(--font-head,inherit);font-weight:600;font-size:15px;color:#000}
.stmodal-sub{font-size:11.5px;color:var(--ink-soft,#000);margin-top:1px}
.stmodal-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.stmodal-x{width:34px;height:34px;border-radius:50%;border:1px solid var(--line,#e2e8de);background:#fff;color:#000;font-size:20px;line-height:1;cursor:pointer}
.stmodal-x:hover{background:#fde5e5;color:#9c1f1f;border-color:#f3bcbc}
.stmodal-body{overflow:auto;padding:0;background:#eef1ee;-webkit-overflow-scrolling:touch}
.stmodal-body .pst-doc{margin:14px auto;box-shadow:0 2px 14px rgba(0,0,0,.12)}
.stmodal-fit{padding:10px 8px;overflow:hidden}
.stmodal-fit .pst-doc{margin:0 auto}
.stmodal-load,.stmodal-err{padding:60px 20px;text-align:center;color:var(--ink-soft,#000);font-size:13px}
.stmodal-err{color:#9c1f1f}
@keyframes stmodalIn{from{opacity:0;transform:scale(.94) translateY(12px)}to{opacity:1;transform:none}}
@media (max-width:640px){
    .stmodal{padding:0}
    .stmodal-box{width:100%;max-height:100%;height:100%;border-radius:0}
    .stmodal-head{flex-wrap:wrap;padding:10px 12px}
    .stmodal-body .pst-doc{margin:10px auto}
    .stmodal-body{overflow-y:auto;overflow-x:hidden}
}
</style>
