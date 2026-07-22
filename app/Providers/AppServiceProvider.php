<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Wire the thin permission layer (spec §3): can($key) resolves to "the
     * current user's role grants $key". Enforced on both UI and server.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (Permissions::isKnown($ability)) {
                return $user->hasPermission($ability) ?: false;
            }

            return null; // defer to any explicitly-defined gate/policy
        });
    }
}
