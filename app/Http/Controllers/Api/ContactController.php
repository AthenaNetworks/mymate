<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Public "contact sales" form (the sales demo). Deliberately outside the auth group so
 * an anonymous visitor can submit - rate-limited, validated, and best-effort: the lead
 * is always logged (so it's never lost even if mail isn't configured) and an email is
 * attempted to the configured sales address.
 */
class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        Log::info('contact: sales lead', [
            'name' => $data['name'],
            'email' => $data['email'],
            'company' => $data['company'] ?? null,
        ]);

        $to = (string) config('mymate.demo.contact_to');
        if ($to !== '') {
            try {
                $body = "New My Mate enquiry\n\n"
                    ."Name: {$data['name']}\n"
                    ."Email: {$data['email']}\n"
                    .'Company: '.($data['company'] ?: '-')."\n\n"
                    .$data['message'];
                Mail::raw($body, function ($m) use ($to, $data): void {
                    $m->to($to)->subject('My Mate enquiry from '.$data['name'])->replyTo($data['email'], $data['name']);
                });
            } catch (\Throwable $e) {
                // Lead is already logged - a mail-transport failure must not fail the form.
                Log::warning('contact: mail delivery failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
