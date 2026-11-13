<?php
/**
 * Ad banner popup — shared by the public website footer and the member
 * dashboard footer. Controlled from Admin Panel → Ad Banner Popup:
 *   - ad_banner_image     uploaded banner (uploads/banners/…)
 *   - ad_banner_active    '1' = popup shows, '0' = hidden
 *   - ad_banner_frequency 'first' = only the first page view of a visit,
 *                          'always' = every time a page is refreshed
 * Clicking the banner (or the ✕ / backdrop) closes the popup and shows
 * the normal page behind it.
 */
$adBannerImg = setting('ad_banner_active') === '1' ? setting('ad_banner_image') : '';
if ($adBannerImg !== '') {
    $adBannerOnce = setting('ad_banner_frequency') !== 'always'; /* default: first view only */
    ?>
<div class="adpop" id="adPopup" hidden>
    <div class="adpop-backdrop" data-adclose></div>
    <div class="adpop-box">
        <button type="button" class="adpop-close" data-adclose aria-label="Close advertisement">&times;</button>
        <img src="<?= e(upload_url($adBannerImg)) ?>" alt="Advertisement" class="adpop-img" data-adclose>
        <div class="adpop-hint">Tap the banner to continue</div>
    </div>
</div>
<style>
.adpop{position:fixed;inset:0;z-index:9990;display:flex;align-items:center;justify-content:center}
.adpop[hidden]{display:none}
.adpop-backdrop{position:absolute;inset:0;background:rgba(8,14,26,.78);backdrop-filter:blur(2px)}
.adpop-box{position:relative;max-width:min(92vw,560px);max-height:88vh;display:flex;flex-direction:column;align-items:center;animation:adpopIn .32s ease}
.adpop-img{max-width:100%;max-height:80vh;object-fit:contain;border-radius:14px;box-shadow:0 18px 60px rgba(0,0,0,.45);cursor:pointer;background:#fff}
.adpop-close{position:absolute;top:-16px;right:-16px;width:38px;height:38px;border-radius:50%;border:none;background:#fff;color:#1d2733;font-size:24px;line-height:1;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center}
.adpop-close:hover{background:var(--brand,#2a7a2a);color:#fff}
.adpop-hint{margin-top:12px;color:rgba(255,255,255,.85);font-size:13px;letter-spacing:.3px;text-shadow:0 1px 4px rgba(0,0,0,.5)}
@keyframes adpopIn{from{opacity:0;transform:scale(.86) translateY(14px)}to{opacity:1;transform:none}}
@media (max-width:600px){.adpop-close{top:-12px;right:-8px;width:32px;height:32px;font-size:20px}}
</style>
<script>
(function () {
    var pop = document.getElementById('adPopup');
    if (!pop) { return; }
    var onceOnly = <?= $adBannerOnce ? 'true' : 'false' ?>;
    var seen = false;
    if (onceOnly) {
        try { seen = sessionStorage.getItem('adBannerClosed') === '1'; } catch (e) { seen = false; }
    }
    if (!seen) {
        document.documentElement.style.overflow = 'hidden';
        pop.hidden = false;
    }
    pop.addEventListener('click', function (ev) {
        if (!ev.target.closest('[data-adclose]')) { return; }
        pop.hidden = true;
        document.documentElement.style.overflow = '';
        try { sessionStorage.setItem('adBannerClosed', '1'); } catch (e) {}
    });
})();
</script>
<?php } ?>
