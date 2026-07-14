<?php

namespace Tests\Unit;

use App\Services\Ping\FpingRunner;
use PHPUnit\Framework\TestCase;

class FpingRunnerTest extends TestCase
{
    public function test_parses_alive_hosts_and_preserves_query_order(): void
    {
        $ips = ['10.0.0.1', '10.0.0.2', '10.0.0.3'];

        // Mixed JSON-Lines: resp/timeout events plus per-host summaries. .2 received nothing.
        $stdout = <<<'JSON'
        {"resp": {"host": "10.0.0.1", "seq": 0, "rtt": 0.04}}
        {"resp": {"host": "10.0.0.3", "seq": 0, "rtt": 0.06}}
        {"timeout": {"host": "10.0.0.2", "seq": 0}}
        {"summary": {"host": "10.0.0.1", "xmt": 1, "rcv": 1, "loss": 0}}
        {"summary": {"host": "10.0.0.2", "xmt": 1, "rcv": 0, "loss": 100}}
        {"summary": {"host": "10.0.0.3", "xmt": 1, "rcv": 1, "loss": 0}}
        JSON;

        $this->assertSame(['10.0.0.1', '10.0.0.3'], FpingRunner::parseReachable($stdout, $ips));
    }

    public function test_empty_output_means_none_reachable(): void
    {
        $this->assertSame([], FpingRunner::parseReachable("\n   \n", ['10.0.0.1', '10.0.0.2']));
    }

    public function test_parses_latency_loss_and_jitter_from_samples(): void
    {
        $ips = ['10.0.0.1', '10.0.0.2'];

        // .1 answered 2 of 3 (rtt 2.0 and 4.0 -> avg 3.0, jitter 2.0, loss 33%); .2 answered none.
        $stdout = <<<'JSON'
        {"resp": {"host": "10.0.0.1", "seq": 0, "rtt": 2.0}}
        {"resp": {"host": "10.0.0.1", "seq": 2, "rtt": 4.0}}
        {"timeout": {"host": "10.0.0.1", "seq": 1}}
        {"summary": {"host": "10.0.0.1", "xmt": 3, "rcv": 2, "loss": 33}}
        {"summary": {"host": "10.0.0.2", "xmt": 3, "rcv": 0, "loss": 100}}
        JSON;

        $samples = FpingRunner::parseSamples($stdout, $ips);

        $this->assertTrue($samples['10.0.0.1']->reachable);
        $this->assertSame(3.0, $samples['10.0.0.1']->rttMs);
        $this->assertSame(2.0, $samples['10.0.0.1']->jitterMs);
        $this->assertSame(33.0, $samples['10.0.0.1']->lossPct);

        $this->assertFalse($samples['10.0.0.2']->reachable);
        $this->assertNull($samples['10.0.0.2']->rttMs);
        $this->assertSame(100.0, $samples['10.0.0.2']->lossPct);
    }

    public function test_hosts_with_no_replies_are_not_reachable(): void
    {
        $stdout = <<<'JSON'
        {"summary": {"host": "10.0.0.1", "xmt": 1, "rcv": 0, "loss": 100}}
        {"summary": {"host": "10.0.0.2", "xmt": 1, "rcv": 0, "loss": 100}}
        JSON;

        $this->assertSame([], FpingRunner::parseReachable($stdout, ['10.0.0.1', '10.0.0.2']));
    }

    public function test_ignores_alive_hosts_not_in_the_query_set(): void
    {
        $stdout = <<<'JSON'
        {"summary": {"host": "10.0.0.1", "xmt": 1, "rcv": 1, "loss": 0}}
        {"summary": {"host": "9.9.9.9", "xmt": 1, "rcv": 1, "loss": 0}}
        JSON;

        $this->assertSame(['10.0.0.1'], FpingRunner::parseReachable($stdout, ['10.0.0.1', '10.0.0.2']));
    }

    public function test_skips_malformed_lines(): void
    {
        $stdout = <<<'JSON'
        not json at all
        {"summary": {"host": "10.0.0.1", "xmt": 1, "rcv": 1, "loss": 0}}
        {"summary": {"host":
        JSON;

        $this->assertSame(['10.0.0.1'], FpingRunner::parseReachable($stdout, ['10.0.0.1', '10.0.0.2']));
    }
}
