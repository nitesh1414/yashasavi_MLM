<?php
/** CMS page renderer (by slug) */
require_once __DIR__ . '/includes/init.php';

$slug = get_str('slug');
$page = q_row("SELECT * FROM pages WHERE slug = ? AND status='published' LIMIT 1", [$slug]);
if (!$page) {
    http_response_code(404);
    $pageTitle = 'Page Not Found';
    require __DIR__ . '/includes/site_header.php';
    echo '<section class="content-page"><div class="container content-wrap text-center">
          <h1 style="font-size:60px;color:var(--green)">404</h1>
          <h2>Page not found</h2>
          <p class="mt-2">The page you are looking for does not exist or has been moved.</p>
          <p class="mt-3"><a class="btn btn-primary" href="' . url('index.php') . '">Go to Homepage</a></p>
          </div></section>';
    require __DIR__ . '/includes/site_footer.php';
    exit;
}

$pageTitle = $page['meta_title'] ?: $page['title'];
$pageDesc = $page['meta_description'];

require __DIR__ . '/includes/site_header.php';
?>
<section class="page-hero">
    <h1><?= e($page['title']) ?></h1>
    <div class="crumbs"><a href="<?= url('index.php') ?>">Home</a> / <?= e($page['title']) ?></div>
</section>

<section class="content-page">
    <div class="container content-wrap">
        <?= rich_text($page['content']) ?>

        <?php if ($page['slug'] === 'legals'):
            $docs = q_all("SELECT * FROM documents WHERE type='legal' AND status='active' ORDER BY sort_order, id"); ?>
            <?php if ($docs): ?>
            <div class="doc-grid">
                <?php foreach ($docs as $d): ?>
                <div class="doc-card">
                    <a href="<?= e(upload_url($d['image']) ?: '#') ?>" target="_blank">
                        <img src="<?= e(upload_url($d['image']) ?: placeholder('Document')) ?>" alt="<?= e($d['title']) ?>">
                    </a>
                    <div class="d-body">
                        <h4><?= e($d['title']) ?></h4>
                        <a class="btn btn-outline btn-sm" href="<?= e(upload_url($d['image']) ?: '#') ?>" target="_blank">View Document</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($page['slug'] === 'promotion'):
            $downloads = q_all("SELECT * FROM documents WHERE type='download' AND status='active' ORDER BY sort_order, id"); ?>
            <?php foreach ($downloads as $d): ?>
            <div class="download-row">
                <span class="d-ico">📄</span>
                <div>
                    <h4><?= e($d['title']) ?></h4>
                    <p><?= e($d['description']) ?></p>
                </div>
                <a class="btn btn-primary btn-sm" href="<?= e($d['external_url'] ?: upload_url($d['file']) ?: '#') ?>" <?= $d['external_url'] ? 'target="_blank"' : '' ?>>Download Now</a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/site_footer.php'; ?>
