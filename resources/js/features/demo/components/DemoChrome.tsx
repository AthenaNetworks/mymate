import { useState, type ChangeEvent } from 'react';
import { createPortal } from 'react-dom';
import { X, ArrowRight, CheckCircle, Cube, Package, CloudArrowUp, Stack } from '@phosphor-icons/react';
import { NAV, WELCOME, FEATURES, CONTACT, PLANS, PACKAGES, GITHUB_URL, RELEASES_URL, DOCKER_URL, DOCS_URL, type PanelKey } from '../lib/demo';
import { useContact } from '../api/contact';

type Open = PanelKey | 'welcome' | null;

/** Marketing chrome shown over the live demo: a top nav with website links + dismissible
 *  content popups over the map. Closing any popup drops the visitor back into the app. */
export function DemoChrome() {
    const [open, setOpen] = useState<Open>('welcome');

    return (
        <>
            {/* Top marketing bar - sits in the reserved inset above the app console. */}
            <header className="fixed inset-x-0 top-0 z-40 flex h-12 items-center gap-3 border-b border-white/10 bg-[#0b0b0f]/85 px-4 backdrop-blur-xl">
                <span className="flex items-center gap-2 font-bold tracking-tight text-white">
                    <span className="grid h-6 w-6 place-items-center rounded-md bg-emerald-500/20 text-[11px] text-emerald-300 ring-1 ring-emerald-400/30">M</span>
                    My Mate
                </span>
                <span className="hidden rounded-full bg-rose-500/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-300 ring-1 ring-rose-400/25 sm:inline">
                    ● Live demo
                </span>

                <nav className="ml-auto flex items-center gap-1 text-sm">
                    {NAV.map((n) => (
                        <button
                            key={n.key}
                            onClick={() => setOpen(n.key)}
                            className="rounded-lg px-3 py-1.5 text-white/60 transition-colors duration-200 hover:bg-white/5 hover:text-white/90"
                        >
                            {n.label}
                        </button>
                    ))}
                    <button
                        onClick={() => setOpen('get')}
                        className="ml-1 flex items-center gap-1.5 rounded-lg bg-emerald-500/90 px-3 py-1.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400"
                    >
                        Get My Mate <ArrowRight weight="bold" className="h-3.5 w-3.5" />
                    </button>
                </nav>
            </header>

            {open && <Popup which={open} onClose={() => setOpen(null)} onNav={setOpen} />}
        </>
    );
}

function Popup({ which, onClose, onNav }: { which: Open; onClose: () => void; onNav: (o: Open) => void }) {
    return createPortal(
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="animate-rise relative w-full max-w-2xl rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="relative max-h-[80vh] overflow-y-auto rounded-[calc(1.5rem-0.25rem)] bg-[#0d0d11] p-7 ring-1 ring-white/10">
                    <button
                        onClick={onClose}
                        aria-label="Close"
                        className="absolute right-4 top-4 z-10 rounded-lg p-1 text-white/40 transition-colors duration-300 hover:bg-white/5 hover:text-white/80"
                    >
                        <X weight="bold" className="h-5 w-5" />
                    </button>

                    {which === 'welcome' && <Welcome onClose={onClose} onNav={onNav} />}
                    {which === 'features' && <Features />}
                    {which === 'get' && <GetMyMate onNav={onNav} />}
                    {which === 'contact' && <Contact />}
                </div>
            </div>
        </div>,
        document.body,
    );
}

function Welcome({ onClose, onNav }: { onClose: () => void; onNav: (o: Open) => void }) {
    return (
        <div className="text-center">
            <span className="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-300/80">{WELCOME.badge}</span>
            <h1 className="mt-3 text-3xl font-extrabold tracking-tight text-white">{WELCOME.title}</h1>
            <p className="mx-auto mt-3 max-w-lg text-sm leading-relaxed text-white/60">{WELCOME.body}</p>
            <p className="mx-auto mt-3 max-w-lg text-xs leading-relaxed text-white/40">{WELCOME.demoNote}</p>
            <div className="mt-6 flex flex-wrap items-center justify-center gap-2.5">
                <button
                    onClick={onClose}
                    className="inline-flex items-center gap-2 rounded-xl bg-emerald-500/90 px-5 py-2.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400"
                >
                    {WELCOME.cta} <ArrowRight weight="bold" className="h-4 w-4" />
                </button>
                <button
                    onClick={() => onNav('features')}
                    className="rounded-xl bg-white/[0.06] px-4 py-2.5 text-sm font-semibold text-white/80 ring-1 ring-white/10 transition hover:bg-white/10"
                >
                    See features
                </button>
                <button
                    onClick={() => onNav('get')}
                    className="rounded-xl bg-white/[0.06] px-4 py-2.5 text-sm font-semibold text-white/80 ring-1 ring-white/10 transition hover:bg-white/10"
                >
                    Get My Mate
                </button>
            </div>
        </div>
    );
}

function Features() {
    return (
        <div>
            <h2 className="text-2xl font-bold tracking-tight text-white">Everything you need to watch your network</h2>
            <div className="mt-5 grid gap-3 sm:grid-cols-2">
                {FEATURES.map((f) => (
                    <div key={f.title} className="rounded-2xl bg-white/[0.03] p-4 ring-1 ring-white/[0.06]">
                        <h3 className="text-sm font-semibold text-white">{f.title}</h3>
                        <p className="mt-1 text-xs leading-relaxed text-white/50">{f.body}</p>
                    </div>
                ))}
            </div>
        </div>
    );
}

const PKG_ICON = [Package, Stack, Cube, CloudArrowUp];

function GetMyMate({ onNav }: { onNav: (o: Open) => void }) {
    return (
        <div>
            {/* Open source, live on GitHub */}
            <div className="rounded-2xl bg-emerald-500/[0.08] p-5 text-center ring-1 ring-emerald-400/20">
                <span className="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-300/80">Free &amp; open source</span>
                <h2 className="mt-2 text-2xl font-bold tracking-tight text-white">{PLANS.heading}</h2>
                <p className="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-white/60">{PLANS.body}</p>
                <div className="mt-5 flex flex-wrap items-center justify-center gap-2.5">
                    <a
                        href={GITHUB_URL}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-2 rounded-xl bg-emerald-500/90 px-5 py-2.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400"
                    >
                        View on GitHub <ArrowRight weight="bold" className="h-4 w-4" />
                    </a>
                    <a
                        href={RELEASES_URL}
                        target="_blank"
                        rel="noreferrer"
                        className="rounded-xl bg-white/[0.06] px-4 py-2.5 text-sm font-semibold text-white/80 ring-1 ring-white/10 transition hover:bg-white/10"
                    >
                        Latest release
                    </a>
                    <a
                        href={DOCS_URL}
                        target="_blank"
                        rel="noreferrer"
                        className="rounded-xl bg-white/[0.06] px-4 py-2.5 text-sm font-semibold text-white/80 ring-1 ring-white/10 transition hover:bg-white/10"
                    >
                        Documentation
                    </a>
                </div>
            </div>

            {/* Deployment methods - all ship with the release */}
            <h3 className="mt-6 text-sm font-bold uppercase tracking-wide text-white/40">Deploy it your way</h3>
            <div className="mt-3 space-y-3">
                {PACKAGES.map((p, i) => {
                    const Icon = PKG_ICON[i] ?? Package;
                    return (
                        <div key={p.name} className="flex items-start gap-3 rounded-2xl bg-white/[0.03] p-4 ring-1 ring-white/[0.06]">
                            <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                                <Icon weight="light" className="h-5 w-5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h4 className="text-sm font-semibold text-white">{p.name}</h4>
                                    <span className="rounded-full bg-white/[0.06] px-2 py-0.5 text-[10px] font-medium text-white/50 ring-1 ring-white/10">{p.badge}</span>
                                </div>
                                <p className="mt-1 text-xs leading-relaxed text-white/50">{p.blurb}</p>
                            </div>
                        </div>
                    );
                })}
            </div>

            <p className="mt-5 text-center text-xs text-white/40">
                MIT-licensed - on{' '}
                <a href={GITHUB_URL} target="_blank" rel="noreferrer" className="font-semibold text-emerald-300/90 underline-offset-2 hover:underline">
                    GitHub
                </a>{' '}
                and{' '}
                <a href={DOCKER_URL} target="_blank" rel="noreferrer" className="font-semibold text-emerald-300/90 underline-offset-2 hover:underline">
                    Docker Hub
                </a>{' '}
                -{' '}
                <button onClick={() => onNav('contact')} className="font-semibold text-emerald-300/90 underline-offset-2 hover:underline">
                    Get in touch
                </button>
            </p>
        </div>
    );
}

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

function Contact() {
    const contact = useContact();
    const [form, setForm] = useState({ name: '', email: '', company: '', message: '' });
    const set = (k: keyof typeof form) => (e: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
        setForm((f) => ({ ...f, [k]: e.target.value }));
    const ready = form.name.trim() !== '' && form.email.trim() !== '' && form.message.trim() !== '' && !contact.isPending;

    if (contact.isSuccess) {
        return (
            <div className="py-6 text-center">
                <CheckCircle weight="fill" className="mx-auto h-10 w-10 text-emerald-400" />
                <p className="mt-3 text-sm text-white/70">{CONTACT.success}</p>
            </div>
        );
    }

    return (
        <div>
            <h2 className="text-2xl font-bold tracking-tight text-white">{CONTACT.title}</h2>
            <p className="mt-2 text-sm leading-relaxed text-white/55">{CONTACT.body}</p>
            <form
                className="mt-5 space-y-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (ready) contact.mutate(form);
                }}
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <input className={field} placeholder="Name" value={form.name} onChange={set('name')} required />
                    <input className={field} type="email" placeholder="Work email" value={form.email} onChange={set('email')} required />
                </div>
                <input className={field} placeholder="Company (optional)" value={form.company} onChange={set('company')} />
                <textarea className={field} rows={4} placeholder="How can we help?" value={form.message} onChange={set('message')} required />
                {contact.isError && <p className="text-xs text-rose-400/90">Sorry - that didn't send. Please try again.</p>}
                <button
                    type="submit"
                    disabled={!ready}
                    className="inline-flex items-center gap-2 rounded-xl bg-emerald-500/90 px-5 py-2.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    {contact.isPending ? 'Sending...' : 'Send'} <ArrowRight weight="bold" className="h-4 w-4" />
                </button>
            </form>
        </div>
    );
}
