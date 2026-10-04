<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_owner();
$errors = [];
$popular = ['Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins', 'Nunito', 'Raleway', 'Work Sans', 'DM Sans', 'Playfair Display', 'Merriweather', 'Lora', 'Fraunces', 'Cormorant Garamond', 'Noto Sans Bengali', 'Hind Siliguri', 'Anek Bangla', 'Tiro Bangla', 'Noto Serif Bengali', 'Cairo', 'Amiri', 'Noto Naskh Arabic'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $plan = []; $newFiles = [];
    foreach (font_roles() as $role => [$var, $label]) {
        $src = $_POST['src_' . $role] ?? 'default';
        if (!in_array($src, ['default', 'google', 'upload'], true)) $src = 'default';
        $old = font_choice($role);
        $p = ['src' => $src, 'name' => $old['name'], 'file' => $old['file']];
        if ($src === 'google') {
            $n = trim($_POST['gname_' . $role] ?? '');
            if (!font_name_ok($n)) { $errors[] = $label . ': enter the exact Google Fonts family name (letters, digits and spaces), e.g. Poppins.'; }
            else { $p['name'] = $n; }
        } elseif ($src === 'upload') {
            try {
                if ($up = site_upload_font('file_' . $role)) { $p['name'] = $up['name']; $p['file'] = $up['url']; $newFiles[] = $up['url']; }
                elseif ($old['file'] === '') $errors[] = $label . ': choose a font file to upload (.ttf, .otf, .woff or .woff2).';
            } catch (RuntimeException $e) { $errors[] = $label . ': ' . $e->getMessage(); }
        }
        $plan[$role] = [$p, $old];
    }
    if ($errors) { foreach ($newFiles as $f) font_delete_file($f); }
    else {
        foreach ($plan as $role => [$p, $old]) {
            set_setting('font_' . $role . '_src', $p['src']);
            set_setting('font_' . $role . '_name', $p['name']);
            set_setting('font_' . $role . '_file', $p['file']);
            if ($old['file'] !== '' && $old['file'] !== $p['file']) font_delete_file($old['file']);
        }
        admin_log('settings.fonts', 'Fonts changed');
        flash_set('success', 'Fonts saved.');
        redirect('/admin/fonts.php');
    }
}
$pageTitle = 'Fonts';
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-error">' . e($er) . '</div>';
?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<datalist id="gfonts"><?php foreach ($popular as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
<?php foreach (font_roles() as $role => [$var, $label, $what]): $c = font_choice($role); ?>
<section class="panel">
  <div class="panel-head"><h2><?= e($label) ?> <span class="sub"><?= e($what) ?></span></h2></div>
  <div class="panel-body">
    <label class="radio-option"><input type="radio" name="src_<?= $role ?>" value="default" <?= $c['src'] === 'default' ? 'checked' : '' ?>><span class="radio-option-label">Built-in default</span></label>
    <label class="radio-option"><input type="radio" name="src_<?= $role ?>" value="google" <?= $c['src'] === 'google' ? 'checked' : '' ?>><span class="radio-option-label">A Google Font <span class="hint" style="display:block;font-weight:400;">Type its name from <a class="link" href="https://fonts.google.com" target="_blank" rel="noopener">fonts.google.com</a> (loaded from Google when visitors open your store).</span></span></label>
    <div class="field" style="margin:0 0 8px 28px;"><input name="gname_<?= $role ?>" list="gfonts" maxlength="40" placeholder="e.g. Poppins" value="<?= e($c['src'] === 'google' ? $c['name'] : '') ?>"></div>
    <label class="radio-option"><input type="radio" name="src_<?= $role ?>" value="upload" <?= $c['src'] === 'upload' ? 'checked' : '' ?>><span class="radio-option-label">Upload my own font file <span class="hint" style="display:block;font-weight:400;">.ttf, .otf, .woff or .woff2, up to 4 MB. Make sure you have the right to use it on a website.<?= $c['file'] !== '' ? ' Current file: <strong>' . e($c['name'] ?: 'uploaded font') . '</strong>.' : '' ?></span></span></label>
    <div class="field" style="margin:0 0 4px 28px;"><input type="file" name="file_<?= $role ?>" accept=".ttf,.otf,.woff,.woff2"></div>
  </div>
</section>
<?php endforeach; ?>
<button class="btn btn-primary">Save fonts</button>
<p class="help" style="margin-top:10px;">Tip: pick a font that covers your language (for Bengali, try Hind Siliguri or Noto Sans Bengali). Reload the store to see the change.</p>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
