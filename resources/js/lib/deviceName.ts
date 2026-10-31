/** The name to show for a device: its friendly alias when set (GitHub #53), else the hostname. */
export function displayName(d: { name: string; alias?: string | null }): string {
    return d.alias?.trim() || d.name;
}
