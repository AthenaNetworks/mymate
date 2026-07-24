// Public wallboard mode (GitHub #15). The /wall/{token} web route emits these meta tags (an
// inline <script> would be blocked by the CSP `script-src 'self'`, same as demo mode), so the
// SPA can boot straight into a read-only, no-login view of one map. Everything here is a no-op
// on a normal authenticated page - the tags are only present on the /wall/{token} route.

function meta(name: string): string | null {
    if (typeof document === 'undefined') return null;
    return document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') ?? null;
}

/** The share token this page was opened with, or null on any normal page. */
export function wallToken(): string | null {
    return meta('mymate:wall-token');
}

/** The map id this public wallboard is scoped to. */
export function wallMapId(): number | null {
    const raw = meta('mymate:wall-map');
    return raw ? Number(raw) : null;
}

/** Whether the SPA is running as a public, read-only wallboard. */
export function isWall(): boolean {
    return wallToken() !== null;
}
