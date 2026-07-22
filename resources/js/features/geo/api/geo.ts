import { useQuery, useMutation } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export interface MapConfig {
    enabled: boolean;
    tile_url: string;
    attribution: string;
    geocoder_enabled: boolean;
}

/** Tile URL + attribution for the geo overlay (from server config). */
export function useMapConfig() {
    return useQuery({
        queryKey: ['map-config'],
        queryFn: async (): Promise<MapConfig> => {
            const { data } = await apiClient.get<{ data: MapConfig }>('/map-config');
            return data.data;
        },
        staleTime: Infinity,
    });
}

export interface GeocodeResult {
    lat: number;
    lng: number;
    label: string;
}

/** Geocode an address to coordinates (server-side proxy); null when nothing matched. */
export function useGeocode() {
    return useMutation({
        mutationFn: async (q: string): Promise<GeocodeResult | null> => {
            const { data } = await apiClient.get<{ data: GeocodeResult | null }>('/geocode', { params: { q } });
            return data.data;
        },
    });
}
