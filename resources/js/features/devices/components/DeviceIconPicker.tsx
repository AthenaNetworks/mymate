import { useState } from 'react';
import { ImageSquare } from '@phosphor-icons/react';
import { iconImageId, imageAssetUrl, IMAGE_ICON_PREFIX } from '../../../lib/imageAssets';
import { ImageLibraryDialog } from '../../maps/components/ImageLibraryDialog';
import { DEVICE_ICONS, DEVICE_ICON_KEYS, ICON_COLORS } from '../../../components/deviceIcons';

/**
 * The icon override (a named glyph, an image from the library, or Auto) and its colour - shared by
 * the device edit dialog and the bulk edit. `null` means Auto / the default colour. `iconSet` /
 * `colorSet` false shows nothing picked yet (bulk edit: "leave as is").
 */
export function DeviceIconPicker({
    icon,
    iconColor,
    onIconChange,
    onColorChange,
    iconSet = true,
    colorSet = true,
}: {
    icon: string | null;
    iconColor: string | null;
    onIconChange: (icon: string | null) => void;
    onColorChange: (color: string | null) => void;
    iconSet?: boolean;
    colorSet?: boolean;
}) {
    const [pickImage, setPickImage] = useState(false); // custom icon from the image library (GitHub #53)
    const imageId = iconSet ? iconImageId(icon) : null;
    const on = 'bg-emerald-500/20 ring-emerald-400/40';

    return (
        <>
            <div className="grid grid-cols-8 gap-1">
                <button
                    type="button"
                    onClick={() => onIconChange(null)}
                    title="Auto - product photo, vendor mark, or device type"
                    className={`grid h-8 place-items-center rounded-lg text-[9px] font-semibold ring-1 transition ${iconSet && icon === null ? `${on} text-emerald-200` : 'text-white/50 ring-white/10 hover:bg-white/5'}`}
                >
                    Auto
                </button>
                <button
                    type="button"
                    onClick={() => setPickImage(true)}
                    title="Use your own image from the library"
                    className={`grid h-8 place-items-center overflow-hidden rounded-lg ring-1 transition ${imageId !== null ? on : 'text-white/50 ring-white/10 hover:bg-white/5'}`}
                >
                    {imageId !== null ? <img src={imageAssetUrl(imageId)} alt="" className="h-5 w-5 object-contain" /> : <ImageSquare weight="duotone" className="h-4 w-4" />}
                </button>
                {DEVICE_ICON_KEYS.map((key) => {
                    const I = DEVICE_ICONS[key];
                    return (
                        <button
                            key={key}
                            type="button"
                            onClick={() => onIconChange(key)}
                            title={key}
                            className={`grid h-8 place-items-center rounded-lg ring-1 transition ${iconSet && icon === key ? on : 'ring-white/10 hover:bg-white/5'}`}
                        >
                            <I weight="duotone" className="h-4 w-4" style={{ color: (colorSet ? iconColor : null) ?? undefined }} />
                        </button>
                    );
                })}
            </div>
            <span className="block text-xs font-medium text-white/55">Colour</span>
            <div className="flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    onClick={() => onColorChange(null)}
                    title="Default colour"
                    className={`grid h-6 w-6 place-items-center rounded-full bg-white/5 text-[9px] text-white/50 ring-1 ${colorSet && iconColor === null ? 'ring-white/60' : 'ring-white/15'}`}
                >
                    A
                </button>
                {ICON_COLORS.map((c) => (
                    <button
                        key={c}
                        type="button"
                        onClick={() => onColorChange(c)}
                        title={c}
                        className={`h-6 w-6 rounded-full ring-2 transition ${colorSet && iconColor === c ? 'ring-white' : 'ring-transparent hover:ring-white/30'}`}
                        style={{ background: c }}
                    />
                ))}
                <input
                    type="color"
                    value={iconColor ?? '#34d399'}
                    onChange={(e) => onColorChange(e.target.value)}
                    title="Custom colour"
                    className="h-6 w-6 cursor-pointer rounded-full border-0 bg-transparent p-0 [color-scheme:dark]"
                />
            </div>
            {pickImage && (
                <ImageLibraryDialog
                    title="Pick an icon"
                    onClose={() => setPickImage(false)}
                    onPick={(asset) => {
                        onIconChange(`${IMAGE_ICON_PREFIX}${asset.id}`);
                        setPickImage(false);
                    }}
                />
            )}
        </>
    );
}
