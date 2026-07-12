<?php

namespace App\Actions\Links;

use App\Models\Link;

class CreateLink
{
    /** @param array<string, mixed> $data */
    public function __invoke(array $data): Link
    {
        // Eager-load both ends so the LinkResource carries util/speed back immediately.
        return Link::create($data)->load('aInterface', 'bInterface');
    }
}
