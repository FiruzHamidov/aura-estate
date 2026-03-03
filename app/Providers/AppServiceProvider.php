<?php

namespace App\Providers;

use App\Models\Property;
use App\Models\User;
use App\Observers\PropertyObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

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
        Property::observe(PropertyObserver::class);

        Relation::morphMap([
            'user' => User::class,
        ]);
    }
}
