<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate as a fresh **admin** operator.
     * Admin because most feature tests exercise management actions (create/edit/delete), which
     *  restricts to admins; read-only-tier behaviour is covered by tests that build a
     * non-admin `User::factory()->create()` explicitly (see ViewerReadOnlyTest).
     */
    protected function actingAsUser(): User
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        return $user;
    }
}
