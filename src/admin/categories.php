<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pageTitle = 'Categories';

$__rows = db()->query(
    "SELECT c.*, COUNT(p.id) AS product_count FROM categories c
     LEFT JOIN products p ON p.category_id = c.id
     GROUP BY c.id ORDER BY c.sort_order, c.name"
)->fetchAll();

// Flattened into parent → children order, each row tagged with its nesting depth, so
// subcategories (e.g. "Men" / "Women" under "Bags & Carry") list right under their parent.
$__byParent = [];
foreach ($__rows as $r) $__byParent[(int) ($r['parent_id'] ?? 0)][] = $r;
$categories = [];
$__flatten = function (int $parentId, int $depth) use (&$__flatten, &$__byParent, &$categories): void {
    foreach ($__byParent[$parentId] ?? [] as $r) {
        $r['depth'] = $depth;
        $categories[] = $r;
        $__flatten((int) $r['id'], $depth + 1);
    }
};
$__flatten(0, 0);

require __DIR__ . '/includes/header.php';
?>

<div class="panel">
  <div class="panel-head">
    <h2>All categories (<?= count($categories) ?>)</h2>
    <a href="/admin/category_form.php" class="btn btn-primary">+ Add category</a>
  </div>
  <div class="table-wrap"><table class="admin-table">
    <thead><tr><th></th><th>Name</th><th>Slug</th><th>Products</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (!$categories): ?>
        <tr class="empty-row"><td colspan="6">No categories yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($categories as $c): ?>
        <tr>
          <td><img class="thumb" src="<?= e(product_image_src($c['image'])) ?>" alt=""></td>
          <td><?php if ($c['depth'] > 0): ?><span style="color:var(--ink-faint);" aria-hidden="true"><?= str_repeat('&nbsp;&nbsp;&nbsp;', $c['depth']) ?>↳ </span><?php endif; ?><?= e($c['name']) ?></td>
          <td class="mono"><?= e($c['slug']) ?></td>
          <td><?= (int)$c['product_count'] ?></td>
          <td><?= $c['is_active'] ? '<span class="pill pill-sage">Active</span>' : '<span class="pill pill-ink">Hidden</span>' ?></td>
          <td style="white-space:nowrap;">
            <a href="/admin/category_form.php?id=<?= (int)$c['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
            <form method="post" action="/admin/category_delete.php" style="display:inline;" onsubmit="return confirm('Delete this category? Products in it will become uncategorized, and any subcategories under it will move up to top level.');">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
