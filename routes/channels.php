<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// The live map (status + utilisation). The shared channel carries the whole fleet, so only an
// unrestricted operator may subscribe to it - a restricted one (per-user or via a group, GitHub
// #28) could otherwise read live status for devices outside their maps straight off the socket.
Broadcast::channel('map', fn (User $user): bool => ! $user->isRestricted());

// A restricted operator's own live stream: LiveBroadcast sends it a copy of each event filtered to
// their devices (App\Support\RestrictedAudience). Only that user, and only while restricted.
Broadcast::channel('map.user.{id}', fn (User $user, int $id): bool => $user->id === $id && $user->isRestricted());
