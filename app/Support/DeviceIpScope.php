<?php

namespace App\Support;

use App\Models\Agent;
use App\Models\Device;
use Illuminate\Validation\Validator;

/**
 * Enforces "one device per management IP per poll scope" at validation time (GitHub #49), so a
 * clash comes back as a readable 422 on mgmt_ip instead of the unique index's 500. Checks the
 * *effective* IP + agent together, which also catches moving an existing device onto an agent
 * that already polls that IP.
 */
class DeviceIpScope
{
    public static function check(Validator $validator, ?string $ip, ?int $agentId, ?int $ignoreId = null): void
    {
        // Let the field rules report their own problems first (bad IP, unknown agent).
        if ($ip === null || $ip === '' || $validator->errors()->hasAny(['mgmt_ip', 'agent_id'])) {
            return;
        }

        $other = Device::ipConflict($ip, $agentId, $ignoreId);
        if ($other === null) {
            return;
        }

        $where = $agentId === null
            ? 'on the central server'
            : 'behind agent "'.(Agent::whereKey($agentId)->value('name') ?? "#{$agentId}").'"';

        $validator->errors()->add(
            'mgmt_ip',
            "{$ip} is already used by \"{$other->name}\" {$where}. The same IP can be reused on a different agent.",
        );
    }
}
