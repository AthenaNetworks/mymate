<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * An uploaded image in the library (GitHub #53): used as a device icon (devices.icon = "img:<id>")
 * or placed on maps as a {@see MapImage}. The file lives on the private local disk and is only
 * streamed back through ImageAssetController::serve().
 */
class ImageAsset extends Model
{
    public const DIR = 'image-assets';

    /** The prefix on devices.icon that points at a library image instead of a drawn glyph. */
    public const ICON_PREFIX = 'img:';

    protected $fillable = ['name', 'path', 'mime', 'version', 'width', 'height'];

    protected $hidden = ['path'];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $asset) {
            // Devices that used it as their icon fall back to the automatic one.
            Device::where('icon', self::ICON_PREFIX.$asset->id)->update(['icon' => null]);
            Storage::disk('local')->delete($asset->path);
        });
    }

    public function mapImages(): HasMany
    {
        return $this->hasMany(MapImage::class);
    }

    /** @return array{id:int, name:string, mime:string, version:string, width:int, height:int} */
    public function meta(): array
    {
        return $this->only('id', 'name', 'mime', 'version', 'width', 'height');
    }
}
