<?php
require_once 'config.php'; require_once 'includes/Database.php'; require_once 'includes/Auth.php'; require_once 'includes/Layout.php'; require_once 'includes/Automation.php';
Auth::requireLogin(); Auth::requireWrite();
Automation::ensureSchema();
$page = max(1, (int) ($_GET['page'] ?? 1)); $perPage = ITEMS_PER_PAGE;
$total = (int) (Database::fetch('SELECT COUNT(*) c FROM automation_runs')['c'] ?? 0); $pages = max(1, (int) ceil($total / $perPage));
$runs = Database::fetchAll("SELECT ar.*, ab.bucket_name, ab.activity_id, ad.activity_name FROM automation_runs ar JOIN automation_buckets ab ON ab.id = ar.bucket_id JOIN activity_definitions ad ON ad.id = ab.activity_id ORDER BY ar.started_at DESC LIMIT ? OFFSET ?", [$perPage, ($page - 1) * $perPage]);
Layout::renderHead('Automation Run Logs');
?>
<div class="page-header"><h1><i data-lucide="history"></i> Automation Run Logs</h1><p class="text-muted">Every bucket run, retry outcome, and manual-diagnosis escalation.</p></div>
<div class="card"><div class="table-responsive"><table class="data-table"><thead><tr><th>Run</th><th>Bucket</th><th>Activity</th><th>Status</th><th>Started</th><th>Finished</th><th>Total</th><th>Sent</th><th>Skipped</th><th>Manual</th></tr></thead><tbody>
<?php foreach ($runs as $run): ?><tr><td>#<?= $run['id'] ?></td><td><strong><?= htmlspecialchars($run['bucket_name']) ?></strong></td><td><?= $run['activity_id'] ?>. <?= htmlspecialchars($run['activity_name']) ?></td><td><span class="badge <?= $run['manual_count'] ? 'badge-danger' : ((int) ($run['skipped_count'] ?? 0) ? 'badge-warning' : 'badge-success') ?>"><?= htmlspecialchars($run['status']) ?></span></td><td class="text-muted"><?= htmlspecialchars(toDisplay((string) $run['started_at'])) ?></td><td class="text-muted"><?= $run['finished_at'] ? htmlspecialchars(toDisplay((string) $run['finished_at'])) : 'Running' ?></td><td><?= $run['total_count'] ?></td><td><?= $run['success_count'] ?></td><td><?= (int) ($run['skipped_count'] ?? 0) ?></td><td><?= $run['manual_count'] ?></td></tr><?php endforeach; ?>
<?php if (!$runs): ?><tr><td colspan="10" class="empty-state"><i data-lucide="history"></i><p>No bucket runs yet</p></td></tr><?php endif; ?></tbody></table></div><?= Layout::pagination($page, $pages, 'run_logs?') ?></div>
<?php Layout::renderFoot(); ?>