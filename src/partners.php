<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!partners_enabled()) { http_response_code(404); require __DIR__ . '/404.php'; exit; }
$pageTitle = 'Our partners';
$partners = partners_list();
require __DIR__ . '/includes/header.php';
?>
<div class="page-header wrap"><span class="eyebrow">Partners</span><h1>Our partners</h1></div>
<div class="wrap section" style="padding-top:8px;">
  <?php if (!$partners): ?>
    <p class="muted">Nothing here yet.</p>
  <?php else: ?>
  <div class="partner-grid">
    <?php foreach ($partners as $p): ?>
      <article class="partner-card">
        <div class="partner-head">
          <?php if ($p['logo_url']): ?><img src="<?= e($p['logo_url']) ?>" alt="" loading="lazy"><?php else: ?><span class="partner-initial"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span><?php endif; ?>
          <h2><?= e($p['name']) ?></h2>
        </div>
        <?php if (trim((string) $p['description']) !== ''): ?><p><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
        <?php if ($p['links']): ?><div class="partner-links"><?php foreach ($p['links'] as $l): ?><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener nofollow" class="btn btn-outline btn-sm"><?= e($l['title']) ?> ↗</a><?php endforeach; ?></div><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
