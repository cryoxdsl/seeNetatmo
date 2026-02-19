<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/settings.php';
require_once __DIR__ . '/inc/view.php';

if (!app_is_installed()) {
    redirect('/install/index.php');
}

$termsContent = terms_of_use_content();
$termsContent = str_replace(["\r\n", "\r"], "\n", $termsContent);
$termsContent = trim($termsContent);

front_header(t('terms.title'));
?>
<section class="panel">
  <h2><?= h(t('terms.title')) ?></h2>
  <?php if ($termsContent === ''): ?>
    <p class="small-muted"><?= h(t('terms.empty')) ?></p>
  <?php else: ?>
    <div class="legal-content"><?= nl2br(h($termsContent), false) ?></div>
  <?php endif; ?>
</section>
<?php front_footer();
