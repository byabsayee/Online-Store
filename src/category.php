<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$slug = $_GET['slug'] ?? '';
$stmt = db()->prepare('SELECT * FROM categories WHERE slug = ? AND is_active = 1');
$stmt->execute([$slug]);
$category = $stmt->fetch();
if (!$category) {
    http_response_code(404);
    $pageTitle = 'Category not found';
    require __DIR__ . '/includes/header.php';
    echo '<div class="wrap section"><div class="empty-state"><h2>Category not found</h2><p>That category may have been removed.</p><a class="btn btn-primary" href="/">Back to shop</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$sort = $_GET['sort'] ?? 'newest';
$sortSql = match ($sort) {
    'price_asc' => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'name' => 'p.name ASC',
    default => 'p.created_at DESC',
};

$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// A parent category also shows products filed directly under its subcategories
// (e.g. viewing "Bags & Carry" includes what's filed under "Men" and "Women").
$catIds = category_descendant_ids((int) $category['id']);
$catPlaceholders = implode(',', array_fill(0, count($catIds), '?'));

$countStmt = db()->prepare("SELECT COUNT(*) FROM products WHERE category_id IN ($catPlaceholders) AND is_active = 1");
$countStmt->execute($catIds);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = db()->prepare(
    "SELECT p.*, c.name AS category_name" . PRODUCT_LIST_EXTRA . " FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.category_id IN ($catPlaceholders) AND p.is_active = 1
     ORDER BY $sortSql LIMIT $perPage OFFSET $offset"
);
$stmt->execute($catIds);
$products = $stmt->fetchAll();

// Subcategories (shown as chips) and the parent category (shown in the breadcrumb), if any.
$subcatStmt = db()->prepare('SELECT id, name, slug FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order, name');
$subcatStmt->execute([$category['id']]);
$subcategories = $subcatStmt->fetchAll();

$parentCategory = null;
if ($category['parent_id']) {
    $parentStmt = db()->prepare('SELECT name, slug FROM categories WHERE id = ? AND is_active = 1');
    $parentStmt->execute([$category['parent_id']]);
    $parentCategory = $parentStmt->fetch() ?: null;
}

$__user = current_user();
$__favIds = $__user ? favorite_ids_for_user($__user['id']) : [];
$pageTitle = $category['name'];
require __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <div class="breadcrumb"><a href="/">Home</a> / <?php if ($parentCategory): ?><a href="<?= e(category_url($parentCategory)) ?>"><?= e($parentCategory['name']) ?></a> / <?php endif; ?><?= e($category['name']) ?></div>
</div>

<div class="page-header wrap">
  <span class="eyebrow"><?= $parentCategory ? 'Category — ' . e($parentCategory['name']) : 'Category' ?></span>
  <h1><?= e($category['name']) ?></h1>
  <?php if ($category['description']): ?><p class="prose"><?= e($category['description']) ?></p><?php endif; ?>
  <?php if ($subcategories): ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:14px;">
      <?php foreach ($subcategories as $sc): ?>
        <a href="<?= e(category_url($sc)) ?>" style="flex-shrink:0;padding:7px 14px;border-radius:999px;font-size:.82rem;font-weight:600;color:var(--ink-soft);border:1px solid var(--line);background:var(--paper-raised);white-space:nowrap;"><?= e($sc['name']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="wrap">
  <div class="filter-bar">
    <span class="count"><?= $total ?> product<?= $total === 1 ? '' : 's' ?></span>
    <form method="get">
      <select name="sort" onchange="this.form.submit()">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option>
        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option>
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name: A–Z</option>
      </select>
    </form>
  </div>

  <?php if ($products): ?>
    <div class="product-grid">
      <?php foreach ($products as $p): include __DIR__ . '/includes/product_card.php'; endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state">
      <div class="icon"><?= ui_icon('folder', 40) ?></div>
      <h2>No products here yet</h2>
      <p>Check back soon — we're stocking this category.</p>
    </div>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $page): ?>
          <span class="current"><?= $i ?></span>
        <?php else: ?>
          <a href="<?= e(category_url($category)) ?>?sort=<?= e($sort) ?>&amp;page=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
