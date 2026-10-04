<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'credits') {
        $ctext = mb_substr(trim($_POST['credit_text'] ?? ''), 0, 80);
        $curl = trim($_POST['credit_url'] ?? '');
        $surl = trim($_POST['source_url'] ?? '');
        if ($curl !== '' && !preg_match('~^https?://[^\s]+$~i', $curl)) $errors[] = 'The credit link must start with https://.';
        if ($surl !== '' && !preg_match('~^https?://[^\s]+$~i', $surl)) $errors[] = 'The source-code link must start with https://.';
        if (!$errors) {
            set_setting('credit_enabled', !empty($_POST['credit_enabled']) ? '1' : '0');
            set_setting('credit_text', $ctext !== '' ? $ctext : CREDIT_TEXT_DEFAULT);
            set_setting('credit_url', $curl);
            set_setting('source_link_enabled', !empty($_POST['source_link_enabled']) ? '1' : '0');
            set_setting('source_url', $surl);
            set_setting('footer_text', mb_substr(trim($_POST['footer_text'] ?? ''), 0, 200));
            admin_log('settings.footer', 'Footer credits updated');
            flash_set('success', 'Footer credits saved.');
            redirect('/admin/footer_links.php');
        }
    } elseif ($act === 'links') {
        $g = $_POST['group'] ?? []; $l = $_POST['label'] ?? []; $u = $_POST['url'] ?? []; $t = $_POST['new_tab'] ?? []; $go = $_POST['group_order'] ?? []; $so = $_POST['sort'] ?? [];
        $rows = [];
        foreach ($l as $i => $label) {
            $label = mb_substr(trim((string) $label), 0, 80); $url = trim((string) ($u[$i] ?? '')); $group = mb_substr(trim((string) ($g[$i] ?? '')), 0, 60);
            if ($label === '' && $url === '') continue;
            if ($label === '' || $url === '' || $group === '') { $errors[] = 'Every footer link needs a column title, a label and a link.'; break; }
            if (!preg_match('~^(https?://|mailto:|tel:|/)[^\s]*$~i', $url)) { $errors[] = '"' . $label . '": the link must start with https:// (or / for a page on this site).'; break; }
            $rows[] = [$group, $label, $url, !empty($t[$i]) ? 1 : 0, (int) ($go[$i] ?? 0), (int) ($so[$i] ?? 0)];
        }
        if (!$errors) {
            try {
                db()->beginTransaction();
                db()->exec('DELETE FROM footer_links');
                $ins = db()->prepare('INSERT INTO footer_links (group_title, label, url, new_tab, group_order, sort_order) VALUES (?,?,?,?,?,?)');
                foreach ($rows as $r) $ins->execute($r);
                db()->commit();
                admin_log('settings.footer_links', 'Footer links edited (' . count($rows) . ' links)');
                flash_set('success', 'Footer links saved.');
                redirect('/admin/footer_links.php');
            } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $errors[] = 'Could not save. Please try again.'; error_log('[footer_links] ' . $e->getMessage()); }
        }
    }
}

$rows = db()->query('SELECT * FROM footer_links ORDER BY group_order, group_title, sort_order, id')->fetchAll();
for ($i = 0; $i < 3; $i++) $rows[] = ['group_title' => '', 'label' => '', 'url' => '', 'new_tab' => 0, 'group_order' => 9, 'sort_order' => 9];
$pageTitle = 'Footer & credits';
require __DIR__ . '/includes/header.php';
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>

<section class="panel">
  <div class="panel-head"><h2>Footer links <span class="sub">columns of links at the bottom of every page</span></h2></div>
  <div class="panel-body">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="links">
      <p class="help">Links with the same <strong>column title</strong> are grouped together. Use <code>/contact</code> for a page on your site or a full <code>https://…</code> address for anything else (a Facebook page, a map, a PDF). Leave a row empty to remove it. Links to pages you switched off are hidden automatically.</p>
      <div style="overflow-x:auto;">
      <table class="admin-table">
        <thead><tr><th>Column title</th><th>Label</th><th>Link</th><th>New tab</th><th>Column #</th><th>Order</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td><input name="group[<?= $i ?>]" value="<?= e($r['group_title']) ?>" maxlength="60" placeholder="e.g. Support"></td>
            <td><input name="label[<?= $i ?>]" value="<?= e($r['label']) ?>" maxlength="80" placeholder="e.g. Contact us"></td>
            <td><input name="url[<?= $i ?>]" value="<?= e($r['url']) ?>" maxlength="500" placeholder="/contact or https://…"></td>
            <td style="text-align:center;"><input type="checkbox" name="new_tab[<?= $i ?>]" value="1" <?= $r['new_tab'] ? 'checked' : '' ?>></td>
            <td><input type="number" name="group_order[<?= $i ?>]" value="<?= (int) $r['group_order'] ?>" style="width:70px;"></td>
            <td><input type="number" name="sort[<?= $i ?>]" value="<?= (int) $r['sort_order'] ?>" style="width:70px;"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <p class="help">Need more rows? Save, and three empty rows are added again.</p>
      <button class="btn btn-primary">Save footer links</button>
    </form>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Credits &amp; source code</h2></div>
  <div class="panel-body">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="credits">
      <div class="field"><label for="footer_text">Extra footer text <span class="muted" style="font-weight:400;">(optional, next to the copyright line)</span></label><input id="footer_text" name="footer_text" maxlength="200" value="<?= e(get_setting('footer_text', '')) ?>"></div>
      <div class="checkbox-row" style="margin-bottom:10px;"><input type="checkbox" id="credit_enabled" name="credit_enabled" value="1" <?= get_setting('credit_enabled', '1') === '1' ? 'checked' : '' ?>><label for="credit_enabled" style="margin:0;font-weight:400;">Show a small credit line in the footer</label></div>
      <div class="field-row">
        <div class="field"><label for="credit_text">Credit text</label><input id="credit_text" name="credit_text" maxlength="80" value="<?= e(get_setting('credit_text', CREDIT_TEXT_DEFAULT)) ?>"></div>
        <div class="field"><label for="credit_url">Credit link</label><input id="credit_url" name="credit_url" value="<?= e(get_setting('credit_url', CREDIT_URL_DEFAULT)) ?>" placeholder="https://…"></div>
      </div>
      <div class="checkbox-row" style="margin-bottom:10px;"><input type="checkbox" id="source_link_enabled" name="source_link_enabled" value="1" <?= get_setting('source_link_enabled', '1') === '1' ? 'checked' : '' ?>><label for="source_link_enabled" style="margin:0;font-weight:400;">Show a “Source code” link in the footer</label></div>
      <div class="field"><label for="source_url">Source-code address</label><input id="source_url" name="source_url" value="<?= e(get_setting('source_url', (string) env_val('SOURCE_CODE_URL', SOURCE_CODE_URL_DEFAULT))) ?>" placeholder="https://github.com/…"></div>
      <p class="help">This software is open source (AGPL-3.0). If you run a modified copy for the public, the licence asks you to offer visitors its source code — keep this link pointing at your copy of the code. Anyone may switch the credit line off.</p>
      <button class="btn btn-primary">Save</button>
    </form>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
