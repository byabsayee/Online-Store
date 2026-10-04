<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $attempts = 0;
        while (true) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                // Always talk to the database in UTC, whatever the server's own
                // timezone is. Timestamps are stored as UTC and converted to the
                // store's timezone (TZ, Asia/Dhaka) only when displayed — see
                // fmt_dt(). This keeps NOW() comparisons in SQL consistent.
                $pdo->exec("SET time_zone = '+00:00'");
                break;
            } catch (PDOException $e) {
                // The db container can take a few seconds longer to become
                // ready than the web container on first `docker compose up`.
                $attempts++;
                if ($attempts >= 10) {
                    http_response_code(500);
                    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
                }
                sleep(2);
            }
        }
        // Load the settings right away: that is what applies any pending schema migration (see
        // all_settings() and migrate.php). Doing it here — not lazily on the first settings read —
        // guarantees the database is up to date before ANY page runs its first query. Otherwise a
        // page whose first query touches a table added by a new migration would crash on the first
        // request after a deploy, before anything had a chance to create that table.
        if (function_exists('all_settings')) {
            try { all_settings(); } catch (Throwable $e) { error_log('[db] could not preload settings: ' . $e->getMessage()); }
        }
    }
    return $pdo;
}
