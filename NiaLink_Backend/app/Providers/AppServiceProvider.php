<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Define the admin-access Gate.
        // This closure runs for every request hitting a route that has the 'can:admin-access' middleware.
        Gate::define('admin-access', function (User $user) {
            return in_array($user->user_role, ['admin', 'merchant_admin']);
        });
    }
}
