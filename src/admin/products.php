<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();

$q = trim($_GET['q'] ?? '');
$cat = (int) ($_GET['cat'] ?? 0);
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'active', 'hidden', 'low', 'out', 'archived'], true)) $filter = 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

// Stock that actually matters: per-variant total when the product has variants.
$effStock = 'CASE WHEN (SELECT COUNT(*) FROM product_variants pv0 WHERE pv0.product_id = p.id AND pv0.is_active = 1) > 0
                  THEN (SELECT COALESCE(SUM(pv0.stock), 0) FROM product_variants pv0 WHERE pv0.product_id = p.id AND pv0.is_active = 1)
                  ELSE p.stock END';

$where = [$filter === 'archived' ? 'p.archived_at IS NOT NULL' : 'p.archived_at IS NULL']; $params = [];
if ($q !== '') {
    foreach (search_words($q) as $w) {
        $like = '%' . like_escape($w) . '%';
        $where[] = "(p.name LIKE ? ESCAPE '|' OR p.sku LIKE ? ESCAPE '|' OR p.tags LIKE ? ESCAPE '|' OR c.name LIKE ? ESCAPE '|')";
        array_push($params, $like, $like, $like, $like);
    }
}
if ($cat) { $where[] = 'p.category_id = ?'; $params[] = $cat; }
if ($filter === 'active') $where[] = 'p.is_active = 1';
if ($filter === 'hidden') $where[] = 'p.is_active = 0';
if ($filter === 'low') $where[] = "($effStock) BETWEEN 1 AND 5";
if ($filter === 'out') $where[] = "($effStock) <= 0";
$whereSql = implode(' AND ', $where);

// Counts for the filter tabs (respecting search + category, not the tab itself).
$base = $where; $baseParams = $params;
$counts = [];
foreach (['all' => 'p.archived_at IS NULL', 'active' => 'p.archived_at IS NULL AND p.is_active = 1', 'hidden' => 'p.archived_at IS NULL AND p.is_active = 0', 'low' => "p.archived_at IS NULL AND ($effStock) BETWEEN 1 AND 5", 'out' => "p.archived_at IS NULL AND ($effStock) <= 0", 'archived' => 'p.archived_at IS NOT NULL'] as $k => $cond) {
    $w2 = ['1=1']; $p2 = [];
    if ($q !== '') { foreach (search_words($q) as $w) { $like = '%' . like_escape($w) . '%'; $w2[] = "(p.name LIKE ? ESCAPE '|' OR p.sku LIKE ? ESCAPE '|' OR p.tags LIKE ? ESCAPE '|' OR c.name LIKE ? ESCAPE '|')"; array_push($p2, $like, $like, $like, $like); } }
    if ($cat) { $w2[] = 'p.category_id = ?'; $p2[] = $cat; }
    $w2[] = $cond;
    $st = db()->prepare('SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $w2));
    $st->execute($p2);
    $counts[$k] = (int) $st->fetchColumn();
}
$total = $counts[$filter];
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT p.*, c.name AS category_name, ($effStock) AS eff_stock,
            (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id) AS variant_count,
            (SELECT COUNT(*) FROM favorites fw WHERE fw.product_id = p.id) AS wish_count,
            (SELECT COUNT(*) FROM product_reviews pr WHERE pr.product_id = p.id AND pr.status = 'published') AS review_count,
            (SELECT AVG(pr.rating) FROM product_reviews pr WHERE pr.product_id = p.id AND pr.status = 'published') AS review_avg
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereSql ORDER BY p.created_at DESC, p.id DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$products = $stmt->fetchAll();
$categories = category_flat_for_select();

function products_url(array $over = []): string {
    $q = array_filter(array_merge($_GET, $over), fn ($v) => $v !== '' && $v !== 0 && $v !== '0' && $v !== 'all');
    if (isset($q['page']) && (int) $q['page'] <= 1) unset($q['page']);
    return '/admin/products.php' . ($q ? '?' . http_build_query($q) : '');
}
function hl(string $text, string $q): string {
    $out = e($text);
    foreach (search_words($q) as $w) $out = preg_replace('/(' . preg_quote(e($w), '/') . ')/iu', '<mark class="hit">$1</mark>', $out);
    return $out;
}

$pageTitle = 'Products';
require __DIR__ . '/includes/header.php';
?>
<section class="panel">
  <div class="panel-head">
    <h2>All products <span class="sub"><?= $counts['all'] ?> total</span></h2>
    <a href="/admin/product_form.php" class="btn btn-primary">+ Add product</a>
  </div>

  <form class="toolbar" method="get">
    <div class="search">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, SKU, tag or category…" aria-label="Search products">
    </div>
    <select name="cat" aria-label="Category" onchange="this.form.submit()">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $c['depth']) ?><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
    <button class="btn btn-outline" type="submit">Search</button>
    <?php if ($q !== '' || $cat): ?><a class="btn btn-outline" href="<?= e(products_url(['q' => '', 'cat' => 0, 'page' => 1])) ?>">Clear</a><?php endif; ?>
    <span class="spacer"></span>
    <div class="seg" role="tablist">
      <?php foreach (['all' => 'All', 'active' => 'Visible', 'hidden' => 'Hidden', 'low' => 'Low stock', 'out' => 'Out of stock', 'archived' => 'Archived'] as $k => $label): if ($k === 'archived' && !$counts['archived']) continue; ?>
        <a href="<?= e(products_url(['filter' => $k, 'page' => 1])) ?>" class="<?= $filter === $k ? 'active' : '' ?>"><?= e($label) ?><span class="n"><?= $counts[$k] ?></span></a>
      <?php endforeach; ?>
    </div>
  </form>

  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th style="width:64px;"></th><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th title="How many customers have it in their wishlist">Wishlists</th><th>Rating</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$products): ?>
        <tr class="empty-row"><td colspan="9"><?= $q !== '' || $cat || $filter !== 'all' ? 'No products match those filters.' : 'No products yet — add your first one.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p): $stockN = (int) $p['eff_stock']; ?>
        <tr>
          <td><img class="thumb" src="<?= e(product_image_src($p['image_main'])) ?>" alt="" loading="lazy"></td>
          <td class="name-cell">
            <a class="title" href="/admin/product_form.php?id=<?= (int) $p['id'] ?>"><?= hl($p['name'], $q) ?></a>
            <div class="meta"><?= $p['sku'] ? hl($p['sku'], $q) : 'no SKU' ?><?= $p['variant_count'] ? ' · ' . (int) $p['variant_count'] . ' variants' : '' ?><?= $p['is_featured'] ? ' · ' . ui_icon('star', 12) . ' featured' : '' ?><?= $p['is_preorder'] ? ' · pre-order' : '' ?></div>
          </td>
          <td><?= e($p['category_name'] ?? '—') ?></td>
          <td class="mono"><?= e(money((float) $p['price'])) ?><?php if ($p['compare_price']): ?><div class="muted small" style="text-decoration:line-through;"><?= e(money((float) $p['compare_price'])) ?></div><?php endif; ?></td>
          <td><?php if ($stockN <= 0 && $p['is_preorder']): ?><span class="pill pill-brass">Pre-order</span><?php elseif ($stockN <= 0 && !empty($p['allow_backorder'])): ?><span class="pill pill-rust" title="Out of stock, but shoppers can still order it">0 · Backorder</span><?php elseif ($stockN <= 0): ?><span class="pill pill-rust">Out</span><?php elseif ($stockN <= 5): ?><span class="stock-low mono"><?= $stockN ?> left</span><?php else: ?><span class="mono"><?= $stockN ?></span><?php endif; ?></td>
          <td class="mono"><?= (int) $p['wish_count'] > 0 ? ui_icon('heart', 13) . ' ' . (int) $p['wish_count'] : '<span class="muted">0</span>' ?></td>
          <td class="small" style="white-space:nowrap;"><?php if ((int) $p['review_count'] > 0): ?><?= stars_html((float) $p['review_avg']) ?> <span class="muted">(<?= (int) $p['review_count'] ?>)</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td><?= $p['is_active'] ? '<span class="pill pill-sage">Visible</span>' : '<span class="pill pill-ink">Hidden</span>' ?></td>
          <td class="actions">
            <span class="btn-group">
              <a href="/admin/product_form.php?id=<?= (int) $p['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
              <a href="/product.php?slug=<?= e($p['slug']) ?>" target="_blank" class="btn btn-outline btn-sm" title="View on store">↗</a>
              <?php if (!empty($p['archived_at'])): ?>
                <form method="post" action="/admin/product_restore.php" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-outline btn-sm" type="submit">Restore</button></form>
              <?php else: ?>
              <form method="post" action="/admin/product_delete.php" style="display:inline;" onsubmit="return confirm('Delete “<?= e(addslashes($p['name'])) ?>”? This cannot be undone.');">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Delete</button>
              </form>
              <?php endif; ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="pager">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $page): ?><span class="current"><?= $i ?></span><?php else: ?><a href="<?= e(products_url(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
