<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$category = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $category = $stmt->fetch();
    if (!$category) { flash_set('error', 'Category not found.'); redirect('/admin/categories.php'); }
}

// A category can't be parented to itself or to one of its own subcategories (that would loop forever).
$__blockedParents = $id ? category_descendant_ids($id) : [];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;

    if (strlen($name) < 2) $errors[] = 'Please enter a category name.';
    if ($parentId !== null) {
        if (in_array($parentId, $__blockedParents, true)) $errors[] = 'A category can\'t be its own parent, or a subcategory of itself.';
        else {
            $pc = db()->prepare('SELECT id FROM categories WHERE id = ?');
            $pc->execute([$parentId]);
            if (!$pc->fetch()) $errors[] = 'That parent category doesn\'t exist.';
        }
    }

    if (!$errors) {
        $slug = slugify($name);
        $baseSlug = $slug; $n = 1;
        while (true) {
            $check = db()->prepare('SELECT id FROM categories WHERE slug = ? AND id != ?');
            $check->execute([$slug, $id ?: 0]);
            if (!$check->fetch()) break;
            $slug = $baseSlug . '-' . (++$n);
        }

        try {
            $image = $category['image'] ?? null;
            $uploaded = handle_product_image_upload('image');
            if ($uploaded) $image = $uploaded;

            $parentName = fn ($pid) => $pid ? (db()->query('SELECT name FROM categories WHERE id = ' . (int) $pid)->fetchColumn() ?: '#' . (int) $pid) : 'None (top level)';
            if ($category) {
                db()->prepare('UPDATE categories SET parent_id=?, name=?, slug=?, description=?, image=?, sort_order=?, is_active=? WHERE id=?')
                    ->execute([$parentId, $name, $slug, $description ?: null, $image, $sortOrder, $isActive, $category['id']]);
                $diff = admin_log_diff(['parent' => $parentName($category['parent_id']), 'name' => $category['name'], 'description' => $category['description'], 'sort_order' => $category['sort_order'], 'is_active' => $category['is_active'] ? 'yes' : 'no', 'slug' => $category['slug']],
                    ['parent' => $parentName($parentId), 'name' => $name, 'description' => $description, 'sort_order' => $sortOrder, 'is_active' => $isActive ? 'yes' : 'no', 'slug' => $slug],
                    ['parent' => 'Parent category', 'name' => 'Name', 'description' => 'Description', 'sort_order' => 'Sort order', 'is_active' => 'Visible in shop', 'slug' => 'URL slug']);
                if ($uploaded) $diff['Image'] = ['(old image)', 'replaced'];
                admin_log('category.update', 'Edited category "' . $name . '"' . ($diff ? ': ' . admin_log_diff_summary($diff) : ' (saved, nothing changed)'), 'category', (int) $category['id'], $diff ? ['changes' => $diff] : []);
                erp_emit('category', (int) $category['id'], 'auto');
                flash_set('success', 'Category updated.');
            } else {
                db()->prepare('INSERT INTO categories (parent_id, name, slug, description, image, sort_order, is_active) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$parentId, $name, $slug, $description ?: null, $image, $sortOrder, $isActive]);
                $newCatId = (int) db()->lastInsertId();
                erp_emit('category', $newCatId, 'auto');
                admin_log('category.create', 'Created category "' . $name . '"' . ($parentId ? ' under "' . $parentName($parentId) . '"' : ''), 'category', $newCatId);
                flash_set('success', 'Category created.');
            }
            redirect('/admin/categories.php');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = $category ? 'Edit category' : 'Add category';
require __DIR__ . '/includes/header.php';
?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="panel">
    <div class="panel-body">
      <div class="field"><label for="name">Category name</label><input id="name" name="name" required value="<?= e($category['name'] ?? ($_POST['name'] ?? '')) ?>"></div>
      <div class="field"><label for="description">Description</label><textarea id="description" name="description" rows="3"><?= e($category['description'] ?? ($_POST['description'] ?? '')) ?></textarea></div>
      <div class="field-row">
        <div class="field">
          <label for="parent_id">Parent category <span class="muted" style="font-weight:400;">(optional)</span></label>
          <?php $__selectedParent = (int) ($_POST['parent_id'] ?? $category['parent_id'] ?? 0); ?>
          <select id="parent_id" name="parent_id">
            <option value="">— None (top level) —</option>
            <?php foreach (category_flat_for_select() as $pc): ?>
              <?php if (in_array((int) $pc['id'], $__blockedParents, true)) continue; ?>
              <option value="<?= (int) $pc['id'] ?>" <?= $__selectedParent === (int) $pc['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $pc['depth']) ?><?= e($pc['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">Makes this a subcategory, e.g. "Men" under "Bags &amp; Carry".</div>
        </div>
        <div class="field"><label for="sort_order">Sort order</label><input type="number" id="sort_order" name="sort_order" value="<?= e((string)($category['sort_order'] ?? 0)) ?>"><div class="hint">Lower numbers appear first in menus.</div></div>
      </div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:6px;font-weight:400;"><input type="checkbox" name="is_active" <?= ($category['is_active'] ?? 1) ? 'checked' : '' ?>> Active (visible in store)</label>
      </div>
      <div class="field">
        <label for="image">Category image</label>
        <?php if (!empty($category['image'])): ?><img src="<?= e($category['image']) ?>" style="width:100px;height:75px;object-fit:cover;border-radius:6px;border:1px solid var(--line);margin-bottom:10px;"><?php endif; ?>
        <input type="file" id="image" name="image" accept="image/*">
        <div class="hint">Used on the homepage category tiles. Recommend a 4:3 landscape photo.</div>
      </div>
    </div>
  </div>
  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><?= $category ? 'Save changes' : 'Create category' ?></button>
    <a href="/admin/categories.php" class="btn btn-outline">Cancel</a>
  </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
