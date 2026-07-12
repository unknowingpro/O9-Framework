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
<?php if ($samples === []): ?>
<p>No worker heartbeats yet — start a worker (e.g. <code>console queue:work</code>) to see it here.</p>
<?php else: ?>
<table>
    <thead><tr><th>Metric</th><th>Worker</th><th>Value</th></tr></thead>
    <tbody>
    <?php foreach ($samples as $s): ?>
        <tr>
            <td><?= View::e($s['name']) ?></td>
            <td><?= View::e($s['labels']['worker'] ?? '—') ?></td>
            <td><?= View::e($s['value']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<p><a href="/api/v1/admin/monitor/metrics" target="_blank">Raw Prometheus output</a></p>
