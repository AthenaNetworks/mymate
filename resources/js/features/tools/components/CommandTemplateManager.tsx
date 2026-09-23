import { useState } from 'react';
import { FloppyDisk, PencilSimple, Trash, X } from '@phosphor-icons/react';
import { useCommandTemplates, useCreateCommandTemplate, useDeleteCommandTemplate, useUpdateCommandTemplate, type CommandTemplate } from '../api/commandTemplates';

const field = 'rounded-lg border border-white/10 bg-black/20 px-3 py-2 text-sm text-white outline-none placeholder:text-white/25 focus:border-emerald-400/50';

type Draft = { name: string; command: string; timeout: number; enabled: boolean };
const blank: Draft = { name: '', command: '', timeout: 30, enabled: true };

export function CommandTemplateManager() {
    const templates = useCommandTemplates(true);
    const create = useCreateCommandTemplate();
    const update = useUpdateCommandTemplate();
    const remove = useDeleteCommandTemplate();
    const [draft, setDraft] = useState<Draft>(blank);
    const [editing, setEditing] = useState<CommandTemplate | null>(null);

    const save = () => {
        const input = { ...draft, name: draft.name.trim(), command: draft.command.trim(), timeout: Math.max(1, Math.min(60, draft.timeout)) };
        if (!input.name || !input.command) return;
        if (editing) {
            update.mutate({ id: editing.id, ...input }, { onSuccess: () => { setDraft(blank); setEditing(null); } });
        } else {
            create.mutate(input, { onSuccess: () => { setDraft(blank); setEditing(null); } });
        }
    };

    const edit = (template: CommandTemplate) => {
        setEditing(template);
        setDraft({ name: template.name, command: template.command, timeout: template.timeout, enabled: template.enabled });
    };

    return (
        <section className="rounded-xl border border-white/[0.08] bg-white/[0.025] p-5">
            <div className="mb-4 flex items-center justify-between">
                <div><h3 className="font-semibold text-white">Command templates</h3><p className="text-xs text-white/40">Save reviewed, non-interactive commands for quick reuse.</p></div>
                {editing && <button onClick={() => { setEditing(null); setDraft(blank); }} className="inline-flex items-center gap-1 text-xs text-white/50 hover:text-white"><X className="h-4 w-4" /> Cancel</button>}
            </div>
            <div className="grid gap-2 md:grid-cols-[1fr_2fr_auto]">
                <input value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} maxLength={120} className={field} placeholder="Template name" />
                <input value={draft.command} onChange={(e) => setDraft({ ...draft, command: e.target.value })} maxLength={4096} className={`${field} font-mono`} placeholder="/system identity print" />
                <button onClick={save} disabled={!draft.name.trim() || !draft.command.trim() || create.isPending || update.isPending} className="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-400 px-3 py-2 text-sm font-semibold text-emerald-950 disabled:cursor-not-allowed disabled:opacity-35"><FloppyDisk className="h-4 w-4" /> {editing ? 'Save' : 'Add'}</button>
            </div>
            <div className="mt-3 space-y-2">
                {(templates.data ?? []).map((template) => <div key={template.id} className="flex items-center gap-3 rounded-lg border border-white/[0.06] px-3 py-2 text-sm">
                    <span className={`h-2 w-2 rounded-full ${template.enabled ? 'bg-emerald-400' : 'bg-white/20'}`} />
                    <span className="font-medium text-white">{template.name}</span><code className="min-w-0 flex-1 truncate text-xs text-white/45">{template.command}</code>
                    <button onClick={() => edit(template)} className="text-white/45 hover:text-white" title="Edit"><PencilSimple className="h-4 w-4" /></button>
                    <button onClick={() => remove.mutate(template.id)} className="text-rose-300/60 hover:text-rose-300" title="Delete"><Trash className="h-4 w-4" /></button>
                </div>)}
                {!templates.isLoading && (templates.data ?? []).length === 0 && <p className="text-xs text-white/35">No templates saved yet.</p>}
            </div>
        </section>
    );
}
