<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
// Owners only, and before anything else runs (this file also handles POSTs).
require_owner();
$me = current_admin();
$pageTitle = 'Staff';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $t = staff_get((int) ($_POST['id'] ?? 0));
    if ($t && ($_POST['action'] ?? '') === 'toggle') {
        if ((int) $t['id'] === (int) $me['id']) {
            flash_set('error', 'You can\'t disable your own account.');
        } elseif ($t['status'] === 'active' && $t['role'] === 'owner' && staff_active_owner_count((int) $t['id']) < 1) {
            flash_set('error', 'There must always be at least one active owner.');
        } else {
            $to = $t['status'] === 'active' ? 'disabled' : 'active';
            db()->prepare('UPDATE admins SET status = ? WHERE id = ?')->execute([$to, $t['id']]);
            admin_log($to === 'disabled' ? 'staff.disable' : 'staff.enable', ($to === 'disabled' ? 'Disabled' : 'Re-enabled') . ' the account of ' . $t['name'] . ' (@' . $t['username'] . ')', 'staff', (int) $t['id']);
            flash_set('success', $t['name'] . '\'s account is now ' . ($to === 'disabled' ? 'disabled — they can no longer sign in.' : 'active again.'));
        }
    }
    redirect('/admin/staff.php' . (!empty($_GET['show']) ? '?show=' . urlencode((string) $_GET['show']) : ''));
}

$q = trim((string) ($_GET['q'] ?? ''));
$show = (string) ($_GET['show'] ?? '');
$all = staff_list($q);
$counts = ['owner' => 0, 'staff' => 0, 'disabled' => 0];
foreach ($all as $r) { $counts[$r['role']]++; if ($r['status'] !== 'active') $counts['disabled']++; }
$rows = array_values(array_filter($all, fn ($r) => match ($show) { 'owner' => $r['role'] === 'owner', 'staff' => $r['role'] === 'staff', 'disabled' => $r['status'] !== 'active', default => true }));
function staff_url(array $o = []): string { $q = array_filter(array_merge($_GET, $o), fn ($v) => $v !== ''); return '/admin/staff.php' . ($q ? '?' . http_build_query($q) : ''); }

require __DIR__ . '/includes/header.php';
?>
<div class="panel">
  <div class="panel-head">
    <h2>Staff <span class="sub"><?= count($all) ?> <?= $q !== '' ? 'matching' : 'people' ?></span></h2>
    <a href="/admin/staff_form.php" class="btn btn-primary btn-sm">+ Add staff member</a>
  </div>
  <p class="help" style="padding:0 22px;margin:0 0 4px;">Everyone here can sign in to this admin portal. Only owners can add, change, disable or delete staff — staff can see their own details but not edit them.</p>

  <form class="toolbar" method="get">
    <div class="search">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, username, number or email…" aria-label="Search staff">
    </div>
    <?php if ($show !== ''): ?><input type="hidden" name="show" value="<?= e($show) ?>"><?php endif; ?>
    <button class="btn btn-primary" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-outline" href="<?= e(staff_url(['q' => ''])) ?>">Clear</a><?php endif; ?>
    <span class="spacer"></span>
    <div class="seg">
      <a href="<?= e(staff_url(['show' => ''])) ?>" class="<?= $show === '' ? 'active' : '' ?>">All<span class="n"><?= count($all) ?></span></a>
      <a href="<?= e(staff_url(['show' => 'owner'])) ?>" class="<?= $show === 'owner' ? 'active' : '' ?>">Owners<span class="n"><?= $counts['owner'] ?></span></a>
      <a href="<?= e(staff_url(['show' => 'staff'])) ?>" class="<?= $show === 'staff' ? 'active' : '' ?>">Staff<span class="n"><?= $counts['staff'] ?></span></a>
      <a href="<?= e(staff_url(['show' => 'disabled'])) ?>" class="<?= $show === 'disabled' ? 'active' : '' ?>">Disabled<span class="n"><?= $counts['disabled'] ?></span></a>
    </div>
  </form>

  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th>Name</th><th>Role</th><th>Number</th><th>Email</th><th>ID</th><th>Status</th><th title="Facebook profile">FB</th><th title="Has an attached document">Doc</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr class="empty-row"><td colspan="9">No staff match.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $self = (int) $r['id'] === (int) $me['id']; $incomplete = staff_incomplete($r); ?>
          <tr>
            <td><div class="name-cell"><?= staff_avatar_html((int) $r['id'], $r['name'], $r['photo_v'], 38) ?><div><a href="/admin/staff_form.php?id=<?= (int) $r['id'] ?>" style="font-weight:600;"><?= e($r['name']) ?></a><?php if ($self): ?> <span class="pill pill-brass">You</span><?php endif; ?><br><span class="muted small">@<?= e($r['username']) ?></span><?php if ($incomplete): ?><br><span class="small" style="color:var(--rust);">Details incomplete</span><?php endif; ?></div></div></td>
            <td><span class="pill <?= $r['role'] === 'owner' ? 'pill-brass' : 'pill-ink' ?>"><?= $r['role'] === 'owner' ? 'Owner' : 'Staff' ?></span></td>
            <td class="mono small"><?= e($r['phone'] ?: '—') ?></td>
            <td class="small"><?= e($r['email'] ?: '—') ?></td>
            <td class="mono small"><?php if ($r['id_number']): ?><span class="muted"><?= e(STAFF_ID_TYPES[$r['id_type']] ?? 'ID') ?></span><br><?= e(staff_mask_id($r['id_number'])) ?><?php else: ?>—<?php endif; ?></td>
            <td><span class="pill <?= $r['status'] === 'active' ? 'pill-sage' : 'pill-rust' ?>"><?= $r['status'] === 'active' ? 'Active' : 'Disabled' ?></span><?php if ($r['must_change_password']): ?><br><span class="muted small">must set password</span><?php endif; ?></td>
            <td><?php if ($r['facebook_url']): ?><a href="<?= e($r['facebook_url']) ?>" target="_blank" rel="noopener noreferrer nofollow" title="Open Facebook profile" style="font-weight:700;">f</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><?= (int) $r['has_doc'] ? ui_icon('paperclip', 16) : '<span class="muted">—</span>' ?></td>
            <td class="actions" style="white-space:nowrap;">
              <a href="/admin/staff_form.php?id=<?= (int) $r['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
              <?php if (!$self): ?>
                <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="btn btn-outline btn-sm"><?= $r['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form>
                <form method="post" action="/admin/staff_delete.php" style="display:inline;" onsubmit="return confirm('Permanently delete <?= e(addslashes($r['name'])) ?> and their attached document? To just stop them signing in, use Disable instead.');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
