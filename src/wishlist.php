<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$user = current_user();
$stmt = db()->prepare(
    "SELECT p.*, c.name AS category_name" . PRODUCT_LIST_EXTRA . " FROM favorites f
     JOIN products p ON p.id = f.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE f.user_id = ? AND p.is_active = 1 ORDER BY f.created_at DESC"
);
$stmt->execute([$user['id']]);
$products = $stmt->fetchAll();
$__favIds = favorite_ids_for_user($user['id']);

$__activeAccountTab = 'wishlist';
$pageTitle = 'Wishlist';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header wrap"><span class="eyebrow">Account</span><h1>Your wishlist</h1></div>

<div class="wrap account-layout">
  <?php include __DIR__ . '/includes/account_nav.php'; ?>

  <div>
    <?php if (!$products): ?>
      <div class="empty-state">
        <div class="icon"><?= ui_icon('heart', 40) ?></div>
        <h2>Nothing saved yet</h2>
        <p>Tap the heart icon on any product to save it here.</p>
        <a class="btn btn-primary" href="/">Browse products</a>
      </div>
    <?php else: ?>
      <div class="product-grid cols-3">
        <?php foreach ($products as $p): include __DIR__ . '/includes/product_card.php'; endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
