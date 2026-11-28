/* Referral sharing is entirely local: the member's URL is not sent to a QR service. */
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('referral-link');
    var copy = document.getElementById('referral-copy');
    var status = document.getElementById('referral-copy-status');
    var qrBox = document.getElementById('referral-qr');
    var qrLarge = document.getElementById('referral-qr-large');
    var qrDialog = document.getElementById('referral-qr-dialog');
    var qrOpen = document.getElementById('referral-qr-open');
    var qrClose = document.getElementById('referral-qr-close');
    if (!input) { return; }

    if (qrBox && typeof qrcode === 'function') {
        try {
            var qr = qrcode(0, 'M');
            // URL serialisation percent-encodes non-ASCII path characters.
            qr.addData(new URL(input.value, window.location.href).href);
            qr.make();
            var svg = qr.createSvgTag({ cellSize: 3, margin: 12, scalable: true });
            qrBox.innerHTML = svg;
            if (qrLarge) { qrLarge.innerHTML = svg; }
        } catch (error) {
            qrBox.textContent = 'QR unavailable';
        }
    }
    if (qrOpen && qrDialog) {
        function closeQrDialog() {
            if (typeof qrDialog.close === 'function') { qrDialog.close(); }
            else { qrDialog.removeAttribute('open'); qrOpen.focus(); }
        }
        qrOpen.addEventListener('click', function () {
            if (qrDialog.hasAttribute('open')) { return; }
            if (typeof qrDialog.showModal === 'function') {
                qrDialog.showModal();
                qrDialog.setAttribute('aria-modal', 'true');
            } else {
                qrDialog.removeAttribute('aria-modal');
                qrDialog.setAttribute('open', '');
            }
            if (qrClose) { qrClose.focus(); }
        });
        if (qrClose) { qrClose.addEventListener('click', closeQrDialog); }
        qrDialog.addEventListener('close', function () { qrOpen.focus(); });
        qrDialog.addEventListener('click', function (event) {
            if (event.target === qrDialog) { closeQrDialog(); }
        });
        // Native modal dialogs handle Escape and focus trapping. Supply those
        // basics as well when an older browser only supports the open attribute.
        qrDialog.addEventListener('keydown', function (event) {
            if (typeof qrDialog.showModal === 'function') { return; }
            if (event.key === 'Escape') { event.preventDefault(); closeQrDialog(); }
            else if (event.key === 'Tab' && qrClose) { event.preventDefault(); qrClose.focus(); }
        });
    }

    function feedback(message) { if (status) { status.textContent = message; } }
    function fallbackCopy() {
        input.focus();
        input.select();
        input.setSelectionRange(0, input.value.length);
        try {
            if (document.execCommand('copy')) { feedback('Referral link copied.'); return; }
        } catch (error) { /* Browser does not allow legacy clipboard access. */ }
        feedback('Select and copy the link above.');
    }
    if (copy) {
        copy.addEventListener('click', function () {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(input.value).then(function () {
                    feedback('Referral link copied.');
                }).catch(fallbackCopy);
            } else {
                fallbackCopy();
            }
        });
    }
});
