<?php
/**
 * Staff management helpers. A "staff member" is an admin account (table `admins`) plus profile
 * details. Only owners can create, edit, disable, delete or reset the password of staff; every
 * staff member can see their OWN details read-only on Admin > My account.
 *
 * Privacy notes:
 *  - The optional document (e.g. an ID scan) and the profile picture are stored in the database,
 *    never in the public uploads folder, and are only served by permission-checked pages to
 *    owners and to the person they belong to.
 *  - Profile pictures are re-encoded on upload (centre-cropped square, resized, metadata such as
 *    GPS location stripped), so what is stored is a clean image and never the original file.
 *  - The ID number is shown in full only on the person's own page and the owner edit form;
 *    lists and the activity log show it masked.
 */

const STAFF_BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
const STAFF_GENDERS = ['Male', 'Female', 'Other'];
const STAFF_ID_TYPES = ['nid' => 'NID', 'birth_certificate' => 'Birth certificate'];
const STAFF_DOC_MAX_BYTES = 5 * 1024 * 1024;
const STAFF_DOC_TYPES = ['application/pdf' => 'PDF', 'image/jpeg' => 'JPEG image', 'image/png' => 'PNG image', 'image/webp' => 'WebP image'];
const STAFF_PHOTO_MAX_BYTES = 8 * 1024 * 1024;
const STAFF_PHOTO_SIZE = 480;
const STAFF_COLUMNS = 'id, username, name, role, status, must_change_password, phone, email, address, blood_group, gender, id_type, id_number, date_of_birth, facebook_url, created_at, updated_at,
    (SELECT UNIX_TIMESTAMP(ph.updated_at) FROM admin_photos ph WHERE ph.admin_id = admins.id) AS photo_v';

function staff_get(int $id): ?array {
    $s = db()->prepare('SELECT ' . STAFF_COLUMNS . ' FROM admins WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** All staff, owners first, with a flag for "has a document". */
function staff_list(string $q = ''): array {
    $sql = 'SELECT ' . STAFF_COLUMNS . ', (SELECT COUNT(*) FROM admin_documents d WHERE d.admin_id = admins.id) AS has_doc FROM admins';
    $params = [];
    if ($q !== '') {
        $like = '%' . like_escape($q) . '%';
        $sql .= " WHERE name LIKE ? ESCAPE '|' OR username LIKE ? ESCAPE '|' OR phone LIKE ? ESCAPE '|' OR email LIKE ? ESCAPE '|'";
        $params = [$like, $like, $like, $like];
    }
    $stmt = db()->prepare($sql . ' ORDER BY (role = \'owner\') DESC, name ASC');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** True when a required profile field (or the picture) is still missing — used for the "Details incomplete" flag. */
function staff_incomplete(array $r): bool {
    foreach (['phone', 'email', 'address', 'blood_group', 'gender', 'id_number', 'date_of_birth', 'facebook_url'] as $k) {
        if (empty($r[$k])) return true;
    }
    return empty($r['photo_v']);
}

/** Owners who can still sign in, optionally not counting one account (used to protect the last owner). */
function staff_active_owner_count(?int $excludeId = null): int {
    $s = db()->prepare("SELECT COUNT(*) FROM admins WHERE role = 'owner' AND status = 'active' AND id <> ?");
    $s->execute([$excludeId ?? 0]);
    return (int) $s->fetchColumn();
}

/** "1234567890123" → "•••••••••0123" (list pages and the activity log never show the full number). */
function staff_mask_id(?string $n): string {
    $n = (string) $n;
    if ($n === '') return '—';
    return str_repeat('•', max(0, strlen($n) - 4)) . substr($n, -4);
}

function staff_password_error(string $pw): ?string {
    if (strlen($pw) < 10) return 'The password must be at least 10 characters.';
    if ($pw === 'ChangeMe123!') return 'Please choose a different password from the default one.';
    return null;
}

/** Age in whole years today (store timezone), or null when there is no valid date of birth. */
function staff_age(?string $dob): ?int {
    if (!$dob || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) return null;
    try {
        $tz = new DateTimeZone(date_default_timezone_get());
        return (int) (new DateTimeImmutable($dob, $tz))->diff(new DateTimeImmutable('today', $tz))->y;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Cleans a Facebook profile/page link. Accepts facebook.com, fb.com and fb.me links (with or without
 * https://, www., m. …) and returns a clean https URL, or null if it isn't one. The host is checked
 * exactly, so look-alikes such as facebook.com.evil.example or javascript: links are refused, and
 * Facebook's own redirector/share endpoints (l.php, sharer, dialog, login) are refused too.
 */
function staff_normalize_facebook(string $input): ?string {
    $input = trim($input);
    if ($input === '' || preg_match('/\s/', $input) || strlen($input) > 255) return null;
    if (!preg_match('~^https?://~i', $input)) $input = 'https://' . ltrim($input, '/');
    $p = parse_url($input);
    if (!$p || empty($p['host']) || isset($p['user']) || isset($p['port'])) return null;
    $host = strtolower($p['host']);
    $host = preg_replace('/^(www|m|web|mbasic|mobile|touch)\./', '', $host);
    if (!in_array($host, ['facebook.com', 'fb.com', 'fb.me'], true)) return null;
    $path = $p['path'] ?? '';
    $query = $p['query'] ?? '';
    if (trim($path, '/') === '' && $query === '') return null;                       // just "facebook.com"
    if (preg_match('~^/(l\.php|sharer(\.php)?|share|dialog|login(\.php)?|plugins|tr)(/|$)~i', $path)) return null;
    if ($path === '/profile.php' && !preg_match('/(^|&)id=\d+/', $query)) return null;
    $out = 'https://' . ($host === 'facebook.com' ? 'www.facebook.com' : $host) . $path . ($query !== '' ? '?' . $query : '');
    return strlen($out) <= 255 ? $out : null;
}

/**
 * Validates the staff form. Every profile field is required (the extra document is the only optional part;
 * the profile picture is checked separately, see staff_check_photo()).
 * @return array{0: string[], 1: array<string,?string>} [errors, cleaned values]
 */
function staff_validate(array $in, ?array $existing): array {
    $errors = [];
    $v = fn (string $k) => trim((string) ($in[$k] ?? ''));
    $id = $existing['id'] ?? 0;
    $idType = $v('id_type');

    $c = [
        'username' => strtolower($v('username')),
        'name' => preg_replace('/\s+/u', ' ', $v('name')),
        'role' => ($in['role'] ?? '') === 'owner' ? 'owner' : 'staff',
        'phone' => $v('phone'),
        'email' => strtolower($v('email')),
        'address' => trim(str_replace("\r", '', (string) ($in['address'] ?? ''))),
        'blood_group' => $v('blood_group'),
        'gender' => $v('gender'),
        'id_type' => isset(STAFF_ID_TYPES[$idType]) ? $idType : '',
        'id_number' => preg_replace('/[\s-]/', '', $v('id_number')),
        'date_of_birth' => $v('date_of_birth'),
        'facebook_url' => $v('facebook_url'),
    ];

    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $c['username'])) $errors[] = 'Username: 3–40 characters, using only letters, numbers, dot, dash or underscore.';
    else {
        $d = db()->prepare('SELECT id FROM admins WHERE username = ? AND id <> ?'); $d->execute([$c['username'], $id]);
        if ($d->fetch()) $errors[] = 'The username "' . $c['username'] . '" is already taken.';
    }
    if (mb_strlen($c['name']) < 2 || mb_strlen($c['name']) > 120) $errors[] = 'Enter the full name (2–120 characters).';
    if (!preg_match('/^\+?[\d\s().-]{5,40}$/', $c['phone']) || strlen(preg_replace('/\D/', '', $c['phone'])) < 7) $errors[] = 'Enter a valid phone number.';
    if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL) || strlen($c['email']) > 160) $errors[] = 'Enter a valid email address.';
    else {
        $d = db()->prepare('SELECT id FROM admins WHERE email = ? AND id <> ?'); $d->execute([$c['email'], $id]);
        if ($d->fetch()) $errors[] = 'Another staff account already uses that email address.';
    }
    if (mb_strlen($c['address']) < 5 || mb_strlen($c['address']) > 500) $errors[] = 'Enter the address (5–500 characters).';
    if (!in_array($c['blood_group'], STAFF_BLOOD_GROUPS, true)) $errors[] = 'Choose a blood group.';
    if (!in_array($c['gender'], STAFF_GENDERS, true)) $errors[] = 'Choose a gender.';

    // Date of birth: a real calendar date, not in the future. (Deliberately no minimum age.)
    $dob = $c['date_of_birth'];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) $errors[] = 'Enter a valid date of birth.';
    elseif ($dob > (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d')) $errors[] = 'The date of birth can\'t be in the future.';
    elseif ((int) $m[1] < 1920) $errors[] = 'Please check the date of birth — the year looks too early.';

    // ID: an NID (10, 13 or 17 digits) or a birth registration / certificate number (10–17 digits).
    if ($c['id_type'] === '') $errors[] = 'Choose whether the ID is an NID or a birth certificate.';
    else {
        $label = STAFF_ID_TYPES[$c['id_type']];
        $len = strlen($c['id_number']);
        $okLen = $c['id_type'] === 'nid' ? in_array($len, [10, 13, 17], true) : ($len >= 10 && $len <= 17);
        if (!preg_match('/^\d+$/', $c['id_number']) || !$okLen) {
            $errors[] = $c['id_type'] === 'nid' ? 'The NID number must be 10, 13 or 17 digits.' : 'The birth certificate number must be 10 to 17 digits.';
        } else {
            $d = db()->prepare('SELECT id FROM admins WHERE id_number = ? AND id <> ?'); $d->execute([$c['id_number'], $id]);
            if ($d->fetch()) $errors[] = 'That ' . $label . ' number is already registered to another staff account.';
        }
    }

    $fb = staff_normalize_facebook($c['facebook_url']);
    if ($fb === null) $errors[] = 'Enter a valid Facebook profile link (like https://www.facebook.com/their.name).';
    else $c['facebook_url'] = $fb;
    return [$errors, $c];
}

/**
 * Checks an uploaded document BEFORE anything is saved, so a bad file can't leave a half-saved record.
 * The type is decided from the file's real contents, never from its name or the browser's claim.
 * @return array{0: ?array{data:string,mime:string,size:int,name:string}, 1: ?string} [payload or null, error or null]
 *         [null, null] means "no file was chosen".
 */
function staff_check_document(?array $file): array {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) return [null, 'The document is too large — the limit is 5 MB.'];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return [null, 'The document could not be uploaded. Please try again.'];
    if ($file['size'] > STAFF_DOC_MAX_BYTES) return [null, 'The document is too large — the limit is 5 MB.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(STAFF_DOC_TYPES[$mime])) return [null, 'Only PDF, JPG, PNG or WebP documents are allowed.'];
    $data = file_get_contents($file['tmp_name']);
    if ($data === false || $data === '') return [null, 'The document is empty.'];
    if ($mime === 'application/pdf' && !str_starts_with($data, '%PDF-')) return [null, 'That does not look like a valid PDF.'];
    if ($mime !== 'application/pdf' && @getimagesizefromstring($data) === false) return [null, 'That does not look like a valid image.'];
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
    $name = preg_replace('/[^\p{L}\p{N} ._()-]+/u', '', basename((string) $file['name']));
    $name = trim(mb_substr($name, 0, 150));
    if ($name === '' || !preg_match('/\.' . $ext . '$/i', $name)) $name = ($name !== '' ? pathinfo($name, PATHINFO_FILENAME) : 'document') . '.' . $ext;
    return [['data' => $data, 'mime' => $mime, 'size' => strlen($data), 'name' => $name], null];
}

/** Saves (or replaces) a person's document from a payload returned by staff_check_document(). */
function staff_save_document(int $adminId, array $doc, ?int $byAdminId): void {
    $s = db()->prepare('INSERT INTO admin_documents (admin_id, original_name, mime, size, data, uploaded_by) VALUES (?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE original_name = VALUES(original_name), mime = VALUES(mime), size = VALUES(size), data = VALUES(data), uploaded_by = VALUES(uploaded_by), uploaded_at = CURRENT_TIMESTAMP');
    $s->bindValue(1, $adminId, PDO::PARAM_INT); $s->bindValue(2, $doc['name']); $s->bindValue(3, $doc['mime']); $s->bindValue(4, $doc['size'], PDO::PARAM_INT);
    $s->bindValue(5, $doc['data'], PDO::PARAM_LOB); $s->bindValue(6, $byAdminId, $byAdminId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $s->execute();
}

function staff_document_meta(int $adminId): ?array {
    $s = db()->prepare('SELECT id, original_name, mime, size, uploaded_at FROM admin_documents WHERE admin_id = ?');
    $s->execute([$adminId]);
    return $s->fetch() ?: null;
}

function staff_delete_document(int $adminId): void {
    db()->prepare('DELETE FROM admin_documents WHERE admin_id = ?')->execute([$adminId]);
}

function staff_human_size(int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

/* ------------------------------------------------------------ profile picture -- */

/** EXIF orientation (1–8) of a JPEG, read straight from the bytes (the exif extension isn't installed in the image). 1 = normal/unknown. */
function staff_jpeg_orientation(string $d): int {
    if (strlen($d) < 12 || substr($d, 0, 2) !== "\xFF\xD8") return 1;
    $pos = 2; $n = strlen($d);
    while ($pos + 4 <= $n) {
        if ($d[$pos] !== "\xFF") return 1;
        $marker = ord($d[$pos + 1]);
        if ($marker === 0xD9 || $marker === 0xDA) return 1;                             // end of image / start of scan
        $len = unpack('n', substr($d, $pos + 2, 2))[1];
        if ($marker === 0xE1 && substr($d, $pos + 4, 6) === "Exif\0\0") {
            $t = $pos + 10;                                                            // start of the TIFF header
            $le = substr($d, $t, 2) === 'II';
            $u16 = fn (int $o) => unpack($le ? 'v' : 'n', substr($d, $o, 2))[1] ?? 0;
            $u32 = fn (int $o) => unpack($le ? 'V' : 'N', substr($d, $o, 4))[1] ?? 0;
            $ifd = $t + $u32($t + 4);
            $count = $u16($ifd);
            for ($i = 0; $i < min($count, 64); $i++) {
                $e = $ifd + 2 + $i * 12;
                if ($u16($e) === 0x0112) { $o = $u16($e + 8); return ($o >= 1 && $o <= 8) ? $o : 1; }
            }
            return 1;
        }
        $pos += 2 + $len;
    }
    return 1;
}

/**
 * Validates and processes a profile picture BEFORE anything is saved. Accepts JPEG or PNG (the image's PHP has no WebP support).
 * The result is always a fresh 480×480 JPEG: centre-cropped square, EXIF rotation applied, all metadata dropped.
 * @return array{0: ?array{data:string,mime:string,size:int}, 1: ?string} [payload or null, error or null]; [null, null] = no file chosen
 */
function staff_check_photo(?array $file): array {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) return [null, 'The profile picture is too large — the limit is 8 MB.'];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return [null, 'The profile picture could not be uploaded. Please try again.'];
    if ($file['size'] > STAFF_PHOTO_MAX_BYTES) return [null, 'The profile picture is too large — the limit is 8 MB.'];
    if (!function_exists('imagecreatefromstring')) return [null, 'Image processing is not available on this server.'];
    $data = file_get_contents($file['tmp_name']);
    $info = $data === false ? false : @getimagesizefromstring($data);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return [null, 'The profile picture must be a JPG or PNG image.'];
    [$w, $h] = $info;
    if ($w < 150 || $h < 150) return [null, 'The profile picture is too small — please use one at least 150×150 pixels.'];
    if ($w * $h > 16000000) return [null, 'The profile picture has too many pixels (over 16 megapixels). Please resize it first.'];
    $src = @imagecreatefromstring($data);
    if ($src === false) return [null, 'That image could not be read. Please try a different one.'];

    if ($info[2] === IMAGETYPE_JPEG) {
        $o = staff_jpeg_orientation($data);
        $flip = fn ($img, $mode) => imageflip($img, $mode) ? $img : $img;
        switch ($o) {
            case 2: imageflip($src, IMG_FLIP_HORIZONTAL); break;
            case 3: $src = imagerotate($src, 180, 0); break;
            case 4: imageflip($src, IMG_FLIP_VERTICAL); break;
            case 5: imageflip($src, IMG_FLIP_HORIZONTAL); $src = imagerotate($src, 90, 0); break;
            case 6: $src = imagerotate($src, -90, 0); break;
            case 7: imageflip($src, IMG_FLIP_HORIZONTAL); $src = imagerotate($src, -90, 0); break;
            case 8: $src = imagerotate($src, 90, 0); break;
        }
    }
    $sw = imagesx($src); $sh = imagesy($src); $side = min($sw, $sh);
    $dst = imagecreatetruecolor(STAFF_PHOTO_SIZE, STAFF_PHOTO_SIZE);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));                     // transparent PNGs get a white background
    imagecopyresampled($dst, $src, 0, 0, (int) (($sw - $side) / 2), (int) (($sh - $side) / 2), STAFF_PHOTO_SIZE, STAFF_PHOTO_SIZE, $side, $side);
    ob_start(); imagejpeg($dst, null, 86); $out = (string) ob_get_clean();
    if ($out === '') return [null, 'That image could not be processed.'];
    return [['data' => $out, 'mime' => 'image/jpeg', 'size' => strlen($out)], null];
}

function staff_save_photo(int $adminId, array $p): void {
    $s = db()->prepare('INSERT INTO admin_photos (admin_id, mime, size, data) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE mime = VALUES(mime), size = VALUES(size), data = VALUES(data), updated_at = CURRENT_TIMESTAMP');
    $s->bindValue(1, $adminId, PDO::PARAM_INT); $s->bindValue(2, $p['mime']); $s->bindValue(3, $p['size'], PDO::PARAM_INT); $s->bindValue(4, $p['data'], PDO::PARAM_LOB);
    $s->execute();
}

function staff_initials(string $name): string {
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $i = mb_strtoupper(mb_substr($parts[0], 0, 1)) . (count($parts) > 1 ? mb_strtoupper(mb_substr(end($parts), 0, 1)) : '');
    return $i;
}

/** Round avatar: the person's picture, or their initials when they don't have one yet. $photoV is the picture's version (cache-buster) or null. */
function staff_avatar_html(int $adminId, string $name, $photoV, int $size = 36): string {
    $style = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . max(10, (int) round($size * 0.38)) . 'px;';
    if ($photoV) {
        return '<img class="avatar" src="/admin/staff_photo.php?id=' . $adminId . '&amp;v=' . (int) $photoV . '" alt="" width="' . $size . '" height="' . $size . '" style="' . $style . '" loading="lazy">';
    }
    return '<span class="avatar avatar-initials" aria-hidden="true" style="' . $style . '">' . e(staff_initials($name)) . '</span>';
}
