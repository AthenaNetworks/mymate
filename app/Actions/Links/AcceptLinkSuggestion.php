<?php

namespace App\Actions\Links;

use App\Models\Link;
use App\Models\LinkSuggestion;
use Illuminate\Support\Facades\DB;

/**
 * Draw the link a suggestion describes (GitHub #85), then drop it and any other suggestion the new
 * link now covers. Null when an existing link already covers it - the suggestion is just removed.
 */
class AcceptLinkSuggestion
{
    public function __construct(private CreateLink $createLink, private RecordNeighbors $neighbors) {}

    public function __invoke(LinkSuggestion $s): ?Link
    {
        return DB::transaction(function () use ($s): ?Link {
            $link = $this->neighbors->covered($s->a_device_id, $s->a_interface_id, $s->b_device_id, $s->b_interface_id)
                ? null
                : ($this->createLink)([
                    'a_device_id' => $s->a_device_id, 'a_interface_id' => $s->a_interface_id,
                    'b_device_id' => $s->b_device_id, 'b_interface_id' => $s->b_interface_id,
                ]);

            LinkSuggestion::where('a_device_id', $s->a_device_id)->where('b_device_id', $s->b_device_id)->get()
                ->filter(fn (LinkSuggestion $o) => $o->is($s) || $this->neighbors->covered($o->a_device_id, $o->a_interface_id, $o->b_device_id, $o->b_interface_id))
                ->each->delete();

            return $link;
        });
    }
}
