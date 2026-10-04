<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';
// Owners only, first thing in the file (it handles POSTs). Staff cannot edit their own details — that is the point of this page.
require_owner();
$me = current_admin();

$id = (int) ($_GET['id'] ?? 0);
$editing = null;
if ($id) {
    $editing = staff_get($id);
    if (!$editing) { flash_set('error', 'Staff member not found.'); redirect('/admin/staff.php'); }
}
$isSelf = $editing && (int) $editing['id'] === (int) $me['id'];
$errors = []; $pwErrors = [];
$f = $editing ?: ['username' => '', 'name' => '', 'role' => 'staff', 'phone' => '', 'email' => '', 'address' => '', 'blood_group' => '', 'gender' => '', 'id_type' => 'nid', 'id_number' => '', 'date_of_birth' => '', 'facebook_url' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'reset_password' && $editing) {
        $pw = (string) ($_POST['new_password'] ?? '');
        if ($isSelf) $pwErrors[] = 'Use My account to change your own password.';
        elseif ($e = staff_password_error($pw)) $pwErrors[] = $e;
        else {
            // Temporary password: they must replace it at their next sign-in, and until they do nothing else in the portal opens.
            db()->prepare('UPDATE admins SET password_hash = ?, must_change_password = 1 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $editing['id']]);
            admin_log('staff.password_reset', 'Set a new temporary password for ' . $editing['name'] . ' (@' . $editing['username'] . ')', 'staff', (int) $editing['id']);
            flash_set('success', 'Temporary password set. ' . $editing['name'] . ' will be asked to choose their own at next sign-in.');
            redirect('/admin/staff_form.php?id=' . $editing['id']);
        }
    } else {
        [$errors, $c] = staff_validate($_POST, $editing);
        $pw = (string) ($_POST['password'] ?? '');
        if (!$editing && ($e = staff_password_error($pw))) $errors[] = $e;
        if ($isSelf) $c['role'] = $editing['role'];   // you can't change your own role
        if ($editing && !$isSelf && $editing['role'] === 'owner' && $c['role'] !== 'owner' && $editing['status'] === 'active' && staff_active_owner_count((int) $editing['id']) < 1) {
            $errors[] = 'There must always be at least one active owner.';
        }
        [$doc, $docErr] = staff_check_document($_FILES['document'] ?? null);
        if ($docErr) $errors[] = $docErr;
        // Every staff member needs a profile picture: one must be uploaded now unless they already have one.
        [$photo, $photoErr] = staff_check_photo($_FILES['photo'] ?? null);
        if ($photoErr) $errors[] = $photoErr;
        elseif (!$photo && (!$editing || empty($editing['photo_v']))) $errors[] = 'Please add a profile picture.';
        $f = array_merge($f, $c);

        if (!$errors) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                if ($editing) {
                    $pdo->prepare('UPDATE admins SET username=?, name=?, role=?, phone=?, email=?, address=?, blood_group=?, gender=?, id_type=?, id_number=?, date_of_birth=?, facebook_url=? WHERE id=?')
                        ->execute([$c['username'], $c['name'], $c['role'], $c['phone'], $c['email'], $c['address'], $c['blood_group'], $c['gender'], $c['id_type'], $c['id_number'], $c['date_of_birth'], $c['facebook_url'], $editing['id']]);
                    $targetId = (int) $editing['id'];
                } else {
                    $pdo->prepare('INSERT INTO admins (username, name, password_hash, role, phone, email, address, blood_group, gender, id_type, id_number, date_of_birth, facebook_url, must_change_password) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)')
                        ->execute([$c['username'], $c['name'], password_hash($pw, PASSWORD_DEFAULT), $c['role'], $c['phone'], $c['email'], $c['address'], $c['blood_group'], $c['gender'], $c['id_type'], $c['id_number'], $c['date_of_birth'], $c['facebook_url']]);
                    $targetId = (int) $pdo->lastInsertId();
                }
                $photoChange = null;
                if ($photo) { staff_save_photo($targetId, $photo); $photoChange = $editing && !empty($editing['photo_v']) ? 'replaced' : 'added'; }
                $docChange = null;
                if ($doc) { staff_save_document($targetId, $doc, (int) $me['id']); $docChange = $editing && staff_document_meta($targetId) ? 'replaced' : 'added'; }
                elseif ($editing && !empty($_POST['remove_document']) && staff_document_meta($targetId)) { staff_delete_document($targetId); $docChange = 'removed'; }
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[staff_form] ' . $ex->getMessage());
                $errors[] = $ex instanceof PDOException && $ex->getCode() === '23000' ? 'That username, email or ID number is already in use.' : 'Could not save — a database error occurred.';
            }
            if (!$errors) {
                if ($editing) {
                    $old = $editing; $new = $c;
                    $old['idn'] = staff_mask_id($editing['id_number']); $new['idn'] = staff_mask_id($c['id_number']);            // the full ID number never goes in the log
                    $old['id_type'] = STAFF_ID_TYPES[$editing['id_type']] ?? $editing['id_type']; $new['id_type'] = STAFF_ID_TYPES[$c['id_type']];
                    $diff = admin_log_diff($old, $new, ['username' => 'Username', 'name' => 'Name', 'role' => 'Role', 'phone' => 'Number', 'email' => 'Email', 'address' => 'Address', 'blood_group' => 'Blood group', 'gender' => 'Gender',
                        'date_of_birth' => 'Date of birth', 'facebook_url' => 'Facebook', 'id_type' => 'ID type', 'idn' => 'ID number']);
                    if ($photoChange) $diff['Profile picture'] = ['—', $photoChange];
                    if ($docChange) $diff['Document'] = ['—', $docChange];
                    admin_log('staff.update', 'Edited the details of ' . $c['name'] . ' (@' . $c['username'] . ')' . ($diff ? ': ' . admin_log_diff_summary($diff) : ' (saved, nothing changed)'), 'staff', $targetId, $diff ? ['changes' => $diff] : []);
                    flash_set('success', 'Details saved.');
                } else {
                    admin_log('staff.create', 'Added ' . $c['name'] . ' (@' . $c['username'] . ') as ' . ($c['role'] === 'owner' ? 'an owner' : 'staff'), 'staff', $targetId, ['role' => $c['role'], 'document' => $doc ? 'attached' : 'none', 'profile_picture' => 'added']);
                    flash_set('success', $c['name'] . ' was added. They will choose their own password at first sign-in.');
                }
                redirect('/admin/staff.php');
            }
        }
    }
}

$docMeta = $editing ? staff_document_meta((int) $editing['id']) : null;
$pageTitle = $editing ? 'Edit staff member' : 'Add staff member';
require __DIR__ . '/includes/header.php';
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="panel" style="max-width:860px;" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <div class="panel-head"><h2><?= $editing ? e($editing['name']) : 'New staff member' ?></h2><?php if ($editing): ?><span class="sub">since <?= e(fmt_dt($editing['created_at'], 'd M Y')) ?></span><?php endif; ?></div>
  <div class="panel-body">
    <h3 class="form-section">Sign-in account</h3>
    <div class="field-row">
      <div class="field"><label for="username">Username</label><input id="username" name="username" value="<?= e($f['username']) ?>" required maxlength="40" autocapitalize="none" spellcheck="false"><div class="hint">What they type to sign in. Lower-case letters, numbers, dot, dash, underscore.</div></div>
      <div class="field"><label for="role">Role</label>
        <select id="role" name="role" <?= $isSelf ? 'disabled' : '' ?>>
          <option value="staff" <?= $f['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
          <option value="owner" <?= $f['role'] === 'owner' ? 'selected' : '' ?>>Owner</option>
        </select>
        <div class="hint"><?= $isSelf ? 'You can\'t change your own role.' : 'Owners can manage staff and see the activity log. Staff can\'t.' ?></div>
      </div>
    </div>
    <?php if (!$editing): ?>
      <div class="field"><label for="password">Temporary password</label>
        <div style="display:flex;gap:8px;"><input type="text" id="password" name="password" required minlength="10" autocomplete="off" spellcheck="false" style="font-family:var(--font-mono);"><button type="button" class="btn btn-outline" id="genPw">Generate</button></div>
        <div class="hint">At least 10 characters. Tell them this password; they'll be made to choose their own the first time they sign in.</div>
      </div>
    <?php endif; ?>

    <h3 class="form-section">Personal details</h3>
    <div class="photo-field">
      <?php if ($editing && !empty($editing['photo_v'])): ?><img id="photoPreview" class="avatar" src="/admin/staff_photo.php?id=<?= (int) $editing['id'] ?>&amp;v=<?= (int) $editing['photo_v'] ?>" alt="" width="96" height="96" style="width:96px;height:96px;">
      <?php else: ?><span id="photoPreview" class="avatar avatar-initials" style="width:96px;height:96px;font-size:34px;"><?= e(staff_initials($f['name'] !== '' ? $f['name'] : '+')) ?></span><?php endif; ?>
      <div class="photo-meta">
        <label for="photo">Profile picture</label>
        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png" <?= ($editing && !empty($editing['photo_v'])) ? '' : 'required' ?>>
        <div class="hint">Required. A clear face photo, JPG or PNG. It is cropped to a square and resized automatically.<?= ($editing && !empty($editing['photo_v'])) ? ' Choose a file only to replace the current one.' : '' ?></div>
      </div>
    </div>
    <div class="field-row">
      <div class="field"><label for="name">Full name</label><input id="name" name="name" value="<?= e($f['name']) ?>" required maxlength="120"></div>
      <div class="field"><label for="phone">Number</label><input id="phone" name="phone" type="tel" value="<?= e((string) $f['phone']) ?>" required maxlength="40" placeholder="+880 1XXX-XXXXXX"></div>
    </div>
    <div class="field-row">
      <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= e((string) $f['email']) ?>" required maxlength="160"></div>
      <div class="field"><label for="date_of_birth">Date of birth</label><input id="date_of_birth" name="date_of_birth" type="date" value="<?= e((string) $f['date_of_birth']) ?>" required min="1920-01-01" max="<?= e(date('Y-m-d')) ?>"></div>
    </div>
    <div class="field-row">
      <div class="field"><label for="gender">Gender</label>
        <select id="gender" name="gender" required><option value="">Choose…</option><?php foreach (STAFF_GENDERS as $g): ?><option <?= $f['gender'] === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="blood_group">Blood group</label>
        <select id="blood_group" name="blood_group" required><option value="">Choose…</option><?php foreach (STAFF_BLOOD_GROUPS as $b): ?><option <?= $f['blood_group'] === $b ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field-row">
      <div class="field"><label for="id_type">ID type</label>
        <select id="id_type" name="id_type" required><?php foreach (STAFF_ID_TYPES as $k => $label): ?><option value="<?= e($k) ?>" <?= $f['id_type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="id_number" id="idNumberLabel">NID number</label><input id="id_number" name="id_number" value="<?= e((string) $f['id_number']) ?>" required inputmode="numeric" maxlength="24" style="font-family:var(--font-mono);"><div class="hint" id="idHint">10, 13 or 17 digits. Shown masked in lists and never written to the activity log.</div></div>
    </div>
    <div class="field"><label for="facebook_url">Facebook profile link</label><input id="facebook_url" name="facebook_url" type="url" value="<?= e((string) $f['facebook_url']) ?>" required maxlength="255" placeholder="https://www.facebook.com/their.name"><div class="hint">Required. Open their Facebook profile and paste the address from the browser.</div></div>
    <div class="field"><label for="address">Address</label><textarea id="address" name="address" rows="3" required maxlength="500"><?= e((string) $f['address']) ?></textarea></div>

    <h3 class="form-section">Document <span class="muted" style="font-weight:400;">(optional)</span></h3>
    <?php if ($docMeta): ?>
      <div class="doc-card">
        <span class="doc-ico"><?= ui_icon('paperclip', 18) ?></span>
        <div class="doc-meta"><strong><?= e($docMeta['original_name']) ?></strong><small><?= e(STAFF_DOC_TYPES[$docMeta['mime']] ?? $docMeta['mime']) ?> · <?= e(staff_human_size((int) $docMeta['size'])) ?> · added <?= e(fmt_dt($docMeta['uploaded_at'], 'd M Y')) ?></small></div>
        <a class="btn btn-outline btn-sm" href="/admin/staff_document.php?id=<?= (int) $editing['id'] ?>" target="_blank" rel="noopener">View</a>
        <a class="btn btn-outline btn-sm" href="/admin/staff_document.php?id=<?= (int) $editing['id'] ?>&amp;download=1">Download</a>
      </div>
      <label class="check" style="margin:10px 0 4px;"><input type="checkbox" name="remove_document" value="1"> Remove this document</label>
    <?php endif; ?>
    <div class="field" style="margin-bottom:0;"><label for="document"><?= $docMeta ? 'Replace with a new file' : 'Attach a file' ?></label><input type="file" id="document" name="document" accept="application/pdf,image/jpeg,image/png,image/webp">
      <div class="hint">e.g. a scan of their NID. PDF, JPG, PNG or WebP, up to 5 MB. Stored privately — only owners and this person can open it.</div></div>
  </div>
  <div class="panel-foot" style="display:flex;gap:10px;"><button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Add staff member' ?></button><a class="btn btn-outline" href="/admin/staff.php">Cancel</a></div>
</form>

<?php if ($editing && !$isSelf): ?>
  <?php foreach ($pwErrors as $err): ?><div class="alert alert-error" style="max-width:860px;"><?= e($err) ?></div><?php endforeach; ?>
  <form method="post" class="panel" style="max-width:860px;" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="reset_password">
    <div class="panel-head"><h2>Reset password</h2></div>
    <div class="panel-body">
      <p class="help">Forgot their password? Set a new temporary one. They must replace it at their next sign-in, and until they do the rest of the portal stays locked for them.</p>
      <div class="field" style="margin-bottom:0;"><label for="new_password">New temporary password</label>
        <div style="display:flex;gap:8px;"><input type="text" id="new_password" name="new_password" required minlength="10" autocomplete="off" spellcheck="false" style="font-family:var(--font-mono);"><button type="button" class="btn btn-outline" id="genPw2">Generate</button></div></div>
    </div>
    <div class="panel-foot"><button class="btn btn-outline" type="submit">Set temporary password</button></div>
  </form>
<?php endif; ?>

<script>
(function () {
  function gen(len) { var c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', b = new Uint32Array(len), o = ''; (window.crypto || window.msCrypto).getRandomValues(b); for (var i = 0; i < len; i++) o += c[b[i] % c.length]; return o; }
  [['genPw', 'password'], ['genPw2', 'new_password']].forEach(function (p) { var btn = document.getElementById(p[0]); if (btn) btn.addEventListener('click', function () { document.getElementById(p[1]).value = gen(14); }); });
  var idType = document.getElementById('id_type'), idLabel = document.getElementById('idNumberLabel'), idHint = document.getElementById('idHint');
  function syncId() { var nid = idType.value === 'nid'; idLabel.textContent = nid ? 'NID number' : 'Birth certificate number'; idHint.textContent = (nid ? '10, 13 or 17 digits' : '10 to 17 digits') + '. Shown masked in lists and never written to the activity log.'; }
  idType.addEventListener('change', syncId); syncId();
  var photo = document.getElementById('photo'), prev = document.getElementById('photoPreview');
  photo.addEventListener('change', function () {
    var f = photo.files[0]; if (!f) return;
    if (f.size > 8 * 1024 * 1024) { alert('That picture is over 8 MB. Please choose a smaller one.'); photo.value = ''; return; }
    var img = document.createElement('img'); img.id = 'photoPreview'; img.className = 'avatar'; img.alt = ''; img.style.cssText = 'width:96px;height:96px;'; img.src = URL.createObjectURL(f);
    prev.replaceWith(img); prev = img;
  });
  var file = document.getElementById('document');
  file.addEventListener('change', function () { if (file.files[0] && file.files[0].size > 5 * 1024 * 1024) { alert('That file is over 5 MB. Please choose a smaller one.'); file.value = ''; } });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
