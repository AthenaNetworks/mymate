/** The device's own web interface. An IPv6 address needs brackets in a URL. */
export function webUrl(ip: string): string {
    return ip.includes(':') ? `http://[${ip}]/` : `http://${ip}/`;
}
