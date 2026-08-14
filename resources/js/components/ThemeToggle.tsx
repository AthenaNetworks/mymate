import { Moon, Sun } from '@phosphor-icons/react';
import { toggleTheme, useTheme } from '../lib/theme';

/** Light/dark toggle (GitHub #34). Shown in the top bar and on the public wallboard, so the
 *  theme can be flipped from anywhere - including a no-login NOC screen. */
export function ThemeToggle({ className }: { className?: string }) {
    const theme = useTheme();
    return (
        <button
            onClick={toggleTheme}
            title={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
            aria-label="Toggle light or dark mode"
            className={
                className ??
                'rounded-lg p-1.5 text-white/45 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80'
            }
        >
            {theme === 'dark' ? <Sun weight="bold" className="h-4 w-4" /> : <Moon weight="bold" className="h-4 w-4" />}
        </button>
    );
}
