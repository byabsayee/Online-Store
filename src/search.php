<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$q = trim($_GET['q'] ?? '');
$featuredOnly = !empty($_GET['featured']);
$sort = $_GET['sort'] ?? 'relevance';
$sortSql = match ($sort) {
    'price_asc' => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'newest' => 'p.created_at DESC',
    'name' => 'p.name ASC',
    default => $q !== '' ? 'relevance DESC' : 'p.created_at DESC',
};

$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = ['p.is_active = 1'];
$params = [];
$rankSql = '0';
$rankParams = [];

if ($q !== '') {
    // Partial-word search: "tita" finds "Titanium" (see product_search_sql()).
    $srch = product_search_sql($q);
    $where[] = '(' . $srch['where'] . ')';
    $params = $srch['where_params'];
    $rankSql = $srch['rank'];
    $rankParams = $srch['rank_params'];
}
if ($featuredOnly) {
    $where[] = 'p.is_featured = 1';
}
$whereSql = implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare(
    "SELECT p.*, c.name AS category_name, ($rankSql) AS relevance" . PRODUCT_LIST_EXTRA . "
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereSql ORDER BY $sortSql, p.name ASC LIMIT $perPage OFFSET $offset"
);
$stmt->execute(array_merge($rankParams, $params));
$products = $stmt->fetchAll();

$__user = current_user();
$__favIds = $__user ? favorite_ids_for_user($__user['id']) : [];
$pageTitle = $q !== '' ? 'Search: ' . $q : ($featuredOnly ? 'Featured products' : 'All products');
require __DIR__ . '/includes/header.php';

$qsBase = 'q=' . urlencode($q) . ($featuredOnly ? '&featured=1' : '') . '&sort=' . urlencode($sort);
?>

<div class="page-header wrap">
  <span class="eyebrow"><?= $q !== '' ? 'Search results' : 'Browse' ?></span>
  <h1><?= $q !== '' ? 'Results for “' . e($q) . '”' : ($featuredOnly ? 'Featured products' : 'All products') ?></h1>
</div>

<div class="wrap">
  <div class="filter-bar">
    <span class="count"><?= $total ?> product<?= $total === 1 ? '' : 's' ?> found</span>
    <form method="get">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <?php if ($featuredOnly): ?><input type="hidden" name="featured" value="1"><?php endif; ?>
      <select name="sort" onchange="this.form.submit()">
        <?php if ($q !== ''): ?><option value="relevance" <?= $sort === 'relevance' ? 'selected' : '' ?>>Best match</option><?php endif; ?>
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
      <div class="icon"><?= ui_icon('search', 40) ?></div>
      <h2>No matches found</h2>
      <p>Try a different search term or browse a category from the menu.</p>
    </div>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
        <?php else: ?><a href="?<?= $qsBase ?>&page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
