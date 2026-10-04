<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner(); // Staff accounts must not see or change store branding, mail/payment settings, or theming.

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['reset'])) {
        foreach (['theme_primary', 'theme_secondary', 'theme_dark'] as $k) {
            db()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$k]);
        }
        admin_log('theme.reset', 'Reset the theme colours to the defaults');
        flash_set('success', 'Theme colors reset to the defaults.');
        redirect('/admin/theme_settings.php');
    }

    $vals = [
        'theme_primary' => strtolower(trim($_POST['theme_primary'] ?? '')),
        'theme_secondary' => strtolower(trim($_POST['theme_secondary'] ?? '')),
        'theme_dark' => strtolower(trim($_POST['theme_dark'] ?? '')),
    ];
    $labels = ['theme_primary' => 'Primary color', 'theme_secondary' => 'Secondary color', 'theme_dark' => 'Header/footer color'];
    foreach ($vals as $k => $v) {
        if (!preg_match('/^#[0-9a-f]{6}$/', $v)) $errors[] = $labels[$k] . ' must be a valid hex code like #a97c34.';
    }
    $seasonalEnabled = !empty($_POST['seasonal_enabled']);
    $seasonalEffect = in_array($_POST['seasonal_effect'] ?? '', ['snow', 'leaves', 'rain'], true) ? $_POST['seasonal_effect'] : 'snow';

    if (!$errors) {
        $t0 = theme_settings();
        $s0 = ['on' => get_setting('seasonal_enabled', '0') === '1' ? 'yes' : 'no', 'effect' => (string) get_setting('seasonal_effect', 'snow')];
        foreach ($vals as $k => $v) set_setting($k, $v);
        set_setting('seasonal_enabled', $seasonalEnabled ? '1' : '0');
        set_setting('seasonal_effect', $seasonalEffect);
        $diff = admin_log_diff(['p' => $t0['primary'], 's' => $t0['secondary'], 'd' => $t0['dark'], 'on' => $s0['on'], 'fx' => $s0['effect']],
            ['p' => $vals['theme_primary'], 's' => $vals['theme_secondary'], 'd' => $vals['theme_dark'], 'on' => $seasonalEnabled ? 'yes' : 'no', 'fx' => $seasonalEffect],
            ['p' => 'Primary colour', 's' => 'Secondary colour', 'd' => 'Header/footer colour', 'on' => 'Seasonal effect on', 'fx' => 'Effect']);
        admin_log('theme.update', 'Edited the theme' . ($diff ? ': ' . admin_log_diff_summary($diff) : ' (saved, nothing changed)'), null, null, $diff ? ['changes' => $diff] : []);
        flash_set('success', 'Theme saved — it is live on the storefront now.');
        redirect('/admin/theme_settings.php');
    }
}

$theme = theme_settings();
$pageTitle = 'Theme & effects';
require __DIR__ . '/includes/header.php';

$presets = [
    'Brass & navy (default)' => [THEME_DEFAULTS['primary'], THEME_DEFAULTS['secondary'], THEME_DEFAULTS['dark']],
    'Ocean' => ['#1f6fb2', '#2f8f83', '#10263d'],
    'Forest' => ['#2e7d4f', '#a97c34', '#17301f'],
    'Terracotta' => ['#c0562f', '#6b7f5a', '#3a1f17'],
    'Plum' => ['#7b3f8c', '#d08a2c', '#2a1833'],
    'Graphite' => ['#3b4252', '#b58a3c', '#15171c'],
];
?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" id="themeForm">
  <?= csrf_field() ?>
  <div class="grid-2" style="align-items:start;">
    <div>
      <section class="panel">
        <div class="panel-head"><h2>Theme colors</h2></div>
        <div class="panel-body">
          <p class="help">Pick a starting palette or choose your own. Every button, link, badge, active menu item and the announcement bar/footer follow these colors — in both day and night mode. Text colors are chosen automatically so they stay readable.</p>
          <div class="preset-row">
            <?php foreach ($presets as $label => [$p, $s, $d]): ?>
              <button type="button" class="preset" data-p="<?= e($p) ?>" data-s="<?= e($s) ?>" data-d="<?= e($d) ?>"><span class="sw"><i style="background:<?= e($p) ?>"></i><i style="background:<?= e($s) ?>"></i><i style="background:<?= e($d) ?>"></i></span><?= e($label) ?></button>
            <?php endforeach; ?>
          </div>

          <?php foreach ([
              ['theme_primary', 'Primary color', $theme['primary'], 'Buttons, links, highlights, prices, active states.'],
              ['theme_secondary', 'Secondary color', $theme['secondary'], '"In stock" and success messages.'],
              ['theme_dark', 'Header & footer color', $theme['dark'], 'Announcement bar, footer and the admin sidebar.'],
          ] as [$name, $label, $val, $hint]): ?>
            <div class="field">
              <label for="<?= $name ?>"><?= e($label) ?></label>
              <div style="display:flex;gap:10px;align-items:center;">
                <input type="color" id="<?= $name ?>_picker" value="<?= e($val) ?>" style="width:44px;height:38px;padding:2px;border:1px solid var(--line);border-radius:6px;background:#fff;">
                <input type="text" id="<?= $name ?>" name="<?= $name ?>" value="<?= e($val) ?>" maxlength="7" required style="max-width:130px;font-family:var(--font-mono);">
              </div>
              <div class="hint"><?= e($hint) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="panel-foot">
          <button type="submit" class="btn btn-primary">Save theme</button>
          <button type="submit" name="reset" value="1" class="btn btn-outline" formnovalidate onclick="return confirm('Reset all three colors to the defaults?');">Reset to defaults</button>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Seasonal screen animation</h2></div>
        <div class="panel-body">
          <div class="field">
            <label class="switch"><input type="checkbox" name="seasonal_enabled" value="1" <?= $theme['seasonal_enabled'] ? 'checked' : '' ?>><span class="track"></span><span>Enable falling-particles effect<small>Visitors get a small button in the header to switch it off (or on) for themselves.</small></span></label>
          </div>
          <div class="field" style="max-width:260px;margin-bottom:0;">
            <label for="seasonal_effect">Effect</label>
            <select id="seasonal_effect" name="seasonal_effect">
              <?php foreach (['snow' => 'Snow', 'leaves' => 'Autumn leaves', 'rain' => 'Rain'] as $k => $l): ?>
                <option value="<?= $k ?>" <?= $theme['seasonal_effect'] === $k ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="panel-foot"><button type="submit" class="btn btn-primary">Save</button></div>
      </section>
    </div>

    <section class="panel" style="position:sticky;top:76px;">
      <div class="panel-head"><h2>Live preview</h2></div>
      <div class="panel-body">
        <div class="preview" id="preview">
          <div class="pv-top" id="pvTop">Your announcement bar message</div>
          <div class="pv-body">
            <div><div class="mono small" id="pvEyebrow" style="letter-spacing:.1em;text-transform:uppercase;">New arrival</div><div style="font-family:var(--font-display);font-size:1.3rem;font-weight:700;color:#20293b;">Titanium Pocket Pry Bar</div></div>
            <div class="pv-row"><span class="pv-btn" id="pvBtn">Add to cart</span><span class="pv-btn" id="pvBtnOutline" style="border:1px solid #20293b;color:#20293b;">Save for later</span></div>
            <div class="pv-row"><span class="pill" id="pvPill">In stock</span><span class="pill" id="pvPill2">Pending</span><a id="pvLink" style="font-weight:600;text-decoration:underline;">A link</a></div>
          </div>
        </div>
        <p class="help" style="margin:12px 0 0;">This preview updates as you pick colors. Nothing changes on the live store until you save.</p>
      </div>
    </section>
  </div>
</form>

<script>
(function () {
  var ids = ['theme_primary', 'theme_secondary', 'theme_dark'];
  var hex = /^#[0-9a-fA-F]{6}$/;
  function lum(h) { var c = [1, 3, 5].map(function (i) { var v = parseInt(h.substr(i, 2), 16) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; }
  function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  function onText(bg) { return ratio(bg, '#ffffff') >= ratio(bg, '#111111') ? '#ffffff' : '#111111'; }
  function shade(h, p) { var t = p >= 0 ? 255 : 0; return '#' + [1, 3, 5].map(function (i) { var c = parseInt(h.substr(i, 2), 16); c = Math.round(c + (t - c) * Math.abs(p) / 100); return ('0' + Math.max(0, Math.min(255, c)).toString(16)).slice(-2); }).join(''); }
  function readable(fg, bg) { for (var i = 0; i < 40 && ratio(fg, bg) < 4.5; i++) fg = shade(fg, -6); return fg; }
  function val(id) { var v = document.getElementById(id).value.trim(); return hex.test(v) ? v : null; }
  function paint() {
    var p = val('theme_primary'), s = val('theme_secondary'), d = val('theme_dark');
    var $ = function (i) { return document.getElementById(i); };
    if (d) { $('pvTop').style.background = d; $('pvTop').style.color = onText(d); }
    if (p) { $('pvBtn').style.background = p; $('pvBtn').style.color = onText(p); $('pvEyebrow').style.color = readable(p, '#f1efe6'); $('pvLink').style.color = readable(p, '#f1efe6'); $('pvPill2').style.background = p + '2e'; $('pvPill2').style.color = readable(p, '#f1efe6'); }
    if (s) { $('pvPill').style.background = s + '29'; $('pvPill').style.color = readable(s, '#f1efe6'); }
  }
  ids.forEach(function (id) {
    var t = document.getElementById(id), pk = document.getElementById(id + '_picker');
    pk.addEventListener('input', function () { t.value = pk.value; paint(); });
    t.addEventListener('input', function () { if (hex.test(t.value.trim())) { pk.value = t.value.trim(); } paint(); });
  });
  document.querySelectorAll('.preset').forEach(function (b) {
    b.addEventListener('click', function () {
      [['theme_primary', 'p'], ['theme_secondary', 's'], ['theme_dark', 'd']].forEach(function (x) {
        var v = b.getAttribute('data-' + x[1]); document.getElementById(x[0]).value = v; document.getElementById(x[0] + '_picker').value = v;
      });
      paint();
    });
  });
  paint();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
