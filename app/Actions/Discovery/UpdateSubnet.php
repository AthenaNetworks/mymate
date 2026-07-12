<?php

namespace App\Actions\Discovery;

use App\Models\Subnet;

class UpdateSubnet
{
    /** @param array<string, mixed> $data */
    public function __invoke(Subnet $subnet, array $data): Subnet
    {
        $subnet->update($data);

        return $subnet;
    }
}
