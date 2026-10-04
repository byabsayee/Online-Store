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
