import { memo, type MouseEvent, type ReactNode } from 'react';
import Markdown, { type Components } from 'react-markdown';
import remarkBreaks from 'remark-breaks';
import remarkGfm from 'remark-gfm';
import { isWall } from '../../../lib/wall';

/** Ordinary links: opened in a new tab. */
const WEB_SCHEMES = new Set(['http', 'https', 'mailto']);
/**
 * Links that hand off to a local tool (Winbox, an SSH/telnet client, Remote Desktop), so a note
 * can jump straight to the gear it describes. Opened in the same tab: a new one would only sit
 * blank while the OS launches the app. Kept to tools that open a session - nothing that runs a
 * file. Adding one here is all it takes to allow it.
 */
const APP_SCHEMES = new Set(['winbox', 'ssh', 'telnet', 'rdp']);

/**
 * The href a note link may use, or null to show it as plain text. Only absolute URLs on the
 * allow-lists above get through - no `javascript:`/`data:`, and no relative links. The public
 * wallboard (no login) gets web links only: its viewers aren't the ones managing the gear.
 */
export function noteLinkHref(url: string, allowAppLinks: boolean): string | null {
    const href = url.trim();
    const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(href)?.[1]?.toLowerCase();
    if (scheme === undefined) return null;
    if (WEB_SCHEMES.has(scheme) || (allowAppLinks && APP_SCHEMES.has(scheme))) return href;
    return null;
}

function isWebLink(href: string): boolean {
    return /^https?:/i.test(href);
}

// A click on a link opens it - it mustn't also select the note, and a double-click mustn't open
// the editor. `nodrag nopan` keeps React Flow from starting a drag/pan on mousedown.
const stop = (e: MouseEvent) => e.stopPropagation();

function NoteLink({ href, children }: { href?: string; children?: ReactNode }) {
    if (!href) return <span>{children}</span>; // a link we don't allow reads as its text
    const web = isWebLink(href);
    return (
        <a
            href={href}
            target={web ? '_blank' : undefined}
            rel={web ? 'noopener noreferrer' : undefined}
            title={href}
            onClick={stop}
            onDoubleClick={stop}
            className="nodrag nopan cursor-pointer font-semibold underline decoration-current/50 underline-offset-2 hover:decoration-current"
        >
            {children}
        </a>
    );
}

// Spacing collapses at the note's edges so a one-line note is as compact as plain text was.
const block = 'my-1 first:mt-0 last:mb-0';

// Colours are all derived from the text colour (`current`), so formatting reads on the theme
// default and on any custom text/background colour a note is given. Sizes are in em, so they
// follow the note's S/M/L preset.
const COMPONENTS: Components = {
    p: ({ children }) => <p className={block}>{children}</p>,
    h1: ({ children }) => <h1 className={`${block} text-[1.3em] font-bold leading-tight`}>{children}</h1>,
    h2: ({ children }) => <h2 className={`${block} text-[1.15em] font-bold leading-tight`}>{children}</h2>,
    h3: ({ children }) => <h3 className={`${block} font-bold leading-tight`}>{children}</h3>,
    h4: ({ children }) => <h4 className={`${block} font-bold leading-tight`}>{children}</h4>,
    h5: ({ children }) => <h5 className={`${block} font-bold leading-tight`}>{children}</h5>,
    h6: ({ children }) => <h6 className={`${block} font-bold leading-tight`}>{children}</h6>,
    ul: ({ children }) => <ul className={`${block} list-disc pl-4`}>{children}</ul>,
    ol: ({ children }) => <ol className={`${block} list-decimal pl-4`}>{children}</ol>,
    li: ({ children }) => <li className="my-0.5">{children}</li>,
    blockquote: ({ children }) => <blockquote className={`${block} border-l-2 border-current/30 pl-2 opacity-80`}>{children}</blockquote>,
    hr: () => <hr className="my-1.5 border-current/25" />,
    pre: ({ children }) => (
        <pre className={`${block} overflow-x-auto rounded bg-current/10 p-1.5 font-mono text-[0.85em] [&_code]:bg-transparent [&_code]:p-0`}>{children}</pre>
    ),
    code: ({ children }) => <code className="rounded bg-current/10 px-1 font-mono text-[0.9em]">{children}</code>,
    a: ({ href, children }) => <NoteLink href={href}>{children}</NoteLink>,
    // Never loaded: it would make every wallboard viewer fetch an outside URL, and the image
    // library covers pictures on a map. The alt text stands in, so the note still says something.
    img: ({ alt }) => (alt ? <span className="italic opacity-80">{alt}</span> : null),
};

/**
 * What a note may contain. Anything else is dropped but keeps its text (`unwrapDisallowed`):
 * tables (no room in a 16rem note) and GFM task-list checkboxes. Images are allowed only so the
 * `img` component above can swap them for their alt text. Raw HTML is never rendered
 * (`skipHtml`).
 */
const ALLOWED = ['p', 'br', 'strong', 'em', 'del', 'code', 'pre', 'a', 'img', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'hr'];

// GFM for bare-URL autolinks and ~~strikethrough~~; breaks so a single newline stays a line
// break, as it was when notes were plain text.
const REMARK_PLUGINS = [remarkGfm, remarkBreaks];

/** A map note's text rendered as (a safe subset of) Markdown. */
export const NoteMarkdown = memo(function NoteMarkdown({ text }: { text: string }) {
    const allowAppLinks = !isWall();
    return (
        <Markdown
            remarkPlugins={REMARK_PLUGINS}
            allowedElements={ALLOWED}
            unwrapDisallowed
            skipHtml
            urlTransform={(url) => noteLinkHref(url, allowAppLinks)}
            components={COMPONENTS}
        >
            {text}
        </Markdown>
    );
});
