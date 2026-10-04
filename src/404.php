<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
http_response_code(404);
$pageTitle = 'Page not found';
require __DIR__ . '/includes/header.php';
?>
<div class="wrap section">
  <div class="empty-state">
    <div class="icon">404</div>
    <h2>We couldn't find that page</h2>
    <p>It may have been moved or no longer exists.</p>
    <a class="btn btn-primary" href="/">Back to shop</a>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
