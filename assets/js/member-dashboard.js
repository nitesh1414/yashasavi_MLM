(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.md-ref-input[data-relative-url]').forEach(function (input) {
            // Browser origin, not a backend localhost URL: works behind proxies and subdirectories.
            try { input.value = new URL(input.getAttribute('data-relative-url'), window.location.href).href; } catch (e) { /* retain the server URL */ }
        });
        var status = document.querySelector('.md-copy-status');
        function announce(message) { if (status) { status.textContent = message; } }
        function fallback(input) {
            input.focus(); input.select();
            try { return document.execCommand('copy'); } catch (e) { return false; }
        }
        document.querySelectorAll('.md-copy[data-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-copy'));
                if (!input) { return; }
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(input.value).then(function () { announce('Referral link copied.'); }, function () {
                        announce(fallback(input) ? 'Referral link copied.' : 'Select the link and copy it manually.');
                    });
                } else { announce(fallback(input) ? 'Referral link copied.' : 'Select the link and copy it manually.'); }
            });
        });
    });
})();
