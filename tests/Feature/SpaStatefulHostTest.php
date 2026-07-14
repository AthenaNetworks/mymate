<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * SPA cookie auth must work on whatever address the instance is reached by, not only
 * the hosts baked into SANCTUM_STATEFUL_DOMAINS at install/first-boot time. The app is
 * served same-origin, so AppServiceProvider adds the current request host to Sanctum's
 * stateful list at runtime. Regression for the LXC "login flashes then bounces back to
 * login" report (GH #4).
 */
class SpaStatefulHostTest extends TestCase
{
    private function bootWithHost(string $url): void
    {
        $this->app->instance('request', Request::create($url, 'GET'));
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_the_current_request_host_becomes_a_stateful_domain(): void
    {
        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);

        // An address not in the configured list - exactly Mark's LXC IP.
        $this->bootWithHost('http://10.1.1.14/login');

        $this->assertContains('10.1.1.14', config('sanctum.stateful'));
    }

    public function test_a_nonstandard_port_is_preserved(): void
    {
        config(['sanctum.stateful' => ['localhost']]);

        $this->bootWithHost('http://10.1.1.14:8000/');

        // Sanctum matches the referer authority incl. port, so the entry must carry it.
        $this->assertContains('10.1.1.14:8000', config('sanctum.stateful'));
    }

    public function test_an_already_listed_host_is_not_duplicated(): void
    {
        config(['sanctum.stateful' => ['10.1.1.14']]);

        $this->bootWithHost('http://10.1.1.14/');

        $this->assertSame(['10.1.1.14'], array_values(config('sanctum.stateful')));
    }
}
