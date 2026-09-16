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
});
