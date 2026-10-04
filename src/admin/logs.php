<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
// Owner only, and first thing in the file. The log is read-only: this page has no way to change or remove an entry.
require_owner();
$pageTitle = 'Activity log';

$perPage = 50;
$adminId = $_GET['admin'] ?? '';
$group = $_GET['group'] ?? '';
$q = trim((string) ($_GET['q'] ?? ''));
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = ['1=1']; $params = [];
if ($adminId === 'none') { $where[] = 'l.admin_id IS NULL'; }
elseif ($adminId !== '' && ctype_digit((string) $adminId)) { $where[] = 'l.admin_id = ?'; $params[] = (int) $adminId; }
if ($group !== '' && isset(ADMIN_LOG_GROUPS[$group])) { $where[] = 'l.action LIKE ?'; $params[] = $group . '.%'; }
if ($q !== '') {
    $like = '%' . like_escape($q) . '%';
    $where[] = "(l.summary LIKE ? ESCAPE '|' OR l.admin_name LIKE ? ESCAPE '|' OR l.details LIKE ? ESCAPE '|' OR l.ip LIKE ? ESCAPE '|')";
    array_push($params, $like, $like, $like, $like);
}
// Dates are typed in the store's timezone; the log is stored in UTC.
if ($from !== '') { $where[] = 'l.created_at >= ?'; $params[] = coupon_local_to_utc($from . 'T00:00'); }
if ($to !== '')   { $where[] = 'l.created_at < ?';  $params[] = coupon_local_to_utc(date('Y-m-d', strtotime($to . ' +1 day')) . 'T00:00'); }
$whereSql = implode(' AND ', $where);

// CSV export of exactly what the filters show (all pages).
if (($_GET['export'] ?? '') === 'csv') {
    $st = db()->prepare("SELECT l.* FROM admin_logs l WHERE $whereSql ORDER BY l.id DESC LIMIT 20000");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity-log-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    // A cell that starts with = + - @ would run as a formula when opened in Excel, so it gets a leading apostrophe.
    $safe = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    fputcsv($out, ['Time (' . date_default_timezone_get() . ')', 'Admin', 'Username', 'Action', 'Summary', 'Target', 'IP', 'Details']);
    foreach ($st->fetchAll() as $r) {
        fputcsv($out, [fmt_dt($r['created_at'], 'Y-m-d H:i:s'), $safe($r['admin_name']), $safe($r['admin_username']), admin_log_label($r['action'])[0], $safe($r['summary']),
            trim(($r['target_type'] ?? '') . ' ' . ($r['target_id'] ?? '')), $r['ip'], $safe($r['details'] ?? '')]);
    }
    fclose($out);
    exit;
}

$cnt = db()->prepare("SELECT COUNT(*) FROM admin_logs l WHERE $whereSql");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$st = db()->prepare("SELECT l.* FROM admin_logs l WHERE $whereSql ORDER BY l.id DESC LIMIT $perPage OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll();

$admins = db()->query('SELECT id, name, username FROM admins ORDER BY name')->fetchAll();
$allTime = (int) db()->query('SELECT COUNT(*) FROM admin_logs')->fetchColumn();
$todayStart = coupon_local_to_utc(date('Y-m-d') . 'T00:00');
$todayStmt = db()->prepare('SELECT COUNT(*) FROM admin_logs WHERE created_at >= ?');
$todayStmt->execute([$todayStart]);
$todayCount = (int) $todayStmt->fetchColumn();

function logs_url(array $over = []): string {
    $q = array_filter(array_merge($_GET, $over), fn ($v) => $v !== '' && $v !== null);
    unset($q['export']);
    if (isset($q['page']) && (int) $q['page'] <= 1) unset($q['page']);
    return '/admin/logs.php' . ($q ? '?' . http_build_query($q) : '');
}
$filtered = $adminId !== '' || $group !== '' || $q !== '' || $from !== '' || $to !== '';
$exportUrl = logs_url() . (str_contains(logs_url(), '?') ? '&' : '?') . 'export=csv';

require __DIR__ . '/includes/header.php';
?>

<div class="stat-grid stat-grid-3">
  <div class="stat-card"><div class="label">Entries recorded</div><div class="value"><?= number_format($allTime) ?></div></div>
  <div class="stat-card"><div class="label">Today</div><div class="value"><?= number_format($todayCount) ?></div></div>
  <div class="stat-card"><div class="label">Admin accounts</div><div class="value"><?= count($admins) ?></div></div>
</div>

<div class="panel">
  <div class="panel-head">
    <h2>Activity log <span class="sub"><?= $filtered ? number_format($total) . ' matching' : number_format($total) . ' entries' ?></span></h2>
    <a class="btn btn-outline btn-sm" href="<?= e($exportUrl) ?>">Export CSV</a>
  </div>
  <p class="help" style="padding:0 22px;margin:0 0 4px;">Every admin action is recorded here with the admin's name, the time (<?= e(date_default_timezone_get()) ?>) and the address it came from. Entries can't be edited or deleted.</p>

  <form class="toolbar log-filters" method="get">
    <div class="search">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search what happened, a name, an order number, an IP…" aria-label="Search the log">
    </div>
    <select name="admin" aria-label="Admin">
      <option value="">All admins</option>
      <?php foreach ($admins as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (string) $adminId === (string) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?> (@<?= e($a['username']) ?>)</option><?php endforeach; ?>
      <option value="none" <?= $adminId === 'none' ? 'selected' : '' ?>>Unknown / failed sign-ins</option>
    </select>
    <select name="group" aria-label="Type of action">
      <option value="">All actions</option>
      <?php foreach (ADMIN_LOG_GROUPS as $k => $label): ?><option value="<?= e($k) ?>" <?= $group === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <label class="log-date">From <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="log-date">To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn btn-primary" type="submit">Filter</button>
    <?php if ($filtered): ?><a class="btn btn-outline" href="/admin/logs.php">Clear</a><?php endif; ?>
  </form>

  <div class="table-wrap">
    <table class="admin-table log-table">
      <thead><tr><th>When</th><th>Admin</th><th>Action</th><th>What happened</th><th>From</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr class="empty-row"><td colspan="5"><?= $filtered ? 'Nothing matches those filters.' : 'No activity recorded yet.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
          [$label, $tone] = admin_log_label($r['action']);
          $isDelete = str_ends_with($r['action'], '.delete') || str_ends_with($r['action'], '.photo_delete');
          $url = $isDelete ? null : admin_log_target_url($r['target_type'], $r['target_id'] !== null ? (int) $r['target_id'] : null);
          $det = $r['details'] ? json_decode($r['details'], true) : null;
        ?>
          <tr>
            <td class="log-when"><?= e(fmt_dt($r['created_at'], 'd M Y')) ?><br><span class="muted small"><?= e(fmt_dt($r['created_at'], 'g:i:s A')) ?></span></td>
            <td class="log-admin"><strong><?= e($r['admin_name']) ?></strong><?php if ($r['admin_username']): ?><br><span class="muted small">@<?= e($r['admin_username']) ?></span><?php endif; ?></td>
            <td><span class="pill pill-<?= e($tone) ?>"><?= e($label) ?></span></td>
            <td class="log-what">
              <?php if ($url): ?><a href="<?= e($url) ?>" style="font-weight:600;"><?= e($r['summary']) ?></a><?php else: ?><?= e($r['summary']) ?><?php endif; ?>
              <?php if (is_array($det) && $det): ?>
                <details class="log-details"><summary>Details</summary>
                  <?php if (!empty($det['changes']) && is_array($det['changes'])): ?>
                    <table class="log-diff"><thead><tr><th>Field</th><th>Was</th><th>Now</th></tr></thead><tbody>
                      <?php foreach ($det['changes'] as $field => $pair): ?>
                        <tr><td><?= e($field) ?></td><td class="was"><?= e(is_array($pair) ? ($pair[0] ?? '') : '') ?></td><td class="now"><?= e(is_array($pair) ? ($pair[1] ?? '') : (string) $pair) ?></td></tr>
                      <?php endforeach; ?>
                    </tbody></table>
                  <?php endif; ?>
                  <?php foreach ($det as $k => $v): if ($k === 'changes') continue; ?>
                    <div class="log-kv"><span><?= e(ucfirst(str_replace('_', ' ', (string) $k))) ?></span> <?= e(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)) ?></div>
                  <?php endforeach; ?>
                </details>
              <?php endif; ?>
            </td>
            <td class="mono small muted"><?= e($r['ip'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="pager">
      <?php
      $win = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $totalPages], fn ($n) => $n >= 1 && $n <= $totalPages));
      sort($win); $prev = 0;
      foreach ($win as $i):
        if ($i - $prev > 1): ?><span>…</span><?php endif;
        if ($i === $page): ?><span class="current"><?= $i ?></span><?php else: ?><a href="<?= e(logs_url(['page' => $i])) ?>"><?= $i ?></a><?php endif;
        $prev = $i;
      endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
