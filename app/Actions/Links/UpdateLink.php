<?php

namespace App\Actions\Links;

use App\Models\Link;

class UpdateLink
{
    /** @param array<string, mixed> $data */
    public function __invoke(Link $link, array $data): Link
    {
        $link->update($data);

        // Reload both ends so the LinkResource carries the new util/speed back immediately.
        return $link->load('aInterface', 'bInterface');
    }
}
