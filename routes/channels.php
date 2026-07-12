<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// The live map (status + utilisation). Single shared map in v1, so any authenticated
// operator may subscribe; the auth itself is what keeps anonymous clients out.
Broadcast::channel('map', fn (User $user): bool => true);
