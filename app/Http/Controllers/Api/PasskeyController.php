<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePasskeyVerified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * Passkeys (WebAuthn) for the SPA. The operator is already signed in with their password when they
 * hit these - a passkey here is a *second factor* (or a self-service enrolment), not a passwordless
 * login. Registering or verifying one marks the session `passkey_verified`, which is what
 * {@see EnsurePasskeyVerified} looks for. The heavy WebAuthn crypto is done by
 * laravel/passkeys' Actions; we just orchestrate and stamp the session.
 */
class PasskeyController extends Controller
{
    /** Options for creating a new passkey (attestation). Stashes the challenge in the session. */
    public function registerOptions(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $options = $generate($request->user());
        $request->session()->put('passkey.registration_options', WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /** Store the newly-created passkey, and count this session as passkey-verified. */
    public function register(PasskeyRegistrationRequest $request, StorePasskey $store): JsonResponse
    {
        // Defence in depth for #42 (the middleware also enforces this): an operator who already
        // has a passkey must be verified this session before adding another, so a password-only
        // attacker can't self-enrol a factor to satisfy the gate. A first enrolment (no passkey
        // yet) is allowed - that's the mandatory-enrol path.
        $verified = $request->hasSession() && $request->session()->get('passkey_verified', false);
        if (! $verified && $request->user()->passkeys()->exists()) {
            abort(Response::HTTP_LOCKED, 'Verify an existing passkey before adding another.');
        }

        $passkey = $store(
            $request->user(),
            $request->string('name')->toString() ?: 'Passkey',
            $request->credential(),
            $request->registrationOptions(),
        );
        $request->session()->put('passkey_verified', true);

        return response()->json($this->shape($passkey), Response::HTTP_CREATED);
    }

    /** Options for the second-factor check, scoped to this operator's own passkeys. */
    public function verifyOptions(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        /** @var PasskeyUser $user */
        $user = $request->user();
        $options = $generate($user);
        $request->session()->put('passkey.verification_options', WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /**
     * Verify a passkey assertion as the 2FA step. Unlike a passwordless login we do NOT re-login;
     * we confirm the passkey belongs to the already-signed-in operator and stamp the session.
     */
    public function verify(PasskeyVerificationRequest $request, VerifyPasskey $verify): JsonResponse
    {
        $passkey = $verify($request->credential(), $request->verificationOptions());

        abort_unless($passkey->user_id === $request->user()->getKey(), Response::HTTP_FORBIDDEN, 'That passkey is not yours.');

        $request->session()->put('passkey_verified', true);

        return response()->json(['verified' => true]);
    }

    /** This operator's passkeys (metadata only). */
    public function index(Request $request): JsonResponse
    {
        $passkeys = $request->user()->passkeys()->latest()->get()->map(fn (Passkey $p) => $this->shape($p));

        return response()->json($passkeys);
    }

    /** Remove one of this operator's passkeys. */
    public function destroy(Request $request, Passkey $passkey, DeletePasskey $delete): Response
    {
        abort_unless($passkey->user_id === $request->user()->getKey(), Response::HTTP_FORBIDDEN);

        $delete($request->user(), $passkey);

        return response()->noContent();
    }

    /** @return array<string,mixed> */
    private function shape(Passkey $p): array
    {
        return [
            'id' => $p->getKey(),
            'name' => $p->name,
            'last_used_at' => $p->last_used_at,
            'created_at' => $p->created_at,
        ];
    }
}
