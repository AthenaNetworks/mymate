<?php

namespace Tests\Feature;

use App\Actions\History\ManageHistoryPartitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HistoryPartitionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_upcoming_partitions(): void
    {
        config(['mymate.history.partitions_ahead' => 6]); // beyond the migration's +3
        $future = now()->startOfDay()->addDays(6);
        $name = 'interface_samples_'.$future->format('Ymd');

        $this->assertFalse(Schema::hasTable($name));

        $result = app(ManageHistoryPartitions::class)();

        $this->assertTrue(Schema::hasTable($name));
        $this->assertGreaterThanOrEqual(1, $result['created']);
    }

    public function test_drops_partitions_past_the_retention_edge(): void
    {
        $old = now()->startOfDay()->subDays(60);
        $oldName = 'interface_samples_'.$old->format('Ymd');
        $from = $old->format('Y-m-d 00:00:00');
        $to = $old->copy()->addDay()->format('Y-m-d 00:00:00');
        DB::statement("CREATE TABLE IF NOT EXISTS \"{$oldName}\" PARTITION OF interface_samples FOR VALUES FROM ('{$from}') TO ('{$to}')");
        $this->assertTrue(Schema::hasTable($oldName));

        config(['mymate.history.retention_days' => 14]);
        $result = app(ManageHistoryPartitions::class)();

        $this->assertFalse(Schema::hasTable($oldName)); // dropped - past retention
        $this->assertTrue(Schema::hasTable('interface_samples_'.now()->format('Ymd'))); // today kept
        $this->assertGreaterThanOrEqual(1, $result['dropped']);
    }

    public function test_is_idempotent(): void
    {
        app(ManageHistoryPartitions::class)();
        $second = app(ManageHistoryPartitions::class)();

        // Second run creates nothing new (everything already exists) and doesn't error.
        $this->assertSame(0, $second['created']);
    }
}
