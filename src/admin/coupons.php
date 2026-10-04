<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pageTitle = 'Coupons';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $c = coupon_find($id);
    if ($c && ($_POST['action'] ?? '') === 'toggle') {
        $on = (int) $c['is_active'] ? 0 : 1;
        db()->prepare('UPDATE coupons SET is_active = ? WHERE id = ?')->execute([$on, $id]);
        admin_log($on ? 'coupon.enable' : 'coupon.disable', 'Coupon ' . $c['code'] . ($on ? ' switched on' : ' switched off'), 'coupon', $id);
        flash_set('success', 'Coupon ' . $c['code'] . ($on ? ' is now on.' : ' is now off.'));
    }
    redirect('/admin/coupons.php' . (!empty($_GET['state']) ? '?state=' . urlencode((string) $_GET['state']) : ''));
}

$q = trim((string) ($_GET['q'] ?? ''));
$stateFilter = (string) ($_GET['state'] ?? '');

$sql = "SELECT c.*,
          (SELECT COUNT(*) FROM orders o WHERE o.coupon_id = c.id AND o.status <> 'cancelled') AS _used,
          (SELECT COALESCE(SUM(o.discount), 0) FROM orders o WHERE o.coupon_id = c.id AND o.status <> 'cancelled') AS given
        FROM coupons c";
$params = [];
if ($q !== '') { $sql .= " WHERE c.code LIKE ? ESCAPE '|' OR c.note LIKE ? ESCAPE '|'"; $like = '%' . like_escape($q) . '%'; $params = [$like, $like]; }
$sql .= ' ORDER BY c.created_at DESC, c.id DESC LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$all = $stmt->fetchAll();

$states = ['active' => 'Active', 'scheduled' => 'Scheduled', 'expired' => 'Expired', 'used_up' => 'Used up', 'disabled' => 'Off'];
$counts = array_fill_keys(array_keys($states), 0);
foreach ($all as &$row) { $row['_state'] = coupon_state($row); $counts[$row['_state'][0]]++; }
unset($row);
$rows = $stateFilter !== '' && isset($states[$stateFilter]) ? array_values(array_filter($all, fn ($r) => $r['_state'][0] === $stateFilter)) : $all;

function coupons_url(array $over = []): string {
    $q = array_filter(array_merge($_GET, $over), fn ($v) => $v !== '');
    return '/admin/coupons.php' . ($q ? '?' . http_build_query($q) : '');
}
$tone = ['active' => 'sage', 'scheduled' => 'brass', 'expired' => 'ink', 'used_up' => 'ink', 'disabled' => 'rust'];

require __DIR__ . '/includes/header.php';
?>

<div class="panel">
  <div class="panel-head">
    <h2>Coupons <span class="sub"><?= count($all) ?> <?= $q !== '' ? 'matching' : 'total' ?></span></h2>
    <a href="/admin/coupon_form.php" class="btn btn-primary btn-sm">+ New coupon</a>
  </div>

  <form class="toolbar" method="get">
    <div class="search">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by code or note…" aria-label="Search coupons">
    </div>
    <?php if ($stateFilter !== ''): ?><input type="hidden" name="state" value="<?= e($stateFilter) ?>"><?php endif; ?>
    <button class="btn btn-primary" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-outline" href="<?= e(coupons_url(['q' => ''])) ?>">Clear</a><?php endif; ?>
    <span class="spacer"></span>
    <div class="seg">
      <a href="<?= e(coupons_url(['state' => ''])) ?>" class="<?= $stateFilter === '' ? 'active' : '' ?>">All<span class="n"><?= count($all) ?></span></a>
      <?php foreach ($states as $k => $label): ?>
        <a href="<?= e(coupons_url(['state' => $k])) ?>" class="<?= $stateFilter === $k ? 'active' : '' ?>"><?= e($label) ?><span class="n"><?= (int) $counts[$k] ?></span></a>
      <?php endforeach; ?>
    </div>
  </form>

  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th>Code</th><th>Discount</th><th>Minimum order</th><th>Valid</th><th>Used</th><th>Given away</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr class="empty-row"><td colspan="8"><?= count($all) === 0 && $q === '' ? 'No coupons yet — create your first one.' : 'No coupons match.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $c): [$sk, $sl] = $c['_state']; ?>
          <tr>
            <td><a href="/admin/coupon_form.php?id=<?= (int) $c['id'] ?>" class="mono" style="font-weight:700;letter-spacing:.04em;"><?= e($c['code']) ?></a><?php if ($c['note']): ?><br><span class="muted small"><?= e($c['note']) ?></span><?php endif; ?></td>
            <td><?= e(coupon_describe($c)) ?><br><span class="muted small"><?= $c['type'] === 'percent' ? 'Percentage' : 'Fixed amount' ?></span></td>
            <td class="mono"><?= (float) $c['min_subtotal'] > 0 ? e(money((float) $c['min_subtotal'])) : '—' ?></td>
            <td class="small">
              <?php if ($c['starts_at'] || $c['expires_at']): ?>
                <?= $c['starts_at'] ? e(fmt_dt($c['starts_at'], 'd M Y, g:i A')) : 'Now' ?><br><span class="muted">→ <?= $c['expires_at'] ? e(fmt_dt($c['expires_at'], 'd M Y, g:i A')) : 'no end date' ?></span>
              <?php else: ?><span class="muted">Always</span><?php endif; ?>
            </td>
            <td class="mono"><?= (int) $c['_used'] ?><?= $c['usage_limit'] !== null ? ' / ' . (int) $c['usage_limit'] : '' ?></td>
            <td class="mono"><?= e(money((float) $c['given'])) ?></td>
            <td><span class="pill pill-<?= e($tone[$sk] ?? 'ink') ?>"><?= e($sl) ?></span></td>
            <td class="actions" style="white-space:nowrap;">
              <a href="/admin/coupon_form.php?id=<?= (int) $c['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
              <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="btn btn-outline btn-sm"><?= (int) $c['is_active'] ? 'Turn off' : 'Turn on' ?></button></form>
              <form method="post" action="/admin/coupon_delete.php" style="display:inline;" onsubmit="return confirm('Delete coupon <?= e($c['code']) ?>?<?= (int) $c['_used'] > 0 ? ' It has been used on ' . (int) $c['_used'] . ' order(s); those orders keep their discount.' : '' ?>');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
