// Demo mode (customer-facing sales site). The blade shell emits meta tags (not an
// inline script - the CSP `script-src 'self'` blocks inline scripts) only when
// MYMATE_DEMO is on; everything here is a no-op on a real monitoring instance.

function meta(name: string): string | null {
    if (typeof document === 'undefined') return null;
    return document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') ?? null;
}

export function isDemo(): boolean {
    return meta('mymate:demo') === '1';
}

/** The public read-only viewer credentials the SPA auto-logs-in with (demo only). */
export function demoCreds(): { email: string; password: string } | null {
    const email = meta('mymate:demo-email');
    const password = meta('mymate:demo-password');
    return isDemo() && email && password ? { email, password } : null;
}

// --- Marketing content (edit freely) -------------------------------------
// External links - point these at the real marketing site / contact once it exists.
export const SITE_URL = 'https://mymate.as135559.net.au';
export const CTA_URL = 'mailto:sales@athenanetworks.com.au?subject=My%20Mate';
export const CONTACT_EMAIL = 'sales@athenanetworks.com.au';

export type PanelKey = 'features' | 'get' | 'contact';

// Plain header links. "Get My Mate" is its own emphasised CTA button (opens the 'get' panel).
export const NAV: { key: PanelKey; label: string }[] = [
    { key: 'features', label: 'Features' },
    { key: 'get', label: 'Get it' },
    { key: 'contact', label: 'Contact' },
];

// Project links.
export const GITHUB_URL = 'https://github.com/AthenaNetworks/mymate';
export const RELEASES_URL = 'https://github.com/AthenaNetworks/mymate/releases/latest';
export const DOCKER_URL = 'https://hub.docker.com/r/athenanetworks/mymate';
export const DOCS_URL = 'https://github.com/AthenaNetworks/mymate#readme';

// Advertised on the Get My Mate page - free and open source, live on GitHub.
export const PLANS = {
    heading: 'Free and open source',
    body: 'My Mate is on GitHub now - MIT licensed, no device caps, nothing locked behind a paywall. Grab a ready-to-run package from the latest release, pull the Docker image, or build it from source.',
    tiers: [
        { name: 'Free forever', devices: 'Unlimited devices', note: 'No license, no cap.' },
        { name: 'Open source', devices: 'MIT-licensed', note: 'On GitHub now.' },
    ],
};

// Deployment methods - all shipped with the release.
export const PACKAGES: { name: string; badge: string; blurb: string }[] = [
    {
        name: 'Debian / Ubuntu package',
        badge: 'Debian 13 - Ubuntu 22.04+',
        blurb: 'One self-contained .deb installs everything - PostgreSQL, Redis, nginx, the web app and the monitoring engine - and provisions itself.',
    },
    {
        name: 'Docker',
        badge: 'athenanetworks/mymate',
        blurb: 'Pull the image and run it, or use the compose file to bring up the app with Postgres and Redis in one command.',
    },
    {
        name: 'Proxmox / LXC template',
        badge: 'Proxmox VE',
        blurb: 'A ready-to-run container template. Drop it into your Proxmox store, start a container, and browse to it - it self-configures on first boot.',
    },
    {
        name: 'On-prem or hosted SaaS',
        badge: 'Your infrastructure, or ours',
        blurb: 'Run it inside your network with direct access to your management LAN - or let us host it, with a lightweight agent at each site connecting out over a secure tunnel so your management network is never exposed.',
    },
];

export const WELCOME = {
    badge: 'Live interactive demo',
    title: 'Network monitoring, the modern way.',
    body:
        'My Mate is a live network monitor - a modern, open-source replacement for MikroTik\'s "The Dude". It maps your whole network, pings every device for up/down, and polls interface throughput plus device health (CPU, memory, temperature, wireless) over SNMP or the RouterOS API - colouring each link green->red by utilisation in real time, with alerting, history, config backups, RouterOS upgrades, auto-discovery and multi-site maps.',
    demoNote:
        'You\'re looking at the real console, running on 100% synthetic data. Pan the map, click a device or link, open the charts, watch CPU and memory move, or try wallboard mode.',
    cta: 'Explore the demo',
};

export const FEATURES: { title: string; body: string }[] = [
    { title: 'Live topology map', body: 'Interface-to-interface links on a React-Flow canvas, laid out automatically and coloured green->red by utilisation in real time - tag links by medium (fibre, ethernet, wireless) to read your plant at a glance.' },
    { title: 'Overview maps & maps-of-maps', body: 'Build a top-level view out of your other maps: drop a map on as a node, link sites together by hand, and drill straight in with a click - no device required.' },
    { title: 'Device health, live', body: 'CPU, memory, temperature and wireless RF (signal, SNR, CCQ, client count) over SNMP or the RouterOS API - shown on every node and graphed over time.' },
    { title: 'Up/down + throughput', body: 'Fast fping up/down plus SNMP or RouterOS API throughput - status and load update live over WebSockets, with per-device latency and packet-loss tracking.' },
    { title: 'Auto-discovery', body: 'Scan a subnet, probe each responder against your SNMP, RouterOS and SSH credentials in one pass, and promote candidates to monitored devices in a click.' },
    { title: 'Config backups', body: 'Versioned device config backups over SSH, stored in git - see exactly what changed and diff any two versions.' },
    { title: 'RouterOS upgrades', body: 'Upgrade a whole fleet in dependency order - furthest device first, so you never cut off the boxes behind it - with live per-device status.' },
    { title: 'Alerting that isn\'t noisy', body: 'Device-down, sustained high-utilisation, and upgrade-failed policies with dependency-aware suppression, recovery alerts, and Slack/Teams/email/webhook delivery.' },
    { title: 'On-prem or SaaS, with site agents', body: 'Run it in your network with direct access to your management LAN, or let us host it - a lightweight agent at each site polls locally (SNMP v1/v2c/v3 or RouterOS) and streams back over a secure outbound tunnel, so your management network is never exposed.' },
    { title: 'Custom icons & product photos', body: 'Devices show real vendor product photos (auto-fetched for MikroTik) or a role icon you pick and colour - so the map looks like your rack, not a sea of boxes.' },
    { title: 'Import from The Dude', body: 'Upload a MikroTik dude.db and bring your devices, links, maps and history straight across - merge or start fresh.' },
    { title: 'Wallboard & dashboard', body: 'A chrome-free TV mode and an auto-paging device dashboard that lights up outages - built for the NOC big screen.' },
];

export const CONTACT = {
    title: 'Get in touch',
    body: 'Want My Mate for your network? Tell us a little about yourself and we\'ll be in touch.',
    success: 'Thanks - we\'ll be in touch shortly.',
};

