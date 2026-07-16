import type { LinkMediaType } from '../../../types';

/**
 * Visual language for a link's physical medium (GitHub #9). Manual map-links colour fully by
 * medium (they carry no load); device links keep their load colour and use only the dash
 * pattern, so wireless reads as over-air without losing the utilisation ramp.
 */
export const MEDIA_TYPES: LinkMediaType[] = ['fiber', 'ethernet', 'wireless', 'other'];

export const MEDIA_META: Record<LinkMediaType, { label: string; color: string; dash?: string }> = {
    fiber: { label: 'Fiber', color: '#22d3ee' }, // cyan - glass
    ethernet: { label: 'Ethernet', color: '#fbbf24' }, // amber - copper
    wireless: { label: 'Wireless', color: '#a78bfa', dash: '5 4' }, // violet, dashed - over-air
    other: { label: 'Other', color: '#94a3b8' }, // slate
};

/** The dash pattern for a medium (device links use this over their load colour). */
export function mediaDash(media: LinkMediaType | null | undefined): string | undefined {
    return media ? MEDIA_META[media].dash : undefined;
}
