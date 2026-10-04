<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();
$errors = [];

if (($_GET['download'] ?? '') === '1') {
    $data = ['format' => 'online-store-preset', 'version' => 1, 'exported' => gmdate('c'), 'settings' => [], 'footer_links' => [], 'site_pages' => [], 'payment_methods' => []];
    $in = preset_setting_keys();
    $st = db()->query('SELECT setting_key, setting_value FROM settings');
    foreach ($st->fetchAll() as $r) if (in_array($r['setting_key'], $in, true)) $data['settings'][$r['setting_key']] = $r['setting_value'];
    $data['footer_links'] = db()->query('SELECT group_title, label, url, new_tab, group_order, sort_order, is_active FROM footer_links ORDER BY group_order, sort_order')->fetchAll();
    $data['site_pages'] = db()->query('SELECT slug, title, body_html, is_enabled FROM site_pages')->fetchAll();
    $data['payment_methods'] = db()->query("SELECT code, name, kind, instructions, account_details, ask_txn, is_active, sort_order FROM payment_methods WHERE kind <> 'cod'")->fetchAll();
    admin_log('preset.export', 'Exported a settings preset');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="store-preset-' . date('Ymd') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'rerun') {
        set_setting('setup_done', '0');
        redirect('/admin/setup.php');
    } elseif ($act === 'import') {
        $f = $_FILES['preset'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1048576) $errors[] = 'Choose a preset .json file (up to 1 MB).';
        else {
            $d = json_decode((string) file_get_contents($f['tmp_name']), true);
            if (!is_array($d) || ($d['format'] ?? '') !== 'online-store-preset' || !is_array($d['settings'] ?? null)) $errors[] = 'That file is not a store preset.';
            else {
                $allowed = preset_setting_keys(); $n = 0;
                foreach ($d['settings'] as $k => $v) if (in_array($k, $allowed, true) && (is_string($v) || $v === null) && mb_strlen((string) $v) < 4000) { set_setting($k, (string) $v); $n++; }
                // font uploads are files, not settings: an imported "upload" choice falls back to the default until re-uploaded
                foreach (['title', 'primary', 'secondary'] as $r) if (get_setting('font_' . $r . '_src') === 'upload' && get_setting('font_' . $r . '_file', '') === '') set_setting('font_' . $r . '_src', 'default');
                if (is_array($d['footer_links'] ?? null) && $d['footer_links']) {
                    db()->exec('DELETE FROM footer_links');
                    $ins = db()->prepare('INSERT INTO footer_links (group_title, label, url, new_tab, group_order, sort_order, is_active) VALUES (?,?,?,?,?,?,?)');
                    foreach ($d['footer_links'] as $r) if (!empty($r['label']) && !empty($r['url']) && preg_match('~^(https?://|mailto:|tel:|/)~i', $r['url'])) $ins->execute([mb_substr((string) $r['group_title'], 0, 60), mb_substr((string) $r['label'], 0, 80), mb_substr((string) $r['url'], 0, 500), (int) !empty($r['new_tab']), (int) ($r['group_order'] ?? 0), (int) ($r['sort_order'] ?? 0), (int) ($r['is_active'] ?? 1)]);
                }
                if (is_array($d['site_pages'] ?? null)) {
                    $defs = site_page_defs();
                    $ins = db()->prepare('INSERT INTO site_pages (slug, title, body_html, is_enabled) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE title = VALUES(title), body_html = VALUES(body_html), is_enabled = VALUES(is_enabled)');
                    foreach ($d['site_pages'] as $r) if (isset($defs[$r['slug'] ?? ''])) $ins->execute([$r['slug'], mb_substr((string) $r['title'], 0, 120), ($b = sanitize_page_html((string) ($r['body_html'] ?? ''))) !== '' ? $b : null, (int) !empty($r['is_enabled'])]);
                }
                if (is_array($d['payment_methods'] ?? null)) {
                    foreach ($d['payment_methods'] as $r) {
                        $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($r['code'] ?? ''))); if ($code === '' || $code === 'cod') continue;
                        $ex = db()->prepare('SELECT id FROM payment_methods WHERE code = ?'); $ex->execute([$code]);
                        $vals = [mb_substr((string) $r['name'], 0, 80), $r['instructions'] ?? null, $r['account_details'] ?? null, (int) !empty($r['ask_txn']), (int) !empty($r['is_active']), (int) ($r['sort_order'] ?? 0)];
                        if ($id = $ex->fetchColumn()) db()->prepare('UPDATE payment_methods SET name=?, instructions=?, account_details=?, ask_txn=?, is_active=?, sort_order=? WHERE id=?')->execute([...$vals, $id]);
                        else db()->prepare("INSERT INTO payment_methods (name, instructions, account_details, ask_txn, is_active, sort_order, code, kind) VALUES (?,?,?,?,?,?,?,'manual')")->execute([...$vals, $code]);
                    }
                }
                set_setting('setup_done', '1');
                admin_log('preset.import', 'Imported a settings preset (' . $n . ' settings)');
                flash_set('success', 'Preset imported. Logos and uploaded fonts are not part of a preset — upload them again if you need them.');
                redirect('/admin/preset.php');
            }
        }
    }
}
$pageTitle = 'Backup & presets';
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-error">' . e($er) . '</div>';
?>
<section class="panel"><div class="panel-head"><h2>Export your settings</h2></div><div class="panel-body">
  <p>Download your store details, colours, fonts choices, delivery and tax numbers, footer links, page wording and manual payment methods as one file. Use it to set up another store the same way.</p>
  <p class="help">It never contains passwords, email passwords, API keys, customers, orders or the accounting-link secret. Uploaded images and font files are not included.</p>
  <a class="btn btn-primary" href="/admin/preset.php?download=1">Download preset</a>
</div></section>
<section class="panel"><div class="panel-head"><h2>Import a preset</h2></div><div class="panel-body">
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="import">
    <div class="field"><input type="file" name="preset" accept=".json,application/json" required></div>
    <p class="help">Importing replaces the footer links and overwrites the settings and pages that are in the file. Everything else stays.</p>
    <button class="btn btn-primary" onclick="return confirm('Import this preset? It overwrites matching settings.');">Import preset</button>
  </form>
</div></section>
<section class="panel"><div class="panel-head"><h2>Setup wizard</h2></div><div class="panel-body">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rerun"><p>Walk through the first-run setup again (it keeps your products and orders).</p><button class="btn btn-outline">Run the setup wizard</button></form>
</div></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
