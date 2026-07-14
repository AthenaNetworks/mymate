<?php

namespace App\Providers;

use App\Services\Discovery\HostProber;
use App\Services\Discovery\Scanner;
use App\Services\Ping\FpingRunner;
use App\Services\Ping\Pinger;
use App\Services\RouterOs\EvilFreelancerRouterOsClient;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Snmp\PhpSnmpClient;
use App\Services\Snmp\SnmpClient;
use App\Services\Upgrade\DeviceRebootWaiter;
use App\Services\Upgrade\PollingRebootWaiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Drivers live behind interfaces so they're swappable + fakeable in tests.
        $this->app->bind(Pinger::class, fn () => new FpingRunner(
            timeoutMs: (int) config('mymate.ping.timeout_ms', 500),
            retries: (int) config('mymate.ping.retries', 1),
        ));

        $this->app->bind(SnmpClient::class, fn () => new PhpSnmpClient(
            timeoutUs: (int) config('mymate.snmp.timeout_us', 1_000_000),
            retries: (int) config('mymate.snmp.retries', 1),
        ));

        // Stateless - connection details (incl. the short timeout) come per device
        // via RouterOsTarget. Swap this bind for a REST impl behind the same interface.
        $this->app->bind(RouterOsClient::class, fn () => new EvilFreelancerRouterOsClient);

        // Auto-discovery: the CIDR expander + credential-pool prober carry
        // their safety knobs (host cap, lockout-aware login spacing) from config.
        $this->app->bind(Scanner::class, fn () => new Scanner(
            maxHosts: (int) config('mymate.discovery.max_hosts_per_subnet', 4096),
        ));

        $this->app->bind(HostProber::class, fn (Application $app) => new HostProber(
            $app->make(SnmpClient::class),
            $app->make(RouterOsClient::class),
            attemptDelayMs: (int) config('mymate.discovery.attempt_delay_ms', 200),
        ));

        // Ordered bulk upgrades wait for each device to recover before the
        // next; the polling impl reads devices.status (kept fresh by the fping loop).
        $this->app->bind(DeviceRebootWaiter::class, PollingRebootWaiter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->trustSpaRequestHost();
    }

    /**
     * The console (SPA) is always served same-origin - nginx serves the app shell and
     * the API from the one host - so the address the browser actually uses is
     * authoritative for cookie auth. Add it to Sanctum's stateful list at runtime so
     * login works on whatever the instance is reached by (LXC/VM IP, hostname or DNS,
     * or an IP that changed since first boot) without hand-editing
     * SANCTUM_STATEFUL_DOMAINS. Without this, browsing to an address that isn't in the
     * list leaves the session cookie unhonoured and login "flashes then bounces back".
     *
     * This is safe: being stateful only means the session cookie is honoured, and the
     * browser scopes that cookie to the real origin - a spoofed Host header gains an
     * attacker nothing, and bearer-token clients (agents) send no Referer/Origin so
     * they're never treated as frontend requests. (GH #4)
     */
    private function trustSpaRequestHost(): void
    {
        $host = trim((string) request()->getHttpHost()); // host, or host:port for a non-standard port
        if ($host === '') {
            return;
        }

        $stateful = config('sanctum.stateful', []);
        if (! in_array($host, $stateful, true)) {
            config(['sanctum.stateful' => [...$stateful, $host]]);
        }
    }
}
