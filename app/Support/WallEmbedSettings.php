<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Where the public wallboard may be embedded in an iframe (GitHub #15). A single global list of
 * origins, stored in one `settings` row (key {@see self::KEY}) so an admin can edit it in-app,
 * falling back to `mymate.wall.frame_ancestors` (env MYMATE_WALL_FRAME_ANCESTORS) when unset.
 * Empty (the default) means no embedding at all, same as before.
 *
 * Why global and not per share link: the thing being allowed is "our intranet / our dashboard
 * host", which is a property of the organisation, not of one map. The share token is already
 * the access boundary (anyone holding it can open the page top-level anyway), so a per-link list
 * would add UI and places to audit without making a link any harder to view. One list in
 * Settings is easy to review and easy to empty in a hurry.
 *
 * It only ever applies to the /wall/{token} page itself - SecurityHeaders keeps DENY everywhere else.
 */
class WallEmbedSettings
{
    private const KEY = 'wall.embed';

    /** @return list<string> */
    public function frameAncestors(): array
    {
        $raw = Setting::where('key', self::KEY)->first()?->value;
        if (is_array($raw) && array_key_exists('frame_ancestors', $raw)) {
            return FrameAncestors::clean((array) $raw['frame_ancestors']);
        }

        $default = config('mymate.wall.frame_ancestors', '');
        $list = is_array($default) ? $default : preg_split('/[\s,]+/', (string) $default, -1, PREG_SPLIT_NO_EMPTY);

        return FrameAncestors::clean($list ?: []);
    }

    /**
     * @param  list<string>  $origins  already validated by the controller
     * @return list<string>
     */
    public function setFrameAncestors(array $origins): array
    {
        $clean = FrameAncestors::clean($origins);
        Setting::updateOrCreate(
            ['key' => self::KEY],
            ['value' => ['frame_ancestors' => $clean], 'type' => 'json'],
        );

        return $clean;
    }

    /** @return array{frame_ancestors:list<string>} */
    public function publicView(): array
    {
        return ['frame_ancestors' => $this->frameAncestors()];
    }
}
