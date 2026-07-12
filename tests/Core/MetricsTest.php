<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Metrics;
use App\Support\Worker\Heartbeat;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    public function testRenderProducesPrometheusTextFormat(): void
    {
        $out = Metrics::render([
            ['name' => 'o9_widgets_total', 'type' => 'gauge', 'help' => 'Total widgets', 'labels' => [], 'value' => 42],
        ]);
        $this->assertStringContainsString('# HELP o9_widgets_total Total widgets', $out);
        $this->assertStringContainsString('# TYPE o9_widgets_total gauge', $out);
        $this->assertStringContainsString('o9_widgets_total 42', $out);
    }

    public function testRenderEmitsHelpTypeOnceForRepeatedSampleNames(): void
    {
        $out = Metrics::render([
            ['name' => 'o9_jobs', 'type' => 'gauge', 'help' => 'Jobs by status', 'labels' => ['status' => 'ok'], 'value' => 3],
            ['name' => 'o9_jobs', 'type' => 'gauge', 'help' => 'Jobs by status', 'labels' => ['status' => 'failed'], 'value' => 1],
        ]);
        $this->assertSame(1, substr_count($out, '# HELP o9_jobs'));
        $this->assertStringContainsString('o9_jobs{status="ok"} 3', $out);
        $this->assertStringContainsString('o9_jobs{status="failed"} 1', $out);
    }

    public function testRenderEscapesLabelValues(): void
    {
        $out = Metrics::render([
            ['name' => 'o9_x', 'type' => 'gauge', 'help' => 'h', 'labels' => ['w' => 'a"b\\c'], 'value' => 1],
        ]);
        $this->assertStringContainsString('w="a\\"b\\\\c"', $out);
    }

    public function testCollectProducesWorkerLivenessFromHeartbeats(): void
    {
        // collect() reads the default run dir (config('worker.run_dir')) with
        // no injection seam, so write a heartbeat there directly.
        $dir = Heartbeat::runDir();
        Heartbeat::write('__metrics_test_worker', [], $dir);
        try {
            $samples = Metrics::collect();
            $names = array_column(array_filter($samples, fn ($s) => ($s['labels']['worker'] ?? null) === '__metrics_test_worker'), 'name');
            $this->assertContains('o9_worker_up', $names);
            $this->assertContains('o9_worker_heartbeat_age_seconds', $names);
        } finally {
            @unlink($dir . '/__metrics_test_worker.heartbeat');
        }
    }

    public function testCollectReturnsAListShape(): void
    {
        $samples = Metrics::collect();
        $this->assertIsArray($samples);
        foreach ($samples as $s) {
            $this->assertArrayHasKey('name', $s);
            $this->assertArrayHasKey('type', $s);
            $this->assertArrayHasKey('value', $s);
        }
    }
}
