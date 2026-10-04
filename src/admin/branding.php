<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner(); // Staff accounts must not see or change store branding, mail/payment settings, or theming.

/**
 * Admin → Branding & sharing.
 * One logo drives the whole site (header, mobile menu, footer, emails, invoices, admin, browser tab, phone
 * home-screen icon and link previews). The favicon and share banner are optional overrides; without them the
 * logo is used.
 */
$slots = [
    // key => [setting, kind, formats, maxSide]
    'logo' => ['brand_logo', 'logo', ['jpg', 'png', 'gif', 'webp', 'svg'], 1000],
    'logo_dark' => ['brand_logo_dark', 'logo-dark', ['jpg', 'png', 'gif', 'webp', 'svg'], 1000],
    'favicon' => ['brand_favicon', 'favicon', ['png', 'jpg', 'gif', 'webp', 'svg'], 1024],
    'banner' => ['brand_banner', 'banner', ['jpg', 'png'], 1600],
];

$errors = [];
$fieldErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $desc = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['site_description'] ?? '')));
    if (mb_strlen($desc) > 300) $errors[] = 'The site description is too long (max 300 characters).';

    // 1) store any new uploads (nothing is saved unless every upload is fine)
    $stored = [];   // key => new url
    $fieldErrors = []; // key => message, so each asset slot can show its own error inline
    foreach ($slots as $key => [$setting, $kind, $formats, $maxSide]) {
        try {
            if ($url = brand_upload($key, $kind, $formats, $maxSide)) $stored[$key] = $url;
        } catch (RuntimeException $e) {
            $fieldErrors[$key] = $e->getMessage();
            $errors[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $e->getMessage();
        }
    }
    if ($errors) {
        foreach ($stored as $url) brand_delete_file($url);
    } else {
        $notes = [];
        $changes = [];
        foreach ($slots as $key => [$setting]) {
            $old = (string) get_setting($setting, '');
            if (isset($stored[$key])) {
                set_setting($setting, $stored[$key]);
                if ($old !== '') brand_delete_file($old);
                $changes[ucfirst(str_replace('_', ' ', $key))] = [$old !== '' ? '(previous file)' : '—', 'new file uploaded'];
            } elseif (!empty($_POST['remove_' . $key]) && $old !== '') {
                set_setting($setting, '');
                brand_delete_file($old);
                $changes[ucfirst(str_replace('_', ' ', $key))] = ['(file)', 'removed'];
            }
        }
        $oldShow = get_setting('brand_logo_show_name', '0') === '1';
        $oldDesc = (string) get_setting('site_description', '');
        set_setting('brand_logo_show_name', !empty($_POST['show_name']) ? '1' : '0');
        set_setting('site_description', $desc);
        $changes += admin_log_diff(['show' => $oldShow ? 'yes' : 'no', 'desc' => $oldDesc], ['show' => !empty($_POST['show_name']) ? 'yes' : 'no', 'desc' => $desc], ['show' => 'Show store name next to logo', 'desc' => 'Site description']);
        admin_log('branding.update', 'Edited branding' . ($changes ? ': ' . admin_log_diff_summary($changes) : ' (saved, nothing changed)'), null, null, $changes ? ['changes' => $changes] : []);
        brand_rebuild_derived();

        if (isset($stored['banner']) && ($d = image_dims($stored['banner'])) && ($d[0] < 600 || $d[1] < 315)) {
            $notes[] = 'The banner is small (' . $d[0] . '×' . $d[1] . ' px) — 1200×630 looks sharpest when shared.';
        }
        if (brand_logo() && is_svg_url(brand_logo()) && !brand_banner()) {
            $notes[] = 'SVG logos can\'t be used in link previews (WhatsApp/Facebook need JPG or PNG) — upload a banner or a PNG logo for shared links.';
        }
        flash_set('success', 'Branding saved — it\'s live across the whole site.' . ($notes ? ' ' . implode(' ', $notes) : ''));
        redirect('/admin/branding.php');
    }
}

$s = store_info();
$cur = [
    'logo' => brand_logo(), 'logo_dark' => brand_logo_dark(), 'favicon' => brand_favicon(), 'banner' => brand_banner(),
];
$showName = get_setting('brand_logo_show_name', '0') === '1';
$descValue = $_SERVER['REQUEST_METHOD'] === 'POST' ? trim((string) ($_POST['site_description'] ?? '')) : (string) get_setting('site_description', '');
$share = share_image();
$sample = db()->query("SELECT * FROM products WHERE is_active = 1 ORDER BY (image_main IS NULL), is_featured DESC, id LIMIT 1")->fetch() ?: null;
$pub = public_url_status();
$hostLabel = strtoupper((string) parse_url($pub['url'], PHP_URL_HOST));
$sampleImg = $sample && $sample['image_main'] ? $sample['image_main'] : null;

$pageTitle = 'Branding & sharing';
require __DIR__ . '/includes/header.php';
?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" id="brandForm">
  <?= csrf_field() ?>

  <div class="panel">
    <div class="panel-head"><h2>Logo <span class="sub">used everywhere by default</span></h2></div>
    <div class="panel-body">
      <p class="help">Upload your logo once. It replaces the round mark in the header, mobile menu, footer and admin, and is also used in emails and on invoices. The browser-tab icon, phone home-screen icon and the picture shown when a link is shared are made from it automatically — unless you upload a separate favicon or banner below.</p>
      <div class="asset-grid">

        <div class="asset-slot<?= isset($fieldErrors['logo']) ? ' has-error' : '' ?>" data-slot="logo">
          <div class="asset-head"><strong>Main logo</strong><span class="muted">JPG · PNG · WebP · GIF · SVG</span></div>
          <div class="asset-preview checker"><?php if ($cur['logo']): ?><img src="<?= e($cur['logo']) ?>" alt="Current logo"><?php else: ?><span class="empty">No logo yet — the round “<?= e(brand_initial()) ?>” mark is used</span><?php endif; ?></div>
          <input type="file" name="logo" id="f_logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
          <?php if (isset($fieldErrors['logo'])): ?><div class="alert alert-error slot-error"><?= e($fieldErrors['logo']) ?></div><?php endif; ?>
          <?php if ($cur['logo']): ?><label class="switch remove"><input type="checkbox" name="remove_logo" value="1"><span class="track"></span><span>Remove the logo</span></label><?php endif; ?>
          <div class="hint">Best: a transparent PNG or SVG, around 400 px wide. Shown about 40 px tall in the header. Max <?= (int) (BRAND_MAX_UPLOAD_BYTES / 1048576) ?> MB.</div>
        </div>

        <div class="asset-slot<?= isset($fieldErrors['logo_dark']) ? ' has-error' : '' ?>" data-slot="logo_dark">
          <div class="asset-head"><strong>Logo for dark backgrounds</strong><span class="muted">optional</span></div>
          <div class="asset-preview dark"><?php if ($cur['logo_dark']): ?><img src="<?= e($cur['logo_dark']) ?>" alt="Current dark-background logo"><?php elseif ($cur['logo']): ?><img src="<?= e($cur['logo']) ?>" alt="" class="on-plate-demo"><?php else: ?><span class="empty">Upload the main logo first</span><?php endif; ?></div>
          <input type="file" name="logo_dark" id="f_logo_dark" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" <?= $cur['logo'] ? '' : 'disabled' ?>>
          <?php if (isset($fieldErrors['logo_dark'])): ?><div class="alert alert-error slot-error"><?= e($fieldErrors['logo_dark']) ?></div><?php endif; ?>
          <?php if ($cur['logo_dark']): ?><label class="switch remove"><input type="checkbox" name="remove_logo_dark" value="1"><span class="track"></span><span>Remove this version</span></label><?php endif; ?>
          <div class="hint">A light/white version, used in the footer, mobile menu, admin sidebar and dark mode. If you skip it, the main logo is placed on a small white plate there.</div>
        </div>
      </div>

      <label class="switch" style="margin-top:6px;"><input type="checkbox" name="show_name" value="1" <?= $showName ? 'checked' : '' ?>><span class="track"></span><span>Also show the store name next to the logo<small>Leave off if your logo already contains the name.</small></span></label>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Favicon &amp; share banner <span class="sub">optional — the logo is used if these are empty</span></h2></div>
    <div class="panel-body">
      <div class="asset-grid">

        <div class="asset-slot<?= isset($fieldErrors['favicon']) ? ' has-error' : '' ?>" data-slot="favicon">
          <div class="asset-head"><strong>Favicon / app icon</strong><span class="muted">PNG · SVG · JPG</span></div>
          <div class="asset-preview checker small"><?php if ($cur['favicon']): ?><img src="<?= e($cur['favicon']) ?>" alt="Current favicon" class="fav"><?php elseif ($cur['logo']): ?><img src="<?= e(brand_generated('i180') ?: $cur['logo']) ?>" alt="" class="fav"><span class="muted small-note">from your logo</span><?php else: ?><img src="/assets/img/favicon.svg" alt="" class="fav"><span class="muted small-note">default</span><?php endif; ?></div>
          <input type="file" name="favicon" id="f_favicon" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
          <?php if (isset($fieldErrors['favicon'])): ?><div class="alert alert-error slot-error"><?= e($fieldErrors['favicon']) ?></div><?php endif; ?>
          <?php if ($cur['favicon']): ?><label class="switch remove"><input type="checkbox" name="remove_favicon" value="1"><span class="track"></span><span>Remove (use the logo)</span></label><?php endif; ?>
          <div class="hint">The small icon in the browser tab and on a phone's home screen. Use a square PNG, 512×512 px. A PNG is needed for the phone home-screen icon; an SVG only covers the tab. Max <?= (int) (BRAND_MAX_UPLOAD_BYTES / 1048576) ?> MB.</div>
        </div>

        <div class="asset-slot<?= isset($fieldErrors['banner']) ? ' has-error' : '' ?>" data-slot="banner">
          <div class="asset-head"><strong>Share banner</strong><span class="muted">JPG · PNG · 1200×630</span></div>
          <div class="asset-preview wide"><img src="<?= e($cur['banner'] ?: $share['url']) ?>" alt="Current share image" id="bannerCur"></div>
          <input type="file" name="banner" id="f_banner" accept="image/png,image/jpeg">
          <?php if (isset($fieldErrors['banner'])): ?><div class="alert alert-error slot-error"><?= e($fieldErrors['banner']) ?></div><?php endif; ?>
          <?php if ($cur['banner']): ?><label class="switch remove"><input type="checkbox" name="remove_banner" value="1"><span class="track"></span><span>Remove (use the logo)</span></label><?php endif; ?>
          <div class="hint">The picture shown when any page other than a product is shared (WhatsApp, Facebook, Messenger, X…). Product links always show that product's own photo. Straight-from-camera photos are fine — it's resized automatically. Max <?= (int) (BRAND_MAX_UPLOAD_BYTES / 1048576) ?> MB.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Site description <span class="sub">search results &amp; shared links</span></h2></div>
    <div class="panel-body">
      <div class="field">
        <label for="site_description">Description <span class="counter" data-counter-for="site_description" data-max="300"></span></label>
        <textarea id="site_description" name="site_description" rows="3" maxlength="300" placeholder="<?= e(DEFAULT_SITE_DESCRIPTION) ?>"><?= e($descValue) ?></textarea>
        <div class="hint">One or two sentences (about 150 characters is ideal). Shown under your name in Google, in shared-link previews, and in the footer. Leave blank for the default.</div>
      </div>

      <div class="share-previews">
        <div>
          <div class="pv-label">When a page is shared</div>
          <div class="share-card" id="cardSite">
            <div class="sc-img"><img src="<?= e($share['url']) ?>" alt="" id="scSiteImg"></div>
            <div class="sc-body"><div class="sc-host"><?= e($hostLabel) ?></div><div class="sc-title"><?= e($s['name']) ?></div><div class="sc-desc" id="scSiteDesc"><?= e(meta_trim($s['description'], 130)) ?></div></div>
          </div>
        </div>
        <div>
          <div class="pv-label">When a product is shared</div>
          <div class="share-card">
            <div class="sc-img"><img src="<?= e($sampleImg ?: $share['url']) ?>" alt=""></div>
            <div class="sc-body"><div class="sc-host"><?= e($hostLabel) ?></div><div class="sc-title"><?= e($sample['name'] ?? 'Product name') ?></div><div class="sc-desc"><?= e($sample ? meta_trim(product_share_description($sample), 130) : 'The product\'s own short description.') ?></div></div>
          </div>
        </div>
      </div>
      <p class="help" style="margin-top:14px;">Already-shared links can keep showing the old preview for a while — WhatsApp and Facebook cache them. Paste the link into <a href="https://developers.facebook.com/tools/debug/" target="_blank" rel="noopener" style="text-decoration:underline;">Facebook's Sharing Debugger</a> and press “Scrape Again” to refresh.</p>
      <?php if ($pub['local'] || !$pub['https']): ?>
        <div class="alert alert-warn" style="margin:12px 0 0;">Links are currently being built as <code><?= e($pub['url']) ?></code> — this is detected automatically from the domain you're using to access the site right now, so it'll switch on its own once you're on a real public domain over HTTPS. If you're behind a reverse proxy that isn't forwarding the real host/protocol, set <code>SITE_URL</code> in your <code>.env</code> as a fallback and redeploy.</div>
      <?php else: ?>
        <p class="help" style="margin:12px 0 0;">Public address used in link previews and the sitemap: <code><?= e($pub['url']) ?></code></p>
      <?php endif; ?>
    </div>
  </div>

  <div class="form-actions"><button type="submit" class="btn btn-primary">Save branding</button></div>
</form>

<script>
(function () {
  // If the last save hit a problem, bring it into view instead of leaving the admin
  // to wonder why nothing changed — errors render inline in their slot (and in the
  // summary at the top), but on a long page it's easy to land below the fold.
  var firstError = document.querySelector('.alert-error');
  if (firstError) firstError.scrollIntoView({ block: 'center' });

  // Show a chosen file straight away in its slot (nothing is saved until you press Save).
  document.querySelectorAll('.asset-slot input[type=file]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0]; if (!f) return;
      var box = inp.closest('.asset-slot').querySelector('.asset-preview');
      var img = box.querySelector('img');
      if (!img) { box.innerHTML = ''; img = document.createElement('img'); box.appendChild(img); }
      img.classList.remove('on-plate-demo');
      var note = box.querySelector('.small-note, .empty'); if (note) note.remove();
      var oldSrc = img.src;
      try {
        img.src = URL.createObjectURL(f);
      } catch (err) {
        return; // very old/locked-down browser — the form still works, just skip the instant preview
      }
      if (oldSrc && oldSrc.indexOf('blob:') === 0) URL.revokeObjectURL(oldSrc);
      if (inp.name === 'banner') { var s = document.getElementById('scSiteImg'); if (s) s.src = img.src; }
    });
  });
  // Live description in the "page shared" card.
  var d = document.getElementById('site_description'), out = document.getElementById('scSiteDesc');
  var fallback = <?= json_encode(DEFAULT_SITE_DESCRIPTION, JSON_UNESCAPED_UNICODE) ?>;
  if (d && out) d.addEventListener('input', function () { var t = d.value.trim() || fallback; out.textContent = t.length > 130 ? t.slice(0, 129) + '…' : t; });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>