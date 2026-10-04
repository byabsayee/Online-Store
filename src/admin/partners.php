<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();
$errors = [];
$editId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'toggle') {
        $on = !empty($_POST['partners_enabled']);
        set_setting('partners_enabled', $on ? '1' : '0');
        if ($on && !(int) db()->query("SELECT COUNT(*) FROM footer_links WHERE url = '/partners'")->fetchColumn()) {
            $g = db()->query("SELECT group_title, group_order FROM footer_links WHERE group_title = 'Support' LIMIT 1")->fetch() ?: ['group_title' => 'Support', 'group_order' => 1];
            db()->prepare("INSERT INTO footer_links (group_title, label, url, group_order, sort_order) VALUES (?, 'Our partners', '/partners', ?, 50)")->execute([$g['group_title'], $g['group_order']]);
        }
        admin_log('partners.toggle', 'Partners page switched ' . ($on ? 'on' : 'off'));
        flash_set('success', $on ? 'Partners page is on — it is linked from your footer.' : 'Partners page is off.');
        redirect('/admin/partners.php');
    } elseif ($act === 'save') {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 1500);
        $link = trim($_POST['link_url'] ?? '');
        $extra = [];
        foreach (preg_split('/\R/', (string) ($_POST['extra_links'] ?? '')) as $ln) {
            $ln = trim($ln); if ($ln === '') continue;
            $parts = array_map('trim', explode('|', $ln, 2));
            [$t, $u] = count($parts) === 2 ? $parts : ['', $parts[0]];
            if (!preg_match('~^https?://[^\s]+$~i', $u)) { $errors[] = 'Each extra link must be "Title | https://address" (or just the address). Problem with: ' . mb_substr($ln, 0, 60); break; }
            $extra[] = ['title' => mb_substr($t, 0, 60), 'url' => $u];
        }
        if ($name === '') $errors[] = 'Give the partner a name.';
        if ($link !== '' && !preg_match('~^https?://[^\s]+$~i', $link)) $errors[] = 'The main link must start with https://.';
        $row = null;
        if ($id) { $st = db()->prepare('SELECT * FROM partners WHERE id = ?'); $st->execute([$id]); $row = $st->fetch(); }
        $logo = $row['logo_url'] ?? null;
        if (!$errors) {
            try { if ($new = site_upload_image('logo')) { brand_delete_file($logo); $logo = $new; } }
            catch (RuntimeException $e) { $errors[] = 'Logo: ' . $e->getMessage(); }
            if (!empty($_POST['remove_logo']) && empty($_FILES['logo']['name'])) { brand_delete_file($logo); $logo = null; }
        }
        if (!$errors) {
            $vals = [$name, $desc ?: null, $logo, $link ?: null, $extra ? json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, (int) ($_POST['sort_order'] ?? 0), !empty($_POST['is_active']) ? 1 : 0];
            if ($row) { db()->prepare('UPDATE partners SET name=?, description=?, logo_url=?, link_url=?, extra_links=?, sort_order=?, is_active=? WHERE id=?')->execute([...$vals, $id]); }
            else { db()->prepare('INSERT INTO partners (name, description, logo_url, link_url, extra_links, sort_order, is_active) VALUES (?,?,?,?,?,?,?)')->execute($vals); $id = (int) db()->lastInsertId(); }
            admin_log($row ? 'partners.update' : 'partners.create', ($row ? 'Edited' : 'Added') . ' partner "' . $name . '"', 'partner', $id);
            flash_set('success', 'Partner saved.');
            redirect('/admin/partners.php');
        }
        $editId = $id;
    } elseif ($act === 'delete') {
        $st = db()->prepare('SELECT * FROM partners WHERE id = ?'); $st->execute([$id]);
        if ($p = $st->fetch()) { brand_delete_file($p['logo_url']); db()->prepare('DELETE FROM partners WHERE id = ?')->execute([$id]); admin_log('partners.delete', 'Removed partner "' . $p['name'] . '"', 'partner', $id); flash_set('success', 'Partner removed.'); }
        redirect('/admin/partners.php');
    }
}

$partners = partners_list(false);
$edit = null;
foreach ($partners as $p) if ((int) $p['id'] === $editId) $edit = $p;
$extraText = '';
if ($edit) { $x = json_decode((string) $edit['extra_links'], true) ?: []; foreach ($x as $l) $extraText .= ($l['title'] ? $l['title'] . ' | ' : '') . $l['url'] . "\n"; }
$pageTitle = 'Partners page';
require __DIR__ . '/includes/header.php';
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
<section class="panel">
  <div class="panel-head"><h2>Partners page</h2></div>
  <div class="panel-body">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle">
      <div class="checkbox-row" style="margin-bottom:12px;"><input type="checkbox" id="pe" name="partners_enabled" value="1" <?= partners_enabled() ? 'checked' : '' ?>><label for="pe" style="margin:0;font-weight:400;">Show a public “Our partners” page (<a href="/partners" target="_blank" class="link">/partners</a>) and link it from the footer</label></div>
      <p class="help">Use it to promote other people or brands — each partner has a logo, name, description and any links you want.</p>
      <button class="btn btn-primary">Save</button>
    </form>
  </div>
</section>
<section class="panel">
  <div class="panel-head"><h2>Partners</h2></div>
  <?php if (!$partners): ?><div class="panel-body"><p class="muted">No partners yet — add the first one below.</p></div><?php else: ?>
  <table class="admin-table"><thead><tr><th></th><th>Name</th><th>Links</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($partners as $p): ?><tr>
      <td style="width:60px;"><?php if ($p['logo_url']): ?><img src="<?= e($p['logo_url']) ?>" alt="" style="width:44px;height:44px;object-fit:contain;"><?php endif; ?></td>
      <td><strong><?= e($p['name']) ?></strong><div class="muted small"><?= e(mb_substr((string) $p['description'], 0, 90)) ?></div></td>
      <td><?= count($p['links']) ?></td>
      <td><?= $p['is_active'] ? '<span class="pill pill-sage">Shown</span>' : '<span class="pill pill-ink">Hidden</span>' ?></td>
      <td style="text-align:right;"><a class="btn btn-outline btn-sm" href="/admin/partners.php?edit=<?= (int) $p['id'] ?>">Edit</a>
        <form method="post" style="display:inline;" onsubmit="return confirm('Remove this partner?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-danger btn-sm">Remove</button></form></td>
    </tr><?php endforeach; ?></tbody></table>
  <?php endif; ?>
</section>
<section class="panel">
  <div class="panel-head"><h2><?= $edit ? 'Edit “' . e($edit['name']) . '”' : 'Add a partner' ?></h2></div>
  <div class="panel-body">
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="field-row">
        <div class="field"><label for="pn">Name</label><input id="pn" name="name" maxlength="120" required value="<?= e($_POST['name'] ?? ($edit['name'] ?? '')) ?>"></div>
        <div class="field"><label for="ps">Order</label><input type="number" id="ps" name="sort_order" value="<?= e((string) ($_POST['sort_order'] ?? ($edit['sort_order'] ?? count($partners)))) ?>"></div>
      </div>
      <div class="field"><label for="pd">Description</label><textarea id="pd" name="description" rows="3" maxlength="1500"><?= e($_POST['description'] ?? ($edit['description'] ?? '')) ?></textarea></div>
      <div class="field"><label for="pl">Main link <span class="muted" style="font-weight:400;">(shown as “Visit website”)</span></label><input id="pl" name="link_url" placeholder="https://…" value="<?= e($_POST['link_url'] ?? ($edit['link_url'] ?? '')) ?>"></div>
      <div class="field"><label for="px">More links <span class="muted" style="font-weight:400;">(one per line: <code>Title | https://address</code>)</span></label><textarea id="px" name="extra_links" rows="3" placeholder="Facebook | https://facebook.com/…&#10;Shop | https://…"><?= e($_POST['extra_links'] ?? $extraText) ?></textarea></div>
      <div class="field"><label for="plogo">Logo <span class="muted" style="font-weight:400;">(PNG, JPG, WebP or SVG, up to 2 MB)</span></label>
        <?php if (!empty($edit['logo_url'])): ?><div style="margin-bottom:6px;"><img src="<?= e($edit['logo_url']) ?>" alt="" style="height:48px;"> <label style="font-weight:400;"><input type="checkbox" name="remove_logo" value="1"> remove</label></div><?php endif; ?>
        <input type="file" id="plogo" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml"></div>
      <div class="checkbox-row" style="margin-bottom:14px;"><input type="checkbox" id="pa" name="is_active" value="1" <?= !$edit || !empty($edit['is_active']) ? 'checked' : '' ?>><label for="pa" style="margin:0;font-weight:400;">Show this partner</label></div>
      <button class="btn btn-primary"><?= $edit ? 'Save changes' : 'Add partner' ?></button>
      <?php if ($edit): ?><a class="btn btn-outline" href="/admin/partners.php">Cancel</a><?php endif; ?>
    </form>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
