<?php

namespace App\Actions\Discovery;

use App\Models\Subnet;

class DeleteSubnet
{
    public function __invoke(Subnet $subnet): void
    {
        $subnet->delete();
    }
}
