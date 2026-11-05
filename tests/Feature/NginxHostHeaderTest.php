<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Debian's fastcgi_params passes HTTP_HOST as $host, which drops the port. Every nginx config we
 * ship has to put the browser's Host back, or on a non-standard port (Docker's 9443) Laravel builds
 * asset URLs without it and CSP blocks them: a blank page. (GH #62)
 */
class NginxHostHeaderTest extends TestCase
{
    public function test_every_shipped_nginx_config_passes_the_host_with_its_port(): void
    {
        $configs = [
            'docker/nginx.conf',
            'deploy/build/files/nginx-mymate.conf',
            'deploy/nginx/mymate.conf',
            'deploy/nginx/mymate-sales.conf',
            'deploy/ssl/nginx-tls.conf.tmpl',
        ];
        foreach ($configs as $config) {
            $text = file_get_contents(base_path($config));
            $this->assertMatchesRegularExpression(
                '/include fastcgi_params;.*?fastcgi_param HTTP_HOST \$http_host;/s',
                $text,
                "$config must set HTTP_HOST after including fastcgi_params",
            );
        }
    }
}
