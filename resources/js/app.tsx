import '@fontsource-variable/plus-jakarta-sans';
import { StrictMode, useEffect, useRef } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from './lib/queryClient';
import { AppShell } from './features/shell/AppShell';
import { LoginScreen } from './features/auth/components/LoginScreen';
import { useCurrentUser, useLogin } from './features/auth/api/auth';
import { Toaster } from './components/Toaster';
import { BrandedLoader } from './components/BrandedLoader';
import { useWallboard } from './lib/shellStore';
import { isDemo, demoCreds } from './features/demo/lib/demo';
import { DemoChrome } from './features/demo/components/DemoChrome';

/**
 * Gate the console behind authentication: login screen until signed in.
 * In demo mode (sales site) the read-only viewer is auto-logged-in and the marketing
 * chrome is layered over the live, synthetic-data console.
 */
function Root() {
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
