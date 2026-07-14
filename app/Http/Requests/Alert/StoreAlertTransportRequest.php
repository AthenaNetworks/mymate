<?php

namespace App\Http\Requests\Alert;

use App\Enums\TransportType;
use App\Rules\SafeOutboundUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertTransportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(TransportType::class)],
            'enabled' => ['sometimes', 'boolean'],
            // config fields by type - the controller folds these into the encrypted config.
            'email' => ['nullable', 'email', 'required_if:type,email'],
            // Slack / Teams / Messenger / generic Webhook / Discord all carry an incoming-webhook URL.
            // SafeOutboundUrl blocks loopback/link-local/reserved
            // targets but allows RFC1918 - a self-hosted webhook may live internally.
            'webhook_url' => ['nullable', 'url', new SafeOutboundUrl, 'required_if:type,slack', 'required_if:type,teams', 'required_if:type,messenger', 'required_if:type,webhook', 'required_if:type,discord'],
            // Telegram Bot API (fixed host).
            'telegram_token' => ['nullable', 'string', 'max:255', 'required_if:type,telegram'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64', 'required_if:type,telegram'],
            // PagerDuty Events API routing key (fixed host).
            'pagerduty_key' => ['nullable', 'string', 'max:255', 'required_if:type,pagerduty'],
        ];
    }
}
