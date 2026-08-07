<?php

namespace App\Services\Probes;

use App\Enums\ProbeKind;
use App\Models\Probe;

/** Run one probe against its target, dispatching to the right check for its kind (GitHub #19). */
class ProbeRunner
{
    public function __construct(private HttpProbe $http, private TcpProbe $tcp) {}

    public function run(Probe $probe): ProbeResult
    {
        return match ($probe->kind) {
            ProbeKind::Http => $this->http->run($probe),
            ProbeKind::Tcp => $this->tcp->run($probe),
        };
    }
}
