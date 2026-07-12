<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Support\EngineLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum SPA (session/cookie) authentication. The same-origin React app
 * fetches `/sanctum/csrf-cookie`, then POSTs `/api/login`; the session cookie that
 * results authenticates the rest of `/api` and the private `map` channel.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            EngineLog::warning('auth: failed login', ['email' => $request->input('email'), 'ip' => $request->ip()]);

            // No "user not found" leak - generic message on the email field.
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate(); // prevent session fixation

        return response()->json($this->me($request));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /** The current operator (drives the SPA auth guard + top-bar identity). */
    public function user(Request $request): JsonResponse
    {
        return response()->json($this->me($request));
    }

    /** Self-service password change - `current_password:web` already verified it. */
    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]); // 'hashed' cast hashes it

        EngineLog::warning('auth: password changed', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function me(Request $request): array
    {
        $user = $request->user();

        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'is_admin' => $user->isAdmin()];
    }
}
