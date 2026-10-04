<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner(); // must run before any POST handling below
$errors = [];
$editId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'save') {
        $name = trim($_POST['name'] ?? '');
        $instr = trim($_POST['instructions'] ?? '');
        $active = !empty($_POST['is_active']) ? 1 : 0;
        $sort = (int) ($_POST['sort_order'] ?? 0);
        $row = null;
        if ($id) { $st = db()->prepare('SELECT * FROM payment_methods WHERE id = ?'); $st->execute([$id]); $row = $st->fetch(); }
        if ($name === '' || mb_strlen($name) > 80) $errors[] = 'Give the method a name (up to 80 characters), e.g. bKash.';
        if (mb_strlen($instr) > 1000) $errors[] = 'Instructions are limited to 1000 characters.';
        if ($row && $row['kind'] === 'cod' && !$active) $errors[] = 'Cash on delivery can\'t be switched off.';
        if (!$errors) {
            try {
                db()->beginTransaction();
                if ($row) {
                    db()->prepare('UPDATE payment_methods SET name = ?, instructions = ?, is_active = ?, sort_order = ? WHERE id = ?')->execute([$name, $instr ?: null, $active, $sort, $id]);
                    erp_emit('payment_method', $id, 'auto');
                    admin_log('payment_method.update', 'Edited payment method "' . $name . '"', 'payment_method', $id);
                } else {
                    $base = preg_replace('/[^a-z0-9]+/', '_', strtolower($name)); $base = trim($base, '_') ?: 'method'; $code = $base; $n = 1;
                    while ((int) db()->query("SELECT COUNT(*) FROM payment_methods WHERE code = " . db()->quote($code))->fetchColumn()) $code = $base . '_' . (++$n);
                    db()->prepare("INSERT INTO payment_methods (code, name, kind, instructions, is_active, sort_order) VALUES (?,?,'manual',?,?,?)")->execute([$code, $name, $instr ?: null, $active, $sort]);
                    $id = (int) db()->lastInsertId();
                    erp_emit('payment_method', $id, 'auto');
                    admin_log('payment_method.create', 'Added payment method "' . $name . '"', 'payment_method', $id);
                }
                db()->commit();
                flash_set('success', 'Payment method saved.');
                redirect('/admin/payment_methods.php');
            } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $errors[] = 'Could not save that. Please try again.'; error_log('[payment_methods] ' . $e->getMessage()); }
        }
        $editId = $id;
    } elseif ($act === 'delete') {
        $st = db()->prepare('SELECT * FROM payment_methods WHERE id = ?'); $st->execute([$id]);
        if ($m = $st->fetch()) {
            $used = (int) db()->query('SELECT (SELECT COUNT(*) FROM orders WHERE payment_method_id = ' . $id . ') + (SELECT COUNT(*) FROM order_payments WHERE method_id = ' . $id . ')')->fetchColumn();
            $lk = erp_linked() ? erp_link_by_local('payment_method', $id) : null;
            if ($m['kind'] === 'cod') flash_set('error', 'Cash on delivery can\'t be removed.');
            elseif ($used || ($lk && !empty($lk['last_payload']))) {
                db()->prepare('UPDATE payment_methods SET is_active = 0 WHERE id = ?')->execute([$id]);
                erp_emit('payment_method', $id, 'archive');
                admin_log('payment_method.archive', 'Switched off payment method "' . $m['name'] . '" (it is used on past orders / linked to the accounting book)', 'payment_method', $id);
                flash_set('success', '"' . $m['name'] . '" is used on past records, so it was switched off instead of deleted.');
            } else {
                db()->prepare('DELETE FROM payment_methods WHERE id = ?')->execute([$id]);
                admin_log('payment_method.delete', 'Deleted payment method "' . $m['name'] . '"', 'payment_method', $id);
                flash_set('success', 'Payment method deleted.');
            }
        }
        redirect('/admin/payment_methods.php');
    }
}

$methods = db()->query('SELECT * FROM payment_methods ORDER BY (kind = \'cod\') DESC, sort_order, id')->fetchAll();
$edit = null;
foreach ($methods as $m) if ((int) $m['id'] === $editId) $edit = $m;
$pageTitle = 'Payment methods';
require __DIR__ . '/includes/header.php';
?>
<section class="panel">
  <div class="panel-head"><h2>Payment methods <span class="sub">shown at checkout</span></h2></div>
  <table class="admin-table">
    <thead><tr><th>Name</th><th>Type</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($methods as $m): ?>
        <tr>
          <td><strong><?= e($m['name']) ?></strong><?php if ($m['instructions']): ?><div class="muted" style="font-size:.85rem;white-space:pre-line;"><?= e($m['instructions']) ?></div><?php endif; ?></td>
          <td><?= $m['kind'] === 'cod' ? 'Cash on delivery' : 'Manual (paid outside the site)' ?></td>
          <td><?= $m['is_active'] ? '<span class="pill pill-sage">On</span>' : '<span class="pill pill-ink">Off</span>' ?></td>
          <td style="text-align:right;">
            <a class="btn btn-outline btn-sm" href="/admin/payment_methods.php?edit=<?= (int) $m['id'] ?>">Edit</a>
            <?php if ($m['kind'] !== 'cod'): ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Remove this payment method?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn-danger btn-sm">Remove</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="panel">
  <div class="panel-head"><h2><?= $edit ? 'Edit “' . e($edit['name']) . '”' : 'Add a payment method' ?></h2></div>
  <div class="panel-body">
    <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="field-row">
        <div class="field"><label for="pm_name">Name</label><input id="pm_name" name="name" maxlength="80" value="<?= e($_POST['name'] ?? ($edit['name'] ?? '')) ?>" placeholder="e.g. bKash, Nagad, Bank transfer" required></div>
        <div class="field"><label for="pm_sort">Order in the list</label><input type="number" id="pm_sort" name="sort_order" value="<?= e((string) ($_POST['sort_order'] ?? ($edit['sort_order'] ?? count($methods)))) ?>"></div>
      </div>
      <div class="field"><label for="pm_instr">What the customer should do <span class="muted" style="font-weight:400;">(shown at checkout)</span></label>
        <textarea id="pm_instr" name="instructions" rows="3" maxlength="1000" placeholder="e.g. Send the total to 01XXXXXXXXX (personal) and keep the transaction ID."><?= e($_POST['instructions'] ?? ($edit['instructions'] ?? '')) ?></textarea></div>
      <div class="checkbox-row" style="margin-bottom:14px;"><input type="checkbox" id="pm_on" name="is_active" value="1" <?= !$edit || !empty($edit['is_active']) || !empty($_POST['is_active']) ? 'checked' : '' ?>><label for="pm_on" style="margin:0;font-weight:400;">Offer this method at checkout</label></div>
      <p class="help">The order stays unpaid until you record the payment on the order page (Payments). No money moves through the website itself.</p>
      <button class="btn btn-primary"><?= $edit ? 'Save changes' : 'Add method' ?></button>
      <?php if ($edit): ?><a class="btn btn-outline" href="/admin/payment_methods.php">Cancel</a><?php endif; ?>
    </form>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
