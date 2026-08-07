<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Probe;
use App\Services\Probes\HttpProbe;
use App\Services\Probes\ProbeRunner;
use App\Services\Probes\TcpProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProbeRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function httpProbe(array $config): Probe
    {
        return Probe::factory()->create(['kind' => 'http', 'config' => $config]);
    }

    public function test_status_matcher_handles_lists_ranges_and_wildcards(): void
    {
        $this->assertTrue(HttpProbe::statusMatches(200, '200'));
        $this->assertTrue(HttpProbe::statusMatches(204, '200,204'));
        $this->assertTrue(HttpProbe::statusMatches(301, '200-399'));
        $this->assertTrue(HttpProbe::statusMatches(503, '5xx'));
        $this->assertFalse(HttpProbe::statusMatches(500, '200-399'));
        $this->assertFalse(HttpProbe::statusMatches(200, '2xxx')); // length guard
        $this->assertFalse(HttpProbe::statusMatches(404, '200,204'));
    }

    public function test_http_probe_up_on_expected_status(): void
    {
        Http::fake(['*' => Http::response('all good', 200)]);
        $r = app(ProbeRunner::class)->run($this->httpProbe(['url' => 'https://x.test/health', 'expect_status' => '200-399']));

        $this->assertTrue($r->up);
        $this->assertSame('HTTP 200', $r->message);
        $this->assertNotNull($r->latencyMs);
    }

    public function test_http_probe_down_on_unexpected_status(): void
    {
        Http::fake(['*' => Http::response('nope', 503)]);
        $r = app(ProbeRunner::class)->run($this->httpProbe(['url' => 'https://x.test/', 'expect_status' => '200-399']));

        $this->assertFalse($r->up);
        $this->assertSame('HTTP 503', $r->message);
    }

    public function test_http_probe_checks_body_keyword(): void
    {
        // Distinct hosts (one Http::fake call) - re-faking the same '*' pattern just stacks stubs.
        Http::fake([
            'healthy.test/*' => Http::response('status: healthy', 200),
            'degraded.test/*' => Http::response('status: degraded', 200),
        ]);

        $up = app(ProbeRunner::class)->run($this->httpProbe(['url' => 'https://healthy.test/', 'expect_body' => 'healthy']));
        $this->assertTrue($up->up);

        $down = app(ProbeRunner::class)->run($this->httpProbe(['url' => 'https://degraded.test/', 'expect_body' => 'healthy']));
        $this->assertFalse($down->up);
        $this->assertStringContainsString('missing', (string) $down->message);
    }

    public function test_http_probe_down_on_connection_error(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'));
        $r = app(ProbeRunner::class)->run($this->httpProbe(['url' => 'https://x.test/']));

        $this->assertFalse($r->up);
        $this->assertSame('timed out', $r->message);
    }

    public function test_tcp_probe_up_on_open_port_and_down_on_closed(): void
    {
        // A real listener on a free port -> up; then close it and probe a refused connect.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "could not open a test listener: {$errstr}");
        $addr = stream_socket_get_name($server, false);
        $port = (int) substr($addr, strrpos($addr, ':') + 1);

        $device = Device::factory()->create(['mgmt_ip' => '127.0.0.1']);
        $probe = Probe::factory()->tcp($port)->create(['device_id' => $device->id]);

        $up = (new TcpProbe)->run($probe->load('device'));
        $this->assertTrue($up->up);
        $this->assertNotNull($up->latencyMs);

        fclose($server); // port now closed
        $down = (new TcpProbe)->run($probe->load('device'));
        $this->assertFalse($down->up);
    }
}
