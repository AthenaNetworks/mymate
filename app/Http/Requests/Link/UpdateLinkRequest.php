<?php

namespace App\Http\Requests\Link;

use App\Models\Link;

/**
 * Edit an existing link (re-bind either end). Reuses StoreLinkRequest's rules
 * (interface-belongs-to-device, no self-link, no duplicate in either direction)
 * but excludes the row being edited from the duplicate check.
 */
class UpdateLinkRequest extends StoreLinkRequest
{
    protected function ignoredLinkId(): ?int
    {
        $link = $this->route('link');

        return $link instanceof Link ? $link->getKey() : null;
    }
}
