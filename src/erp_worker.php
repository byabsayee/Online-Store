<?php
/**
 * Background worker for the ERP integration. Run it every minute from cron:
 *
 *   * * * * * php /var/www/html/erp_worker.php >> /var/log/erp_worker.log 2>&1
 *
 * (The Docker image runs it for you every 30 seconds via supervisord.) It sends queued events
 * with retry/backoff, advances running import batches, reconciles hourly and prunes old logs.
 * Command line only: over HTTP it does nothing (and nginx answers 404 for it anyway).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ERP_NO_SESSION', true);
require_once __DIR__ . '/includes/functions.php';

try {
    $c = erp_conn(true);
    if (in_array($c['status'], ['disabled', 'revoked', 'pending'], true)) exit(0);
    if (!erp_lock('worker', 0)) exit(0); // another worker run is still going
    try {
        // Linked but the initial matching was never finished: do it now instead of holding every change back (switch off with the setting erp_auto_setup = 0).
        if (erp_active() && !erp_setup_done() && get_setting('erp_auto_setup', '1') !== '0') {
            [$ok, $m] = erp_auto_setup();
            echo date('c') . ' auto-setup: ' . ($ok ? 'ok' : 'waiting') . ' - ' . $m . "\n";
        }
        if (erp_active() && erp_setup_done()) {
            $stats = erp_flush(10);
            erp_backfill_invoices(20);
            erp_backfill_staff(25);
            if ($stats['sent'] || $stats['failed'] || $stats['dead']) echo date('c') . " sent={$stats['sent']} failed={$stats['failed']} dead={$stats['dead']}\n";
            foreach (db()->query("SELECT id FROM sync_import_batches WHERE status = 'running' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $bid) erp_import_step((int) $bid, 50);
            $last = erp_last_reconcile();
            if (!$last || (time() - (int) strtotime($last['ran_at'])) >= 3600) erp_reconcile();
        }
        $lastPrune = (int) get_setting('erp_last_prune', '0');
        if (time() - $lastPrune > 86400) { erp_prune(); set_setting('erp_last_prune', (string) time()); }
    } finally { erp_unlock('worker'); }
} catch (Throwable $e) {
    error_log('[erp worker] ' . $e->getMessage());
    fwrite(STDERR, '[erp worker] ' . $e->getMessage() . "\n");
    exit(1);
}
