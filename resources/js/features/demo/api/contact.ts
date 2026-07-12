import { useMutation } from '@tanstack/react-query';
import { apiClient, fetchCsrfCookie } from '../../../lib/apiClient';

export type ContactInput = { name: string; email: string; company?: string; message: string };

/** Submit the public "contact sales" form. */
export function useContact() {
    return useMutation({
        mutationFn: async (input: ContactInput): Promise<void> => {
            await fetchCsrfCookie(); // prime XSRF-TOKEN for the stateful API
            await apiClient.post('/contact', input);
        },
    });
}
