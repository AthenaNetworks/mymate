import { useEffect, useState } from 'react';
import { FrameCorners } from '@phosphor-icons/react';
import { useUpdateWallEmbedSettings, useWallEmbedSettings } from '../api/wallEmbed';
import { pushToast } from '../../../lib/toast';

const card = 'min-w-0 rounded-2xl bg-white/[0.02] p-5 ring-1 ring-white/[0.06]';
const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 font-mono text-xs text-white ring-1 ring-white/10 outline-none ' +
    'placeholder:text-white/25 focus:ring-2 focus:ring-emerald-400/60';

const split = (text: string) => text.split(/[\s,]+/).map((o) => o.trim()).filter(Boolean);

/**
 * Where the public wallboard may be embedded in an iframe (GitHub #15). One origin per line; empty
 * means nobody can frame it, which is the default. Only the /wall/{token} share page is affected -
 * the console itself can never be framed, whatever is listed here.
 */
export function WallEmbedSection() {
    const { data } = useWallEmbedSettings();
    const update = useUpdateWallEmbedSettings();
    const [text, setText] = useState('');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (data) setText(data.frame_ancestors.join('\n'));
    }, [data]);

    function save() {
        setError(null);
        update.mutate(split(text), {
            onSuccess: (d) => pushToast({ title: d.frame_ancestors.length ? 'Wallboard embedding allowed for those sites' : 'Wallboard embedding turned off', tone: 'info' }),
            onError: (e: unknown) => {
                const errors = (e as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
                setError(errors ? (Object.values(errors)[0]?.[0] ?? 'Check the list') : "Couldn't save");
            },
        });
    }

    const dirty = data ? split(text).join('\n') !== data.frame_ancestors.join('\n') : false;

    return (
        <section className={card}>
            <div className="mb-4 flex items-center gap-2">
                <FrameCorners weight="light" className="h-4 w-4 text-white/40" />
                <div>
                    <h2 className="text-sm font-bold text-white">Wallboard embedding</h2>
                    <p className="text-xs text-white/40">Sites allowed to show a shared wallboard link inside an iframe, eg an intranet or a dashboard.</p>
                </div>
            </div>

            <textarea
                rows={4}
                value={text}
                onChange={(e) => setText(e.target.value)}
                placeholder={'https://intranet.example.com\nhttps://*.dashboards.example.com'}
                spellCheck={false}
                className={field}
            />
            {error && <p className="mt-2 text-xs text-rose-300">{error}</p>}
            <p className="mt-2 text-[11px] leading-relaxed text-white/40">
                One per line, as scheme://host[:port] with no path. A leading *. matches any subdomain of that
                site. Leave it empty to block embedding (the default). This only ever applies to public
                wallboard links - the console itself can't be put in a frame.
            </p>

            <div className="mt-3 flex justify-end">
                <button
                    disabled={update.isPending || !dirty}
                    onClick={save}
                    className="rounded-xl bg-emerald-500/15 px-3 py-1.5 text-sm font-medium text-emerald-200 ring-1 ring-emerald-400/25 transition-colors hover:bg-emerald-500/25 disabled:opacity-40"
                >
                    {update.isPending ? 'Saving...' : 'Save'}
                </button>
            </div>
        </section>
    );
}
