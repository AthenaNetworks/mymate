<?php

namespace App\Enums;

/**
 * How an alert is delivered. Email via Laravel Mail; Slack / Teams / Messenger / a generic
 * Webhook via an incoming-webhook POST of `{"text": ...}`; Discord via `{"content": ...}`;
 * Telegram via the Bot API (bot token + chat id); PagerDuty via its Events API (routing key).
 * Transport config (address / URL / tokens) is encrypted at rest and never returned.
 */
enum TransportType: string
{
    case Email = 'email';
    case Slack = 'slack';
    case Teams = 'teams';
    case Messenger = 'messenger';
    case Webhook = 'webhook';
    case Discord = 'discord';
    case Telegram = 'telegram';
    case Pagerduty = 'pagerduty';
}
