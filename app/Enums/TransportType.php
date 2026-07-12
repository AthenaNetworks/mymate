<?php

namespace App\Enums;

/**
 * How an alert is delivered. Email via Laravel Mail; Slack/Teams/Messenger
 * via an incoming-webhook POST of `{"text": ...}` (Messenger = a custom messaging-app webhook,
 * ). Transport config (address / webhook URL) is encrypted at rest and never returned.
 */
enum TransportType: string
{
    case Email = 'email';
    case Slack = 'slack';
    case Teams = 'teams';
    case Messenger = 'messenger';
}
