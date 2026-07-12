<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateMailSettingsRequest;
use App\Support\EngineLog;
use App\Support\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Outgoing mail-server (SMTP) config. The password is encrypted at rest
 * and never returned - {@see MailSettings::publicView()} exposes only `password_set`.
 */
class MailSettingController extends Controller
{
    public function show(MailSettings $mail): JsonResponse
    {
        return response()->json(['data' => $mail->publicView()]);
    }

    public function update(UpdateMailSettingsRequest $request, MailSettings $mail): JsonResponse
    {
        $mail->save($request->validated());

        return response()->json(['data' => $mail->publicView()]);
    }

    /**
     * Send a test email to confirm the server works. The failure reason is logged
     * server-side only - returning it to the client would make this a
     * banner-grab-quality port-scan oracle against arbitrary internal host:port pairs.
     */
    public function test(Request $request, MailSettings $mail): JsonResponse
    {
        $to = Validator::make($request->all(), ['to' => ['required', 'email']])->validate()['to'];

        try {
            $mail->sendTest($to);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            EngineLog::error('mail: test send failed', ['error' => $e->getMessage()]); // message only, never the secret

            return response()->json(['ok' => false, 'error' => 'Test send failed.'], 422);
        }
    }
}
