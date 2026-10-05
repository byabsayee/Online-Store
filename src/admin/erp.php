<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner(); // first statement that matters: every POST below changes money-adjacent state

$admin = current_admin();
$by = $admin['name'] ?? 'Admin';
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'setup', 'queue', 'conflicts', 'log', 'import'], true)) $tab = 'overview';
$self = '/admin/erp.php';
$errors = [];
$revealed = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['action'] ?? '';
    $go = fn (string $t = 'overview') => redirect($self . '?tab=' . $t);
    switch ($act) {
        case 'connect':
            [$ok, $msg] = erp_connect(trim($_POST['book_url'] ?? ''), trim($_POST['pairing_code'] ?? ''), [
                'connection_id' => $_POST['connection_id'] ?? '', 'api_key' => $_POST['api_key'] ?? '',
                'secret_site_to_book' => $_POST['secret_site_to_book'] ?? '', 'secret_book_to_site' => $_POST['secret_book_to_site'] ?? '']);
            if ($ok) admin_log('erp.connect', 'Started linking the store to the accounting book at ' . (erp_conn(true)['book_base_url'] ?? ''));
            flash_set($ok ? 'success' : 'error', $msg); $go();
        case 'invoice_source':
            $v = (string) ($_POST['source'] ?? '');
            if (!in_array($v, ['store', 'book'], true)) { flash_set('error', 'Choose one of the two invoice options.'); $go(); }
            set_setting('invoice_source', $v);
            admin_log('erp.invoice_source', 'Customer invoices now come from ' . ($v === 'book' ? 'Byabsayee' : 'this store'));
            flash_set('success', $v === 'book' ? 'Customers now see the invoice Byabsayee generates.' : 'Customers now see this store\'s own invoice.'); $go();
        case 'pause': erp_pause(); admin_log('erp.state', 'Accounting sync paused'); flash_set('success', 'Sync paused. Changes keep queuing and are sent when you resume.'); $go();
        case 'resume': erp_resume(); admin_log('erp.state', 'Accounting sync resumed'); flash_set('success', 'Sync resumed.'); $go();
        case 'disconnect':
            if (($_POST['confirm'] ?? '') !== 'DISCONNECT') { flash_set('error', 'Type DISCONNECT to confirm.'); $go(); }
            $m = erp_disconnect(true); admin_log('erp.disconnect', 'Accounting link disconnected'); flash_set('success', $m); $go();
        case 'reset':
            if (erp_status() === 'revoked') { erp_conn_update(['status' => 'disabled', 'connection_id' => null, 'book_base_url' => null, 'peer_capabilities' => null, 'book_info' => null, 'authority' => null, 'last_error' => null]); set_setting('erp_setup_done', '0'); }
            $go();
        case 'rotate': [$ok, $m] = erp_rotate_credentials(); if ($ok) admin_log('erp.rotate', 'Accounting credentials rotated'); flash_set($ok ? 'success' : 'error', $m); $go();
        case 'flush': $s = erp_flush(10); flash_set('success', 'Sent ' . $s['sent'] . ', retrying ' . $s['failed'] . ', gave up on ' . $s['dead'] . '.'); $go('queue');
        case 'retry': erp_outbox_retry((int) ($_POST['id'] ?? 0)); $go('queue');
        case 'retry_all': $n = erp_outbox_retry_all_dead(); flash_set('success', $n . ' event(s) queued again.'); $go('queue');
        case 'discard': erp_outbox_discard((int) ($_POST['id'] ?? 0)); $go('queue');
        case 'auto_setup': [$ok, $m] = erp_auto_setup(); if ($ok) admin_log('erp.setup', 'Automatic setup finished'); flash_set($ok ? 'success' : 'error', $m); $go($ok ? 'overview' : 'setup');
        case 'scan': [$ok, $m] = erp_initial_scan(); flash_set($ok ? 'success' : 'error', $m); $go('setup');
        case 'match': flash_set('info', erp_match_resolve((int) ($_POST['id'] ?? 0), (string) ($_POST['choice'] ?? ''))); $go('setup');
        case 'match_bulk':
            $n = erp_match_bulk((string) ($_POST['entity'] ?? ''), (string) ($_POST['kind'] ?? ''), (string) ($_POST['choice'] ?? ''));
            admin_log('erp.setup', 'Initial matching: bulk "' . ($_POST['choice'] ?? '') . '" on ' . $n . ' item(s)'); flash_set('success', $n . ' item(s) updated.'); $go('setup');
        case 'finish_setup': [$ok, $m] = erp_finish_setup(); if ($ok) admin_log('erp.setup', 'Initial matching finished'); flash_set($ok ? 'success' : 'error', $m); $go($ok ? 'overview' : 'setup');
        case 'reconcile':
            $r = erp_reconcile();
            flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? ('Reconciled: ' . $r['drift_total'] . ' difference(s), ' . $r['stock']['corrected'] . ' stock correction(s).') : ('Reconcile failed: ' . $r['error'])); $go();
        case 'resend': $n = erp_resend_missing((string) $_POST['entity'], (array) ($_POST['uuids'] ?? [])); flash_set('success', $n . ' item(s) queued to send to the book.'); $go();
        case 'pull': [$n, $errs] = erp_pull_from_book((string) $_POST['entity'], (array) ($_POST['uuids'] ?? [])); flash_set($errs ? 'error' : 'success', $n . ' item(s) brought in' . ($errs ? '; problems: ' . implode(' | ', array_slice($errs, 0, 3)) : '.')); $go();
        case 'conflict':
            $cid = (int) ($_POST['id'] ?? 0); $choice = (string) ($_POST['choice'] ?? '');
            if (in_array($choice, ['link', 'separate'], true)) { flash_set('info', erp_resolve_customer_match($cid, $choice, $by)); }
            else {
                $st = db()->prepare("SELECT * FROM sync_conflicts WHERE id = ? AND status = 'open'"); $st->execute([$cid]);
                if ($c = $st->fetch()) {
                    if ($choice === 'keep_local' && $c['entity_uuid']) { $n = erp_resend_missing($c['entity'], [$c['entity_uuid']]); }
                    db()->prepare("UPDATE sync_conflicts SET status = ?, resolution = ?, resolved_by = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$choice === 'dismiss' ? 'dismissed' : 'resolved', $choice, mb_substr($by, 0, 120), $cid]);
                    admin_log('erp.conflict', 'Resolved a ' . $c['kind'] . ' sync conflict: ' . $choice);
                    flash_set('success', 'Done.');
                }
            }
            $go('conflicts');
        case 'import_plan':
            [$ok, $m, $bid] = erp_import_plan((string) ($_POST['direction'] ?? ''), (array) ($_POST['entities'] ?? []), trim($_POST['date_from'] ?? '') ?: null, trim($_POST['date_to'] ?? '') ?: null, (string) ($_POST['stock_mode'] ?? 'none'), $by);
            flash_set($ok ? 'success' : 'error', $m); redirect($self . '?tab=import' . ($ok ? '&batch=' . $bid : ''));
        case 'import_start': flash_set('info', erp_import_start((int) $_POST['id'])); admin_log('erp.import', 'Started import #' . (int) $_POST['id']); erp_import_step((int) $_POST['id'], 20); redirect($self . '?tab=import&batch=' . (int) $_POST['id']);
        case 'import_step': erp_import_step((int) $_POST['id'], 100); redirect($self . '?tab=import&batch=' . (int) $_POST['id']);
        case 'import_pause': erp_import_pause((int) $_POST['id']); redirect($self . '?tab=import&batch=' . (int) $_POST['id']);
        case 'import_resume': erp_import_resume((int) $_POST['id']); redirect($self . '?tab=import&batch=' . (int) $_POST['id']);
        case 'import_rollback': flash_set('info', erp_import_rollback((int) $_POST['id'])); admin_log('erp.import', 'Rolled back import #' . (int) $_POST['id']); redirect($self . '?tab=import&batch=' . (int) $_POST['id']);
    }
}

$conn = erp_conn(true);
$status = $conn['status'];
$q = erp_queue_stats();
$statusLabel = ['disabled' => 'Not connected', 'pending' => 'Connecting…', 'verifying' => 'Waiting for the book to finish linking', 'active' => 'Connected', 'paused' => 'Paused', 'revoked' => 'Disconnected'][$status] ?? $status;
$openConf = (int) db()->query("SELECT COUNT(*) FROM sync_conflicts WHERE status = 'open'")->fetchColumn();
$pageTitle = 'Accounting link';
require __DIR__ . '/includes/header.php';
$tabs = ['overview' => 'Overview', 'setup' => 'Setup review', 'queue' => 'Sync queue' . ($q['dead'] ? ' (' . $q['dead'] . ' stuck)' : ''), 'conflicts' => 'Conflicts' . ($openConf ? ' (' . $openConf . ')' : ''), 'log' => 'Activity', 'import' => 'History import'];
?>
<div class="seg" role="tablist" style="margin-bottom:18px;display:inline-flex;">
  <?php foreach ($tabs as $k => $label): ?><a href="<?= $self ?>?tab=<?= $k ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
</div>

<?php if ($tab === 'overview'): ?>
<section class="panel">
  <div class="panel-head"><h2>Byabsayee link <span class="sub"><?= e($statusLabel) ?></span></h2></div>
  <div class="panel-body">
    <?php if ($conn['last_error'] && $status !== 'disabled'): ?><div class="alert alert-error">Last problem: <?= e($conn['last_error']) ?></div><?php endif; ?>
    <?php if (in_array($status, ['disabled', 'pending', 'revoked'], true)): ?>
      <?php if ($status === 'revoked'): ?>
        <p>The link was disconnected. Your records and their matches are kept, so linking the same book again resumes where it stopped.</p>
      <?php else: ?>
        <p>Link this store to your Byabsayee accounting book so orders, payments, stock and customers stay in step. It stays completely off until you do this, and your shop works exactly as before.</p>
      <?php endif; ?>
      <?php if ($err = erp_site_public_error()): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
      <?php if (!$err && ($__h = (string) parse_url(site_url(), PHP_URL_HOST)) !== ''): ?>
        <div class="alert alert-info">This store will introduce itself to Byabsayee as <strong><?= e($__h) ?></strong>. The pairing code must have been created for exactly this domain.
          Wrong domain? Change it under <a href="/admin/settings.php">Settings &amp; email → Public site address</a> (that saved value overrides <code>SITE_URL</code> in <code>.env</code>), then come back here.</div>
      <?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="connect">
        <div class="field"><label for="book_url">Byabsayee address</label><input id="book_url" name="book_url" placeholder="https://books.example.com" value="https://web.byabsayee.com" required></div>
        <div class="field"><label for="pairing_code">Pairing code</label><input id="pairing_code" name="pairing_code" autocomplete="off" placeholder="Generated in Byabsayee → Integrations"><div class="hint">Easiest way. Or use the manual credentials below.</div></div>
        <details class="log-details"><summary>Enter the credentials by hand instead</summary>
          <div class="field" style="margin-top:12px;"><label for="connection_id">Connection ID</label><input id="connection_id" name="connection_id" autocomplete="off"></div>
          <div class="field"><label for="api_key">API key</label><input id="api_key" name="api_key" type="password" autocomplete="off"></div>
          <div class="field-row"><div class="field"><label for="s1">Secret: store → book</label><input id="s1" name="secret_site_to_book" type="password" autocomplete="off"></div>
          <div class="field"><label for="s2">Secret: book → store</label><input id="s2" name="secret_book_to_site" type="password" autocomplete="off"></div></div></details>
        <p class="help">The book must be on its own HTTPS domain, and so must this store. Nothing is exchanged with any server that isn't that address.</p>
        <button class="btn btn-primary">Connect</button>
      </form>
      <?php if ($status === 'revoked'): ?><form method="post" style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><button class="btn btn-outline btn-sm">Forget this link and start over</button></form><?php endif; ?>
    <?php else: ?>
      <table class="admin-table" style="margin-bottom:16px;"><tbody>
        <tr><th style="width:220px;">Book</th><td><?= e($conn['book_base_url']) ?></td></tr>
        <tr><th>Connection</th><td class="mono"><?= e($conn['connection_id']) ?></td></tr>
        <tr><th>Who decides currency, timezone, tax</th><td><?= ($conn['authority'] ?? '') === 'book' ? 'The accounting book' : (($conn['authority'] ?? '') === 'site' ? 'This website' : 'Not chosen yet') ?></td></tr>
        <tr><th>Last successful sync</th><td><?= e($conn['last_sync_at'] ? fmt_dt($conn['last_sync_at']) : 'Not yet') ?></td></tr>
        <tr><th>Waiting to send</th><td><?= (int) $q['pending'] ?> event(s)<?= $q['dead'] ? ' · <strong style="color:var(--rust);">' . (int) $q['dead'] . ' stuck</strong> (see Sync queue)' : '' ?></td></tr>
        <tr><th>Module / book version</th><td><?= e(ERP_MODULE_VERSION) ?> / <?= e($conn['peer_module_version'] ?: '—') ?></td></tr>
      </tbody></table>
      <?php
        // The link remembers the store's public domain from the moment it was paired. If the store's address was changed afterwards
        // (new domain, http→https, www on/off), the book would keep calling the old one — tell the owner instead of failing silently.
        $pairedHost = strtolower((string) ($conn['site_domain'] ?? ''));
        $nowHost = strtolower((string) parse_url(site_url(), PHP_URL_HOST));
        if ($pairedHost !== '' && $nowHost !== '' && $pairedHost !== $nowHost && !erp_test_mode()): ?>
        <div class="alert alert-error"><strong>The store's address changed.</strong> This link was set up for <code><?= e($pairedHost) ?></code> but the store now answers as <code><?= e($nowHost) ?></code>, so the book can no longer reach it.
          Fix: <em>Disconnect</em> below, generate a new pairing code in Byabsayee, and connect again — your records and their matches are kept, so nothing is duplicated.</div>
      <?php endif; ?>
      <?php if ($status === 'verifying'): ?><p>The book is checking that this domain is yours. This page updates once it finishes — reload in a few seconds.</p><?php endif; ?>
      <?php if ($status === 'active' && !erp_setup_done()): ?><div class="alert alert-info"><strong>One more step:</strong> the two catalogs still need to be matched before anything syncs. This runs automatically within a minute, or <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="auto_setup"><button class="btn btn-primary">match everything now</button></form> &middot; <a href="<?= $self ?>?tab=setup">review it item by item →</a></div><?php endif; ?>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php if ($status === 'active'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pause"><button class="btn btn-outline">Pause sync</button></form>
        <?php elseif ($status === 'paused'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resume"><button class="btn btn-primary">Resume sync</button></form><?php endif; ?>
        <?php if (in_array($status, ['active', 'paused'], true)): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reconcile"><button class="btn btn-outline">Reconcile now</button></form>
          <form method="post" onsubmit="return confirm('Get a fresh API key and secrets from the book?');"><?= csrf_field() ?><input type="hidden" name="action" value="rotate"><button class="btn btn-outline">Rotate credentials</button></form>
        <?php endif; ?>
      </div>
      <details class="log-details" style="margin-top:18px;"><summary>Disconnect</summary>
        <form method="post" style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="disconnect">
          <p class="help">Stops all syncing and removes the stored keys. Nothing is deleted here or at the book. Waiting events stay queued.</p>
          <div class="field"><label for="dc">Type DISCONNECT to confirm</label><input id="dc" name="confirm" autocomplete="off"></div>
          <button class="btn btn-danger">Disconnect</button></form></details>
    <?php endif; ?>
  </div>
</section>
<?php
  $__linked = in_array($status, ['active', 'paused'], true);
  $__eff = invoice_source();
  $__pref = invoice_source_pref();
  $__known = (int) db()->query("SELECT COUNT(*) FROM orders WHERE book_invoice_no IS NOT NULL")->fetchColumn();
  $__total = (int) db()->query("SELECT COUNT(*) FROM orders")->fetchColumn();
?>
<section class="panel">
  <div class="panel-head"><h2>Customer invoices <span class="sub">Showing: <?= $__eff === 'book' ? 'Byabsayee invoice' : 'Store invoice' ?></span></h2></div>
  <div class="panel-body">
    <p class="help">Pick which invoice <strong>customers of this website</strong> see — on their order page, in the downloadable invoice PDF and in order emails. It also decides the look of the <strong>Invoice ID</strong>. This only changes this website; Byabsayee is not affected.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="invoice_source">
      <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0;cursor:<?= $__linked ? 'pointer' : 'not-allowed' ?>;<?= $__linked ? '' : 'opacity:.55;' ?>">
        <input type="radio" name="source" value="book" <?= $__eff === 'book' ? 'checked' : '' ?> <?= $__linked ? '' : 'disabled' ?>>
        <span><strong>Byabsayee invoice</strong> <span class="muted">(recommended when connected)</span><br><span class="muted small">The invoice your accounting book generates. Invoice ID looks like <span class="mono">INV-000123</span>. If one isn't available yet, the store invoice is shown instead.<?= $__linked ? '' : ' Needs the Byabsayee link below.' ?></span></span></label>
      <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0;cursor:pointer;">
        <input type="radio" name="source" value="store" <?= $__eff === 'store' ? 'checked' : '' ?>>
        <span><strong>Store invoice</strong> <span class="muted">(independent)</span><br><span class="muted small">The invoice this website creates by itself. Invoice ID is the order number, like <span class="mono">ORD-260928-AB12C</span>. Works with or without Byabsayee.</span></span></label>
      <button class="btn btn-primary btn-sm">Save choice</button>
      <?php if ($__pref === '' && $__linked): ?><span class="muted small" style="margin-left:8px;">Not chosen yet — the Byabsayee invoice is used by default while linked.</span><?php endif; ?>
    </form>
    <?php if ($__linked): ?><p class="muted small" style="margin-top:12px;"><?= $__known ?> of <?= $__total ?> order(s) have their Byabsayee invoice number so far; the rest are looked up in the background.</p><?php endif; ?>
  </div>
</section>
<?php if (in_array($status, ['active', 'paused'], true) && ($rep = erp_last_reconcile())): ?>
<section class="panel">
  <div class="panel-head"><h2>Last reconciliation <span class="sub"><?= e(fmt_dt(erp_db_from_iso($rep['ran_at']))) ?></span></h2></div>
  <div class="panel-body">
    <?php if (!$rep['ok']): ?><div class="alert alert-error"><?= e($rep['error']) ?></div>
    <?php elseif (($rep['drift_total'] ?? 0) === 0): ?><p>Everything matches the book (<?= (int) $rep['stock']['checked'] ?> stock figures checked).</p>
    <?php else: ?>
      <?php if ($rep['stock']['corrected']): ?><p><?= (int) $rep['stock']['corrected'] ?> stock figure(s) differed and were corrected to the book's numbers (each shows as a “reconciliation adjustment” in the stock history).</p><?php endif; ?>
      <?php if ($rep['stock']['deferred'] && $rep['stock']['drift']): ?><p class="muted">Stock differences were noted but not changed because sales are still waiting to reach the book.</p><?php endif; ?>
      <?php foreach ($rep['entities'] as $ent => $r): foreach (['missing_at_book' => 'missing at the book', 'missing_here' => 'only at the book', 'behind' => 'newer at the book'] as $key => $lbl): if (!$r[$key]) continue; ?>
        <form method="post" style="margin-bottom:8px;"><?= csrf_field() ?><input type="hidden" name="entity" value="<?= e($ent) ?>">
          <?php foreach ($r[$key] as $u): ?><input type="hidden" name="uuids[]" value="<?= e($u) ?>"><?php endforeach; ?>
          <strong><?= count($r[$key]) ?> <?= e($ent) ?>(s) <?= e($lbl) ?></strong>
          <?php if ($key === 'missing_at_book'): ?><button class="btn btn-outline btn-sm" name="action" value="resend">Send to the book</button>
          <?php else: ?><button class="btn btn-outline btn-sm" name="action" value="pull">Bring in from the book</button><?php endif; ?></form>
      <?php endforeach; endforeach; ?>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php elseif ($tab === 'setup'): ?>
<section class="panel">
  <div class="panel-head"><h2>Setup review <span class="sub"><?= erp_setup_done() ? 'finished' : 'nothing syncs until you finish this' ?></span></h2></div>
  <div class="panel-body">
    <?php if ($status !== 'active'): ?><p class="muted">Link the store first (Overview).</p>
    <?php elseif (erp_setup_done()): ?><p>Setup is finished. Everything now syncs both ways.</p>
    <?php else:
      $items = db()->query("SELECT * FROM sync_match_items WHERE status = 'pending' ORDER BY FIELD(entity,'category','product','customer'), FIELD(kind,'sku_match','remote_only','local_only'), id LIMIT 300")->fetchAll();
      $pend = erp_match_pending_count();
      $names = function (array $it) { $f = json_decode((string) $it['remote_data'], true) ?: []; return $f; };
    ?>
      <p>Nothing is merged automatically. We compared this store with the book (matching products by SKU, categories by name, customers by phone or email). Decide what happens with each item below.</p>
      <form method="post" style="margin-bottom:14px;"><?= csrf_field() ?><input type="hidden" name="action" value="scan"><button class="btn btn-outline"><?= $pend ? 'Scan again' : 'Compare with the book' ?></button></form>
      <?php if ($pend): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
          <?php foreach ([['sku_match', 'link', 'Link all matches'], ['remote_only', 'create', 'Create all book-only items here'], ['local_only', 'push', 'Send all store-only items to the book'], ['local_only', 'ignore', 'Leave all store-only items out']] as [$k, $c, $lbl]): ?>
            <form method="post" onsubmit="return confirm('<?= e($lbl) ?>?');"><?= csrf_field() ?><input type="hidden" name="action" value="match_bulk"><input type="hidden" name="kind" value="<?= $k ?>"><input type="hidden" name="choice" value="<?= $c ?>"><button class="btn btn-outline btn-sm"><?= e($lbl) ?></button></form>
          <?php endforeach; ?>
        </div>
        <table class="admin-table"><thead><tr><th>Type</th><th>Here</th><th>At the book</th><th>Decision</th></tr></thead><tbody>
        <?php foreach ($items as $it): $rf = $names($it);
          $tbl = ['category' => 'categories', 'product' => 'products', 'customer' => 'sync_customers'][$it['entity']];
          $loc = null; if ($it['local_id']) { $s = db()->prepare("SELECT * FROM $tbl WHERE id = ?"); $s->execute([$it['local_id']]); $loc = $s->fetch(); } ?>
          <tr>
            <td><?= e(ucfirst($it['entity'])) ?><br><span class="pill <?= $it['kind'] === 'sku_match' ? 'pill-sage' : 'pill-ink' ?>"><?= ['sku_match' => 'Looks the same', 'remote_only' => 'Only at the book', 'local_only' => 'Only here'][$it['kind']] ?></span></td>
            <td><?= $loc ? e($loc['name']) . ($it['entity'] === 'product' ? '<div class="muted mono">' . e((string) $loc['sku']) . ' · ' . money((float) $loc['price']) . '</div>' : ($it['entity'] === 'customer' ? '<div class="muted">' . e((string) ($loc['phone'] ?: $loc['email'])) . '</div>' : '')) : '—' ?></td>
            <td><?= $rf ? e((string) ($rf['name'] ?? '')) . ($it['entity'] === 'product' ? '<div class="muted mono">' . e((string) ($rf['sku'] ?? '')) . ' · ' . e((string) ($rf['price'] ?? '')) . '</div>' : ($it['entity'] === 'customer' ? '<div class="muted">' . e((string) (($rf['phone'] ?? '') ?: ($rf['email'] ?? ''))) . '</div>' : '')) : '—' ?></td>
            <td>
              <form method="post" style="display:inline-flex;gap:6px;"><?= csrf_field() ?><input type="hidden" name="action" value="match"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                <?php if ($it['kind'] === 'sku_match'): ?><button class="btn btn-primary btn-sm" name="choice" value="link">Same — link</button>
                <?php elseif ($it['kind'] === 'remote_only'): ?><button class="btn btn-primary btn-sm" name="choice" value="create">Create here</button>
                <?php else: ?><button class="btn btn-primary btn-sm" name="choice" value="push">Send to book</button><?php endif; ?>
                <button class="btn btn-outline btn-sm" name="choice" value="ignore">Leave out</button></form></td>
          </tr>
        <?php endforeach; ?></tbody></table>
        <?php if ($pend > count($items)): ?><p class="muted"><?= $pend - count($items) ?> more not shown — handle these first, or use the bulk buttons.</p><?php endif; ?>
      <?php else: ?>
        <p>No open decisions. When you finish, everything you chose to send — plus your payment methods, coupons, tax and delivery charges — goes to the book, and from then on both sides stay in step.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="finish_setup"><button class="btn btn-primary">Finish setup and start syncing</button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php elseif ($tab === 'queue'): ?>
<section class="panel">
  <div class="panel-head" style="display:flex;justify-content:space-between;align-items:center;"><h2>Sync queue <span class="sub"><?= (int) $q['pending'] ?> waiting · <?= (int) $q['dead'] ?> stuck · <?= (int) $q['done'] ?> sent</span></h2>
    <span style="display:flex;gap:8px;"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="flush"><button class="btn btn-outline btn-sm">Send now</button></form>
    <?php if ($q['dead']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry_all"><button class="btn btn-outline btn-sm">Retry all stuck</button></form><?php endif; ?></span></div>
  <?php $rows = db()->query("SELECT * FROM sync_outbox WHERE status IN ('pending','sending','dead','conflict') ORDER BY FIELD(status,'dead','conflict','pending','sending'), id DESC LIMIT 100")->fetchAll(); ?>
  <table class="admin-table"><thead><tr><th>When</th><th>What</th><th>Status</th><th>Last problem</th><th></th></tr></thead><tbody>
    <?php if (!$rows): ?><tr class="empty-row"><td colspan="5">Nothing waiting — everything has been delivered.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr>
      <td><?= e(fmt_dt($r['created_at'], 'j M, g:i A')) ?></td><td><?= e($r['entity']) ?> · <?= e($r['op']) ?></td>
      <td><?= ['pending' => '<span class="pill pill-ink">Waiting (try ' . (int) $r['attempts'] . ')</span>', 'sending' => '<span class="pill pill-ink">Sending</span>', 'dead' => '<span class="pill pill-rust">Stuck</span>', 'conflict' => '<span class="pill pill-brass">Book queued a conflict</span>'][$r['status']] ?></td>
      <td class="muted" style="max-width:340px;"><?= e((string) $r['last_error']) ?></td>
      <td style="text-align:right;"><?php if ($r['status'] === 'dead'): ?>
        <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-outline btn-sm" name="action" value="retry">Retry</button>
        <button class="btn btn-outline btn-sm" name="action" value="discard" onclick="return confirm('Discard this event? The book will not receive this change.');">Discard</button></form><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody></table>
</section>

<?php elseif ($tab === 'conflicts'): ?>
<section class="panel">
  <div class="panel-head"><h2>Conflicts <span class="sub">changes that needed a person</span></h2></div>
  <div class="panel-body">
  <?php $cs = db()->query("SELECT * FROM sync_conflicts WHERE status = 'open' ORDER BY id DESC LIMIT 100")->fetchAll(); if (!$cs): ?><p class="muted">No open conflicts.</p><?php endif; ?>
  <?php foreach ($cs as $c): $kindLbl = ['field' => 'Changed on both sides', 'locked' => 'Locked order edited at the book', 'totals' => 'Totals do not add up', 'customer_match' => 'Possible duplicate customer', 'oversold' => 'Oversold', 'other' => 'Needs review'][$c['kind']]; ?>
    <div style="border:1px solid var(--line);border-radius:8px;padding:14px;margin-bottom:12px;">
      <strong><?= e($kindLbl) ?></strong> <span class="muted">· <?= e($c['entity']) ?> · <?= e(fmt_dt($c['created_at'], 'j M, g:i A')) ?></span>
      <p style="margin:6px 0;"><?= e((string) $c['note']) ?></p>
      <?php if ($c['kind'] === 'customer_match'): $l = json_decode((string) $c['local_data'], true) ?: []; $f = (json_decode((string) $c['remote_data'], true)['payload']['fields'] ?? []); ?>
        <p class="muted">Here: <?= e(($l['name'] ?? '') . ' · ' . ($l['phone'] ?? '') . ' · ' . ($l['email'] ?? '')) ?><br>Book: <?= e(($f['name'] ?? '') . ' · ' . ($f['phone'] ?? '') . ' · ' . ($f['email'] ?? '')) ?></p>
      <?php endif; ?>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;"><?= csrf_field() ?><input type="hidden" name="action" value="conflict"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <?php if ($c['kind'] === 'customer_match'): ?><button class="btn btn-primary btn-sm" name="choice" value="link">Same person — link</button><button class="btn btn-outline btn-sm" name="choice" value="separate">Different people — keep both</button>
        <?php else: ?><?php if ($c['entity_uuid'] && $c['kind'] !== 'oversold'): ?><button class="btn btn-outline btn-sm" name="choice" value="keep_local">Keep the store's version (send it again)</button><?php endif; ?><button class="btn btn-outline btn-sm" name="choice" value="dismiss">Dismiss</button><?php endif; ?>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
</section>

<?php elseif ($tab === 'log'): ?>
<section class="panel">
  <div class="panel-head"><h2>Activity <span class="sub">personal details are hidden; kept 90 days</span></h2></div>
  <?php $ok = $_GET['show'] ?? ''; $where = $ok === 'problems' ? 'WHERE ok = 0' : ''; $ls = db()->query("SELECT * FROM sync_log $where ORDER BY id DESC LIMIT 150")->fetchAll(); ?>
  <div class="seg" style="margin:12px 16px;display:inline-flex;"><a href="<?= $self ?>?tab=log" class="<?= $ok === '' ? 'active' : '' ?>">Everything</a><a href="<?= $self ?>?tab=log&show=problems" class="<?= $ok === 'problems' ? 'active' : '' ?>">Problems only</a></div>
  <table class="admin-table"><thead><tr><th>When</th><th></th><th>What happened</th></tr></thead><tbody>
    <?php if (!$ls): ?><tr class="empty-row"><td colspan="3">Nothing yet.</td></tr><?php endif; ?>
    <?php foreach ($ls as $l): ?><tr><td style="white-space:nowrap;"><?= e(fmt_dt($l['created_at'], 'j M, g:i:s A')) ?></td>
      <td><span class="pill <?= $l['ok'] ? 'pill-sage' : 'pill-rust' ?>"><?= e(['in' => 'From book', 'out' => 'To book', 'system' => 'System'][$l['direction']]) ?></span></td>
      <td><?= e($l['summary']) ?><?= $l['http_status'] ? ' <span class="muted">(HTTP ' . (int) $l['http_status'] . ')</span>' : '' ?></td></tr><?php endforeach; ?></tbody></table>
</section>

<?php else: /* import */ ?>
<?php $bid = (int) ($_GET['batch'] ?? 0); $b = $bid ? erp_import_get($bid) : null; ?>
<?php if (!in_array($status, ['active'], true) || !erp_setup_done()): ?>
  <section class="panel"><div class="panel-body"><p class="muted">Finish linking and the setup review first.</p></div></section>
<?php else: ?>
<section class="panel">
  <div class="panel-head"><h2>Bring over history <span class="sub">optional — going forward, everything already syncs</span></h2></div>
  <div class="panel-body">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="import_plan">
      <div class="field"><label>Direction</label>
        <label class="radio-option"><input type="radio" name="direction" value="store_to_book" checked><span class="radio-option-label">Send this store's past orders and customers to the book</span></label>
        <label class="radio-option"><input type="radio" name="direction" value="book_to_store"><span class="radio-option-label">Bring the book's past orders and customers into this store</span></label></div>
      <div class="field"><label>What</label>
        <label class="radio-option"><input type="checkbox" name="entities[]" value="customers" checked><span class="radio-option-label">Customers</span></label>
        <label class="radio-option"><input type="checkbox" name="entities[]" value="orders" checked><span class="radio-option-label">Orders (with their payments and returns)</span></label></div>
      <div class="field-row"><div class="field"><label for="df">From (date)</label><input type="date" id="df" name="date_from"></div><div class="field"><label for="dt">To (date)</label><input type="date" id="dt" name="date_to"></div></div>
      <div class="field"><label for="sm">Stock</label><select id="sm" name="stock_mode"><option value="none">Do not touch stock (recommended)</option><option value="opening_balance">Set stock here to the book's numbers afterwards (book → store only)</option></select></div>
      <p class="help">This first runs a <strong>dry run</strong>: it counts what would happen and writes nothing. You start the real import from the result, and you can pause it or roll back that one batch.</p>
      <button class="btn btn-primary">Run a dry run</button>
    </form>
  </div>
</section>
<?php if ($b): $plan = json_decode((string) $b['plan'], true) ?: []; $prog = json_decode((string) $b['progress'], true) ?: []; ?>
<section class="panel">
  <div class="panel-head"><h2>Batch #<?= (int) $b['id'] ?> <span class="sub"><?= e(['dry_run' => 'dry run — nothing written', 'ready' => 'ready', 'running' => 'running', 'paused' => 'paused', 'done' => 'finished', 'rolled_back' => 'rolled back', 'failed' => 'failed'][$b['status']]) ?> · <?= $b['direction'] === 'store_to_book' ? 'store → book' : 'book → store' ?></span></h2></div>
  <div class="panel-body">
    <table class="admin-table" style="margin-bottom:12px;"><tbody>
      <?php foreach ($plan['counts'] ?? [] as $k => $v): ?><tr><th style="width:280px;"><?= e(ucfirst(str_replace('_', ' ', $k))) ?></th><td><?= (int) $v ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php foreach ($plan['warnings'] ?? [] as $w): ?><div class="alert alert-info"><?= e($w) ?></div><?php endforeach; ?>
    <?php if ($prog): ?><p>Done <?= (int) ($prog['done'] ?? 0) ?> · skipped <?= (int) ($prog['skipped'] ?? 0) ?> · failed <?= (int) ($prog['failed'] ?? 0) ?></p>
      <?php foreach ($prog['errors'] ?? [] as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?><?php if (!empty($prog['fatal'])): ?><div class="alert alert-error"><?= e($prog['fatal']) ?></div><?php endif; ?><?php endif; ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
      <?php if ($b['status'] === 'dry_run'): ?><button class="btn btn-primary" name="action" value="import_start" onclick="return confirm('Start the real import now?');">Start the import</button>
      <?php elseif ($b['status'] === 'running'): ?><button class="btn btn-outline" name="action" value="import_step">Do a bit more now</button><button class="btn btn-outline" name="action" value="import_pause">Pause</button>
      <?php elseif ($b['status'] === 'paused'): ?><button class="btn btn-primary" name="action" value="import_resume">Resume</button><?php endif; ?>
      <?php if (in_array($b['status'], ['done', 'paused', 'failed'], true)): ?><button class="btn btn-danger" name="action" value="import_rollback" onclick="return confirm('Undo exactly this batch?');">Roll back this batch</button><?php endif; ?>
    </form>
    <?php if ($b['status'] === 'running'): ?><p class="muted">It keeps going in the background about every 30 seconds; you can leave this page.</p><?php endif; ?>
  </div>
</section>
<?php endif; endif; endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
