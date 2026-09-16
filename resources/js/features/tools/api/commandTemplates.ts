import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export interface CommandTemplate {
    id: number;
    name: string;
    command: string;
    timeout: number;
    enabled: boolean;
    command_hash: string;
    updated_at: string | null;
}

type TemplateInput = { name: string; command: string; timeout: number; enabled?: boolean };

export function useCommandTemplates(enabled = true) {
    return useQuery({
        queryKey: ['command-templates'],
        queryFn: async (): Promise<CommandTemplate[]> => {
            const { data } = await apiClient.get<{ templates: CommandTemplate[] }>('/command-templates');
            return data.templates;
        },
        enabled,
    });
}

export function useCreateCommandTemplate() {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (input: TemplateInput) => (await apiClient.post<{ template: CommandTemplate }>('/command-templates', input)).data.template,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['command-templates'] }),
    });
}

export function useUpdateCommandTemplate() {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, ...input }: TemplateInput & { id: number }) => (await apiClient.put<{ template: CommandTemplate }>(`/command-templates/${id}`, input)).data.template,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['command-templates'] }),
    });
}

export function useDeleteCommandTemplate() {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: number) => { await apiClient.delete(`/command-templates/${id}`); },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['command-templates'] }),
    });
}
