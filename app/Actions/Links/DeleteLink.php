<?php

namespace App\Actions\Links;

use App\Models\Link;

class DeleteLink
{
    public function __invoke(Link $link): void
    {
        $link->delete();
    }
}
