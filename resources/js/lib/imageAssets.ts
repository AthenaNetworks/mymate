import { wallToken } from './wall';

/** Prefix on a device's `icon` that points at an image in the library (GitHub #53). */
export const IMAGE_ICON_PREFIX = 'img:';

/** The library image id a device icon points at, or null for a drawn glyph / auto. */
export function iconImageId(icon: string | null | undefined): number | null {
    if (!icon?.startsWith(IMAGE_ICON_PREFIX)) return null;
    const id = Number(icon.slice(IMAGE_ICON_PREFIX.length));
    return Number.isInteger(id) && id > 0 ? id : null;
}

/**
 * URL of a library image. On the public wallboard it goes through the share token (which only
 * serves images the shared map uses); everywhere else the logged-in route. Library images never
 * change in place (a new upload is a new id), so the id alone is a safe cache key.
 */
export function imageAssetUrl(id: number): string {
    const token = wallToken();
    return token ? `/api/public/wall/${token}/images/${id}` : `/api/image-assets/${id}/file`;
}
