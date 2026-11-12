// Single sign-on (OpenID Connect - Entra ID first). The page shell emits <meta name="mymate:sso">
// when an admin has turned it on (a meta tag, not an inline script - the CSP forbids those), and
// the server sends a failed sign-in back to the login page as `?sso_error=<reason>`.

/** Where "Login with SSO" goes - a full-page navigation to the provider, not an API call. */
export const SSO_LOGIN_URL = '/auth/sso/redirect';

export function ssoEnabled(): boolean {
    if (typeof document === 'undefined') return false;
    return document.querySelector('meta[name="mymate:sso"]')?.getAttribute('content') === '1';
}

const MESSAGES: Record<string, string> = {
    no_role: "Your account isn't assigned a My Mate role. Ask an administrator to give you access.",
    disabled: 'This account has been disabled by an administrator.',
    email_conflict: 'That email address is already linked to a different sign-in. Ask an administrator.',
    no_email: "Your sign-in didn't include an email address, so no account could be matched or created.",
    expired: 'The sign-in took too long or was started in another browser. Please try again.',
    provider_denied: "The sign-in wasn't completed at your identity provider.",
    provider_unreachable: "Couldn't reach the identity provider. Try again shortly.",
};

/**
 * The reason a single sign-on just failed, as a readable message (or null), read once from the
 * URL. The query parameter is then removed so a reload doesn't show it again.
 */
export function takeSsoError(): string | null {
    if (typeof window === 'undefined') return null;
    const params = new URLSearchParams(window.location.search);
    const reason = params.get('sso_error');
    if (reason === null) return null;
    params.delete('sso_error');
    const qs = params.toString();
    window.history.replaceState(null, '', window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash);
    return MESSAGES[reason] ?? 'Single sign-on failed. If it keeps happening, ask an administrator to check the SSO settings.';
}
