// Side-effect import of the bundled variable font (CSS-only package).
declare module '@fontsource-variable/plus-jakarta-sans';

// Reverb client env (mirrors of the backend REVERB_* keys, set in .env).
interface ImportMetaEnv {
    readonly VITE_REVERB_APP_KEY: string;
    readonly VITE_REVERB_HOST: string;
    readonly VITE_REVERB_PORT: string;
    readonly VITE_REVERB_SCHEME: string;
}
