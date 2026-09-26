<?php

namespace Tests\Unit;

use App\Actions\Polling\RecordDeviceResources;
use App\Services\Polling\RouterOsDeviceMetricsDriver;
use App\Services\Polling\SnmpDeviceMetricsDriver;
use App\Services\Polling\StorageReading;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/** hrStorageTable parsing, RouterOS storage, uptime parsing and reboot detection (no DB). */
class StorageReadingTest extends TestCase
{
    /** A net-snmp Linux box, walked as a whole hrStorageEntry ("column.index" keys). */
    private function linuxEntry(): array
    {
        $rows = [
            // index => [type, descr, units, size, used]
            1 => ['.1.3.6.1.2.1.25.2.1.2', 'Physical memory', '1024', '16303848', '4075962'],
            3 => ['.1.3.6.1.2.1.25.2.1.3', 'Virtual memory', '1024', '18400996', '4219000'],
            6 => ['.1.3.6.1.2.1.25.2.1.1', 'Memory buffers', '1024', '16303848', '300000'],
            7 => ['.1.3.6.1.2.1.25.2.1.1', 'Cached memory', '1024', '8000000', '8000000'],
            10 => ['.1.3.6.1.2.1.25.2.1.3', 'Swap space', '1024', '2097148', '144038'],
            31 => ['.1.3.6.1.2.1.25.2.1.4', '/', '4096', '-1', '100'],       // overflowed Integer32
            35 => ['.1.3.6.1.2.1.25.2.1.4', '/boot', '1024', '0', '0'],       // no size, dropped
            40 => ['.1.3.6.1.2.1.25.2.1.7', '/media/cdrom', '2048', '100', '100'],
        ];
        $entry = [];
        foreach ($rows as $idx => [$type, $descr, $units, $size, $used]) {
            $entry["2.{$idx}"] = $type;
            $entry["3.{$idx}"] = $descr;
            $entry["4.{$idx}"] = $units;
            $entry["5.{$idx}"] = $size;
            $entry["6.{$idx}"] = $used;
            $entry["7.{$idx}"] = '0'; // allocation failures, ignored
        }

        return $entry;
    }

    public function test_entry_walk_keeps_ram_swap_and_disks_in_bytes(): void
    {
        $rows = collect(StorageReading::fromHrEntry($this->linuxEntry()))->keyBy('key');

        // buffers / cached (type other), the cdrom and the size-0 row are left out
        $this->assertSame(['1', '3', '10', '31'], $rows->keys()->map(fn ($k) => (string) $k)->all());
        $ram = $rows['1'];
        $this->assertSame('ram', $ram->type);
        $this->assertSame(16303848 * 1024, $ram->sizeBytes);
        $this->assertSame(4075962 * 1024, $ram->usedBytes);
        $this->assertEqualsWithDelta(25.0, $ram->usedPct(), 0.01);
        $this->assertSame('virtual_memory', $rows['10']->type);

        // -1 on a big disk is 4294967295 units once read as unsigned
        $root = $rows['31'];
        $this->assertSame('fixed_disk', $root->type);
        $this->assertSame(4294967295 * 4096, $root->sizeBytes);
    }

    public function test_columns_without_a_type_guess_it_from_the_description(): void
    {
        $rows = StorageReading::fromHrColumns(
            ['65536' => 'main memory', '131072' => 'system disk', '5' => 'Cached memory'],
            [], [],
            ['65536' => '1000', '131072' => '2000', '5' => '100'],
            ['65536' => '250', '131072' => '500', '5' => '100'],
        );

        $this->assertCount(2, $rows);
        $this->assertSame('ram', $rows[0]->type);
        $this->assertSame('fixed_disk', $rows[1]->type);
        $this->assertSame(25.0, $rows[1]->usedPct());
    }

    public function test_hr_type_accepts_an_oid_a_number_or_one_of_our_names(): void
    {
        $this->assertSame('fixed_disk', StorageReading::hrType('.1.3.6.1.2.1.25.2.1.4'));
        $this->assertSame('flash', StorageReading::hrType('iso.3.6.1.2.1.25.2.1.9'));
        $this->assertSame('ram', StorageReading::hrType('2'));
        $this->assertSame('flash', StorageReading::hrType('flash'));
        $this->assertSame('other', StorageReading::hrType('rubbish'));
    }

    public function test_routeros_resource_row_gives_memory_and_system_disk(): void
    {
        $rows = RouterOsDeviceMetricsDriver::storagesFromResource([
            'total-memory' => '1073741824', 'free-memory' => '805306368',
            'total-hdd-space' => '134217728', 'free-hdd-space' => '100663296',
        ]);

        $this->assertSame(['memory', 'system-disk'], array_map(fn ($r) => $r->key, $rows));
        $this->assertSame(268435456, $rows[0]->usedBytes);
        $this->assertSame('flash', $rows[1]->type);
        $this->assertSame(25.0, $rows[1]->usedPct());
        $this->assertNull(RouterOsDeviceMetricsDriver::storagesFromResource([]));
    }

    public function test_timeticks_parse_plain_and_bracketed(): void
    {
        $this->assertSame(123456, SnmpDeviceMetricsDriver::timeticks('123456'));
        $this->assertSame(123456, SnmpDeviceMetricsDriver::timeticks('(123456) 0:20:34.56'));
        $this->assertNull(SnmpDeviceMetricsDriver::timeticks('No Such Object available on this agent at this OID'));
        $this->assertNull(SnmpDeviceMetricsDriver::timeticks(null));
    }

    public function test_reboot_is_uptime_going_backwards_but_not_a_timeticks_wrap(): void
    {
        $at = Carbon::parse('2026-09-26 12:00:00');
        $now = $at->copy()->addSeconds(30);

        $this->assertFalse(RecordDeviceResources::rebooted(null, null, 50, $now));        // first reading
        $this->assertFalse(RecordDeviceResources::rebooted(1000, $at, 1030, $now));       // still climbing
        $this->assertTrue(RecordDeviceResources::rebooted(864000, $at, 25, $now));        // 10 days -> 25s
        // ~497 days of TimeTicks rolls over to a small number without a reboot
        $this->assertFalse(RecordDeviceResources::rebooted(42949600, $at, 40, $now));
    }
}
