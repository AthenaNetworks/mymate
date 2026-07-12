<?php

namespace App\Actions\Discovery;

use App\Models\Subnet;

class CreateSubnet
{
    /** @param array<string, mixed> $data */
    public function __invoke(array $data): Subnet
    {
        return Subnet::create($data);
    }
}
