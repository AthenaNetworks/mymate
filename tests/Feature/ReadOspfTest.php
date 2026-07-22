<?php

namespace Tests\Feature;

use App\Actions\Polling\ReadOspf;
use Tests\TestCase;

/**
 * GitHub #11: parse OSPF neighbour count + per-interface cost from RouterOS API rows. (The
 * transport is covered live; these lock the parsing, which is where the risk is.)
 */
class ReadOspfTest extends TestCase
{
    public function test_counts_only_full_neighbours(): void
    {
        $this->assertSame(2, ReadOspf::countFull([
            ['state' => 'Full'],
            ['state' => 'Full'],
            ['state' => '2-Way'],
            ['state' => 'Down'],
        ]));
        $this->assertSame(0, ReadOspf::countFull([]));
    }

    public function test_maps_interface_costs_last_value_wins_on_duplicates(): void
    {
        $costs = ReadOspf::costsByInterface([
            ['interface' => 'ether4', 'cost' => '1'],
            ['interface' => 'ether6', 'cost' => '10'],
            ['interface' => 'ether4', 'cost' => '1'],   // duplicate (another area) - same value
            ['interface' => 'no-cost'],                  // missing cost -> skipped
            ['cost' => '5'],                             // missing interface -> skipped
        ]);

        $this->assertSame(['ether4' => 1, 'ether6' => 10], $costs);
    }
}
