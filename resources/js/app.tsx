import '@fontsource-variable/plus-jakarta-sans';
import { StrictMode, useEffect, useRef } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from './lib/queryClient';
import { AppShell } from './features/shell/AppShell';
import { LoginScreen } from './features/auth/components/LoginScreen';
import { PasskeyGate } from './features/auth/components/PasskeyGate';
import { useCurrentUser, useLogin } from './features/auth/api/auth';
import { Toaster } from './components/Toaster';
import { BrandedLoader } from './components/BrandedLoader';
import { useWallboard } from './lib/shellStore';
import { isDemo, demoCreds } from './features/demo/lib/demo';
import { DemoChrome } from './features/demo/components/DemoChrome';
import { isWall } from './lib/wall';
import { PublicWallboard } from './features/wall/components/PublicWallboard';

/**
 * Gate the console behind authentication: login screen until signed in.
 * In demo mode (sales site) the read-only viewer is auto-logged-in and the marketing
 * chrome is layered over the live, synthetic-data console.
 */
function Root() {
    // Public wallboard (GitHub #15): opened via a /wall/{token} share link. It bypasses auth
    // entirely and renders its own read-only view on token-gated data, so short-circuit before
    // any authenticated query runs.
    if (isWall()) {
        return <PublicWallboard />;
    }

    return <AuthedApp />;
}

function AuthedApp() {
    const { data: user, isLoading } = useCurrentUser();
    const login = useLogin();
    const wallboard = useWallboard();
    const demo = isDemo();
    const triedDemoLogin = useRef(false);

    // Demo: sign the public viewer in automatically so there's no login screen.
    useEffect(() => {
        if (!demo || isLoading || user || triedDemoLogin.current || login.isPending) return;
        const creds = demoCreds();
        if (creds) {
            triedDemoLogin.current = true;
            login.mutate(creds);
        }
    }, [demo, isLoading, user, login]);

    if (isLoading || (demo && !user)) {
        return <BrandedLoader />;
    }

    if (!user) {
        return <LoginScreen />;
    }

    // Passkey second factor / forced enrolment after a password login. Skipped in demo - the demo
    // viewer must never be bounced into a WebAuthn ceremony.
    if (!demo && (user.passkey_stage === 'challenge' || user.passkey_stage === 'enrol')) {
        return <PasskeyGate stage={user.passkey_stage} />;
    }

    // Demo (not wallboard): reserve a top strip for the marketing bar + overlay content.
    if (demo && !wallboard) {
        return (
            <div className="pt-[var(--app-inset-top)]" style={{ ['--app-inset-top' as string]: '3rem' }}>
                <DemoChrome />
                <AppShell />
            </div>
        );
    }

    return <AppShell />;
}

import { initTheme } from './lib/theme';

initTheme(); // apply the saved light/dark choice before first paint (GitHub #34)

const el = document.getElementById('app');
if (el) {
    createRoot(el).render(
        <StrictMode>
            <QueryClientProvider client={queryClient}>
                <Root />
                <Toaster />
            </QueryClientProvider>
        </StrictMode>,
    );
}
