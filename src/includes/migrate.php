<?php
/**
 * Tiny forward-only migrator. Applies sql/migrations/NNN_*.sql files newer
 * than the `schema_version` setting, so a redeploy of the image upgrades the
 * database by itself — no manual phpMyAdmin step. Migrations 001-003 predate
 * this and are assumed applied. Every auto-applied file must be idempotent.
 */
const MIGRATE_BASELINE = 3;
/** Highest migration number the code expects; all_settings() upgrades the database until it reaches it. */
const MIGRATE_LATEST = 14;

function migration_dirs(): array {
    return [
        '/var/www/migrations',                 // baked into the Docker image
        __DIR__ . '/../../sql/migrations',     // repo checkout / local dev
        __DIR__ . '/../sql/migrations',
    ];
}

/** Where a fresh-install schema.sql may live: baked into the image, or in a repo checkout. */
function schema_candidates(): array {
    return [
        '/var/www/schema.sql',                 // baked into the Docker image
        __DIR__ . '/../../sql/schema.sql',     // repo checkout / local dev
        __DIR__ . '/../sql/schema.sql',
    ];
}

/** True when an exception means "the settings table is not there yet" (a brand-new, empty database). */
function schema_is_missing(Throwable $e): bool {
    return $e instanceof PDOException
        && (($e->errorInfo[1] ?? 0) === 1146 || $e->getCode() === '42S02');
}

/**
 * First boot on an empty database: create every table from schema.sql, then apply the first-run admin
 * credentials from ADMIN_USER / ADMIN_PASS (default admin / admin; the owner is still forced to choose a new
 * password on first sign-in). Replaces the old "import schema.sql in phpMyAdmin" step. Safe to call from
 * several requests at once (named lock + re-check) and a no-op on a database that already has tables.
 */
function bootstrap_schema(PDO $pdo): bool {
    $file = null;
    foreach (schema_candidates() as $f) { if (is_file($f)) { $file = $f; break; } }
    if ($file === null) return false;

    $lock = (int) $pdo->query("SELECT GET_LOCK('store_bootstrap', 30)")->fetchColumn();
    if (!$lock) return false;
    try {
        if ($pdo->query("SHOW TABLES LIKE 'settings'")->fetchColumn() !== false) return true; // someone else finished first

        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(";\n", $sql . "\n"))) as $stmt) {
            $pdo->exec($stmt);
        }

        $user = trim((string) env_val('ADMIN_USER', 'admin'));
        $pass = (string) env_val('ADMIN_PASS', 'admin');
        if ($user === '') $user = 'admin';
        $pdo->prepare("UPDATE admins SET username = ?, password_hash = ?, must_change_password = 1 WHERE role = 'owner' ORDER BY id LIMIT 1")
            ->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
        return true;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('store_bootstrap')");
    }
}

/** Passwords that ship with the product and must never be kept: the seeded one and whatever ADMIN_PASS set. */
function default_admin_passwords(): array {
    return array_values(array_unique(['ChangeMe123!', 'admin', (string) env_val('ADMIN_PASS', 'admin')]));
}

function run_pending_migrations(PDO $pdo, int $current): int {
    $dir = null;
    foreach (migration_dirs() as $d) { if (is_dir($d)) { $dir = $d; break; } }
    if ($dir === null) return $current;

    $files = glob($dir . '/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($files);
    $lock = (int) $pdo->query("SELECT GET_LOCK('store_migrate', 15)")->fetchColumn();
    if (!$lock) return $current;
    try {
        // Another request may have finished while we waited for the lock.
        $row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn();
        $current = $row !== false ? (int) $row : $current;

        foreach ($files as $file) {
            $version = (int) basename($file);
            if ($version <= max($current, MIGRATE_BASELINE)) continue;
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
            foreach (array_filter(array_map('trim', explode(";\n", $sql . "\n"))) as $stmt) {
                $pdo->exec($stmt);
            }
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string) $version]);
            $current = $version;
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('store_migrate')");
    }
    return $current;
}
