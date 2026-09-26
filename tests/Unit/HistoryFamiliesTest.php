<?php

namespace Tests\Unit;

use App\Actions\History\HistoryFamilies;
use PHPUnit\Framework\TestCase;

/** The registry is what a generic history reader builds on, so keep it complete and consistent. */
class HistoryFamiliesTest extends TestCase
{
    private const UNITS = ['bps', 'pps', 'pct', 'dBm', 'dB', 's', 'bytes', 'count', 'ms', 'C', null];

    private const KINDS = ['gauge', 'rate', 'availability'];

    public function test_every_metric_has_a_label_a_known_unit_and_a_kind(): void
    {
        foreach (HistoryFamilies::FAMILIES as $family => $spec) {
            $this->assertNotEmpty($spec['label'] ?? null, "{$family} has no label");
            $this->assertNotEmpty($spec['entity'] ?? null, "{$family} has no entity");
            foreach (array_keys($spec['metrics']) as $metric) {
                $meta = $spec['meta'][$metric] ?? null;
                $this->assertIsArray($meta, "{$family}.{$metric} has no meta");
                $this->assertNotSame('', $meta['label']);
                $this->assertContains($meta['unit'], self::UNITS, "{$family}.{$metric} unit");
                $this->assertContains($meta['kind'], self::KINDS, "{$family}.{$metric} kind");
            }
            // no meta for a metric that doesn't exist
            $this->assertSame([], array_diff(array_keys($spec['meta'] ?? []), array_keys($spec['metrics'])), $family);
        }
    }

    public function test_the_device_page_families_and_metrics_are_registered(): void
    {
        $this->assertSame(['interface_id'], HistoryFamilies::get('optical')['keys']);
        $this->assertSame(['device_id', 'cpu_index'], HistoryFamilies::get('cpu')['keys']);
        $this->assertSame(['storage_id', 'device_id'], HistoryFamilies::get('storage')['keys']);
        foreach (['pkts_in', 'pkts_out', 'errors_in', 'errors_out', 'discards_in', 'discards_out', 'up_pct'] as $m) {
            $this->assertArrayHasKey($m, HistoryFamilies::get('interface')['metrics']);
        }
        $this->assertArrayHasKey('uptime_s', HistoryFamilies::get('device_metric')['metrics']);
        $this->assertSame('availability', HistoryFamilies::metricMeta('interface', 'up_pct')['kind']);
        $this->assertSame('pps', HistoryFamilies::metricMeta('interface', 'errors_in')['unit']);
        $this->assertStringContainsString('oper_up', HistoryFamilies::rawExpr('interface', 'up_pct'));

        foreach (['optical_samples', 'cpu_samples', 'storage_samples', 'interface_samples'] as $t) {
            $this->assertContains($t, HistoryFamilies::rawTables());
        }
    }

    public function test_describe_lists_the_aggregates_a_reader_can_ask_for(): void
    {
        $d = HistoryFamilies::describe();

        $this->assertSame(['avg', 'max', 'min'], $d['device_metric']['metrics']['uptime_s']['aggregates']);
        $this->assertSame('bytes', $d['storage']['metrics']['used_bytes']['unit']);
        $this->assertSame('interface', $d['optical']['entity']);
        $this->assertSame(['avg', 'max'], $d['interface']['metrics']['bps_in']['aggregates']);
    }

    public function test_unknown_metric_meta_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HistoryFamilies::metricMeta('cpu', 'nope');
    }
}
