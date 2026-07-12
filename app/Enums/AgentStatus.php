<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Enrolled = 'enrolled'; // created, never connected
    case Online = 'online';     // connected to the hub now
    case Offline = 'offline';   // was online, connection dropped
}
