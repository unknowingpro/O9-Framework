<?php
/**
 * @var array<string, mixed>|null $user
 */
use App\Core\Metrics;
use App\Core\View;

$samples = Metrics::collect();
?>
<h1>Dashboard</h1>
<p>Signed in as <strong><?= View::e($user['id'] ?? 'unknown') ?></strong>.</p>

<h2>Worker liveness</h2>
<p id="monitor-empty"<?= $samples === [] ? '' : ' hidden' ?>>No worker heartbeats yet — start a worker (e.g. <code>console queue:work</code>) to see it here.</p>
<div class="table-scroll">
<table id="monitor-table"<?= $samples === [] ? ' hidden' : '' ?>>
    <thead><tr><th>Metric</th><th>Worker</th><th>Value</th></tr></thead>
    <tbody id="monitor-body">
    <?php foreach ($samples as $s): ?>
        <tr>
            <td><?= View::e($s['name']) ?></td>
            <td><?= View::e($s['labels']['worker'] ?? '—') ?></td>
            <td><?= View::e($s['value']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="refresh-hint">Auto-refreshes every 10s.</p>

<p><a href="/api/v1/admin/monitor/metrics" target="_blank">Raw Prometheus output</a></p>
