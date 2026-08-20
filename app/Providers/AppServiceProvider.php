<?php

namespace App\Providers;

use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

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
        Vite::prefetch(concurrency: 3);

        // Spatie\Permission\Models\Role berada di luar App\Models, sehingga
        // penemuan policy otomatis Laravel tidak menemukannya. Tanpa baris ini,
        // halaman Role terbuka untuk siapa saja yang bisa login.
        Gate::policy(Role::class, RolePolicy::class);
    }
}
