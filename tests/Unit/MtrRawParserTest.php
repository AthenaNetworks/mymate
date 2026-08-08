<?php

namespace Tests\Unit;

use App\Services\Trace\MtrRawParser;
use PHPUnit\Framework\TestCase;

/**
 * MtrRawParser is a pure class (no framework deps), so this is a plain PHPUnit test -
 * no need to boot the app. Feeds a real `mtr --raw -b -c 1 1.1.1.1` capture (the transit
 * hops rewritten to RFC 5737 documentation addresses) and asserts the accounting plus
 * the trailing-target collapse. mtr's raw index is 0-based: index 0 is the first real
 * hop (the local gateway), so `h 0` is hop 1 in the reported, 1-based table.
 */
class MtrRawParserTest extends TestCase
{
    /** @var list<string> */
    private const SAMPLE_CAPTURE = [
        'x 0 33000',
        'h 0 192.0.2.1',
        'h 0 192.0.2.1',
        'p 0 1790 33000',
        'x 1 33001',
        'h 1 192.0.2.129',
        'h 1 192.0.2.129',
        'p 1 2714 33001',
        'x 2 33002',
        'h 2 198.51.100.9',
        'p 2 17478 33002',
        'x 3 33003',
        'x 4 33004',
        'x 5 33005',
        'h 5 198.51.100.42',
        'p 5 15877 33005',
        'x 6 33006',
        'h 6 203.0.113.7',
        'p 6 43077 33006',
        'x 7 33007',
        'h 7 1.1.1.1',
        'p 7 17688 33007',
        'x 8 33008',
        'h 8 1.1.1.1',
        'd 8 one.one.one.one',
        'p 8 15900 33008',
    ];

    public function test_collapses_trailing_target_duplicate_and_counts_one_round(): void
    {
        $snapshot = $this->parseSample();

        $this->assertSame(1, $snapshot['rounds_done']);
        // index 8 also answers as 1.1.1.1, but that's mtr's path-length-hunting artifact -
        // index 7 was the first hit, so it must not appear in the reported path at all.
        $this->assertCount(8, $snapshot['hops']);
        $this->assertSame(8, $snapshot['hops'][7]['ttl']);
        $this->assertSame('1.1.1.1', $snapshot['hops'][7]['ip']);
    }

    public function test_answered_hop_stats(): void
    {
        $hops = $this->parseSample()['hops'];

        $hop1 = $hops[0];
        $this->assertSame(1, $hop1['ttl']); // the index-0 gateway is presented as hop 1
        $this->assertSame('192.0.2.1', $hop1['ip']);
        $this->assertSame(1, $hop1['sent']);
        $this->assertSame(1, $hop1['recv']);
        $this->assertSame(0.0, $hop1['loss_pct']);
        // 1790us -> 1.79ms, rounded to 1 decimal; single sample so last=avg=best=worst, stdev=0.
        $this->assertSame(1.8, $hop1['last_ms']);
        $this->assertSame(1.8, $hop1['avg_ms']);
        $this->assertSame(1.8, $hop1['best_ms']);
        $this->assertSame(1.8, $hop1['worst_ms']);
        $this->assertSame(0.0, $hop1['stdev_ms']);

        $hop8 = $hops[7];
        $this->assertSame('1.1.1.1', $hop8['ip']);
        $this->assertSame(17.7, $hop8['last_ms']); // 17688us -> 17.688ms
    }

    public function test_unanswered_hop_reports_null_ip_and_full_loss(): void
    {
        $hops = $this->parseSample()['hops'];

        // index 3 was probed (x 3) but never answered (no h/p) - a dropped-probe hop,
        // distinct from a hop that was never reached at all. It shows as hop 4.
        $hop4 = $hops[3];
        $this->assertSame(4, $hop4['ttl']);
        $this->assertNull($hop4['ip']);
        $this->assertNull($hop4['ptr']);
        $this->assertSame(1, $hop4['sent']);
        $this->assertSame(0, $hop4['recv']);
        $this->assertSame(100.0, $hop4['loss_pct']);
        $this->assertNull($hop4['last_ms']);
        $this->assertNull($hop4['avg_ms']);
        $this->assertNull($hop4['best_ms']);
        $this->assertNull($hop4['worst_ms']);
        $this->assertNull($hop4['stdev_ms']);
    }

    public function test_averages_and_stdev_across_multiple_rounds(): void
    {
        $parser = new MtrRawParser('9.9.9.9'); // never seen below - no collapse in play
        $parser->feed('x 0 1');
        $parser->feed('h 0 8.8.8.8');
        $parser->feed('p 0 1000 1'); // 1.0 ms
        $parser->feed('x 0 2');
        $parser->feed('p 0 3000 2'); // 3.0 ms

        $snapshot = $parser->snapshot();
        $hop = $snapshot['hops'][0];

        $this->assertSame(2, $snapshot['rounds_done']);
        $this->assertSame(1, $hop['ttl']); // first hop presented 1-based
        $this->assertSame(2, $hop['sent']);
        $this->assertSame(2, $hop['recv']);
        $this->assertSame(1.0, $hop['best_ms']);
        $this->assertSame(3.0, $hop['worst_ms']);
        $this->assertSame(3.0, $hop['last_ms']);
        $this->assertSame(2.0, $hop['avg_ms']);
        $this->assertSame(1.0, $hop['stdev_ms']); // population stdev of {1.0, 3.0} = 1.0
    }

    /** @return array{rounds_done: int, hops: list<array<string, mixed>>} */
    private function parseSample(): array
    {
        $parser = new MtrRawParser('1.1.1.1');
        foreach (self::SAMPLE_CAPTURE as $line) {
            $parser->feed($line);
        }

        return $parser->snapshot();
    }
}
