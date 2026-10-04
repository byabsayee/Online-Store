<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pageTitle = 'Reviews';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $st = db()->prepare('SELECT r.*, p.name AS product_name FROM product_reviews r JOIN products p ON p.id = r.product_id WHERE r.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if ($r) {
        $who = review_display_name($r['author_name']);
        $what = $r['rating'] . '-star review by ' . $who . ' on "' . admin_log_clip($r['product_name'], 60) . '"';
        $details = ['product_id' => (int) $r['product_id'], 'rating' => (int) $r['rating'], 'review_text' => admin_log_clip($r['body'], 300)];
        if ($action === 'hide' || $action === 'show') {
            $new = $action === 'hide' ? 'hidden' : 'published';
            db()->prepare('UPDATE product_reviews SET status = ? WHERE id = ?')->execute([$new, $id]);
            admin_log('review.' . $action, ($action === 'hide' ? 'Hid ' : 'Published ') . $what, 'review', $id, $details);
            flash_set('success', $action === 'hide' ? 'Review hidden from the storefront.' : 'Review is now visible.');
        } elseif ($action === 'delete') {
            db()->prepare('DELETE FROM product_reviews WHERE id = ?')->execute([$id]);
            admin_log('review.delete', 'Deleted ' . $what, 'review', $id, $details);
            flash_set('success', 'Review deleted.');
        }
    }
    redirect('/admin/reviews.php' . (!empty($_POST['back']) && str_starts_with((string) $_POST['back'], '?') ? $_POST['back'] : ''));
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['published', 'hidden'], true) ? $_GET['status'] : '';
$stars = (int) ($_GET['stars'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = ['1=1']; $params = [];
if ($status !== '') { $where[] = 'r.status = ?'; $params[] = $status; }
if ($stars >= 1 && $stars <= 5) { $where[] = 'r.rating = ?'; $params[] = $stars; }
if ($q !== '') {
    $like = '%' . like_escape($q) . '%';
    $where[] = "(p.name LIKE ? ESCAPE '|' OR r.author_name LIKE ? ESCAPE '|' OR r.body LIKE ? ESCAPE '|' OR r.title LIKE ? ESCAPE '|')";
    array_push($params, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);
$c = db()->prepare("SELECT COUNT(*) FROM product_reviews r JOIN products p ON p.id = r.product_id WHERE $whereSql");
$c->execute($params);
$total = (int) $c->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$st = db()->prepare("SELECT r.*, p.name AS product_name, p.slug AS product_slug FROM product_reviews r JOIN products p ON p.id = r.product_id WHERE $whereSql ORDER BY r.created_at DESC, r.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll();

$counts = db()->query("SELECT status, COUNT(*) c FROM product_reviews GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$avgAll = db()->query("SELECT AVG(rating) FROM product_reviews WHERE status = 'published'")->fetchColumn();

function reviews_url(array $over = []): string {
    $q = array_filter(array_merge($_GET, $over), fn ($v) => $v !== '' && $v !== null && $v !== 0);
    if (isset($q['page']) && (int) $q['page'] <= 1) unset($q['page']);
    return '/admin/reviews.php' . ($q ? '?' . http_build_query($q) : '');
}
$back = '?' . http_build_query(array_filter(['q' => $q, 'status' => $status, 'stars' => $stars ?: '', 'page' => $page > 1 ? $page : '']));

require __DIR__ . '/includes/header.php';
?>

<div class="stat-grid stat-grid-3">
  <div class="stat-card"><div class="label">Published</div><div class="value"><?= (int) ($counts['published'] ?? 0) ?></div></div>
  <div class="stat-card"><div class="label">Hidden</div><div class="value"><?= (int) ($counts['hidden'] ?? 0) ?></div></div>
  <div class="stat-card"><div class="label">Average rating</div><div class="value"><?= $avgAll !== null && $avgAll !== false ? e(number_format((float) $avgAll, 1)) . ' ' . ui_icon('star', 15) : '—' ?></div></div>
</div>

<div class="panel">
  <div class="panel-head"><h2>Product reviews <span class="sub"><?= number_format($total) ?> <?= ($q !== '' || $status !== '' || $stars) ? 'matching' : 'total' ?></span></h2></div>
  <p class="help" style="padding:0 22px;margin:0 0 4px;">Customers can review a product once they've received it. Reviews go live immediately — hide one to remove it from the storefront without deleting it.</p>

  <form class="toolbar" method="get">
    <div class="search">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search product, customer or text…" aria-label="Search reviews">
    </div>
    <select name="stars" aria-label="Rating">
      <option value="">Any rating</option>
      <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= $stars === $i ? 'selected' : '' ?>><?= $i ?> star<?= $i === 1 ? '' : 's' ?></option><?php endfor; ?>
    </select>
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <button class="btn btn-primary" type="submit">Filter</button>
    <span class="spacer"></span>
    <div class="seg">
      <a href="<?= e(reviews_url(['status' => '', 'page' => ''])) ?>" class="<?= $status === '' ? 'active' : '' ?>">All</a>
      <a href="<?= e(reviews_url(['status' => 'published', 'page' => ''])) ?>" class="<?= $status === 'published' ? 'active' : '' ?>">Published</a>
      <a href="<?= e(reviews_url(['status' => 'hidden', 'page' => ''])) ?>" class="<?= $status === 'hidden' ? 'active' : '' ?>">Hidden</a>
    </div>
  </form>

  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th>Product</th><th>Rating</th><th>Review</th><th>Customer</th><th>Date</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr class="empty-row"><td colspan="7">No reviews <?= ($q !== '' || $status !== '' || $stars) ? 'match those filters' : 'yet' ?>.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="/product.php?slug=<?= e($r['product_slug']) ?>#reviews" target="_blank" rel="noopener" style="font-weight:600;"><?= e($r['product_name']) ?></a></td>
            <td style="white-space:nowrap;"><?= stars_html((float) $r['rating']) ?></td>
            <td style="max-width:360px;"><?php if ($r['title']): ?><strong><?= e($r['title']) ?></strong><br><?php endif; ?><span class="small"><?= nl2br(e(admin_log_clip($r['body'], 400))) ?></span></td>
            <td><?= e($r['author_name']) ?></td>
            <td class="small"><?= e(fmt_dt($r['created_at'], 'd M Y')) ?></td>
            <td><span class="pill <?= $r['status'] === 'published' ? 'pill-sage' : 'pill-rust' ?>"><?= $r['status'] === 'published' ? 'Published' : 'Hidden' ?></span></td>
            <td class="actions" style="white-space:nowrap;">
              <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                <button class="btn btn-outline btn-sm" name="action" value="<?= $r['status'] === 'published' ? 'hide' : 'show' ?>"><?= $r['status'] === 'published' ? 'Hide' : 'Publish' ?></button>
                <button class="btn btn-danger btn-sm" name="action" value="delete" onclick="return confirm('Delete this review permanently?');">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="pager"><?php for ($i = 1; $i <= $totalPages; $i++): ?><?php if ($i === $page): ?><span class="current"><?= $i ?></span><?php else: ?><a href="<?= e(reviews_url(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?><?php endfor; ?></div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
