// Browser side of the WebAuthn ceremony. The server (laravel/passkeys) generates the options and
// does all the crypto/verification; here we only translate between its base64url JSON and the
// native PublicKeyCredential objects and call navigator.credentials. Kept dependency-free and
// spec-standard so it works in any modern browser (needs a secure origin - HTTPS or localhost).

/* eslint-disable @typescript-eslint/no-explicit-any */

export const passkeysSupported = (): boolean =>
    typeof window !== 'undefined' && typeof window.PublicKeyCredential !== 'undefined';

function b64urlToBuffer(value: string): ArrayBuffer {
    const pad = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + pad).replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
}

function bufferToB64url(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (const b of bytes) binary += String.fromCharCode(b);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** Run attestation (create a new passkey) from server options; returns the JSON to POST back. */
export async function createPasskey(options: any): Promise<any> {
    const publicKey: PublicKeyCredentialCreationOptions = {
        ...options,
        challenge: b64urlToBuffer(options.challenge),
        user: { ...options.user, id: b64urlToBuffer(options.user.id) },
        excludeCredentials: (options.excludeCredentials ?? []).map((c: any) => ({ ...c, id: b64urlToBuffer(c.id) })),
    };
    const credential = (await navigator.credentials.create({ publicKey })) as PublicKeyCredential | null;
    if (!credential) throw new Error('Passkey setup was cancelled.');
    const response = credential.response as AuthenticatorAttestationResponse;

    return {
        id: credential.id,
        rawId: bufferToB64url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: bufferToB64url(response.clientDataJSON),
            attestationObject: bufferToB64url(response.attestationObject),
        },
    };
}

/** Run assertion (use an existing passkey) from server options; returns the JSON to POST back. */
export async function getPasskey(options: any): Promise<any> {
    const publicKey: PublicKeyCredentialRequestOptions = {
        ...options,
        challenge: b64urlToBuffer(options.challenge),
        allowCredentials: (options.allowCredentials ?? []).map((c: any) => ({ ...c, id: b64urlToBuffer(c.id) })),
    };
    const credential = (await navigator.credentials.get({ publicKey })) as PublicKeyCredential | null;
    if (!credential) throw new Error('Passkey check was cancelled.');
    const response = credential.response as AuthenticatorAssertionResponse;

    return {
        id: credential.id,
        rawId: bufferToB64url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: bufferToB64url(response.clientDataJSON),
            authenticatorData: bufferToB64url(response.authenticatorData),
            signature: bufferToB64url(response.signature),
            userHandle: response.userHandle ? bufferToB64url(response.userHandle) : null,
        },
    };
}
