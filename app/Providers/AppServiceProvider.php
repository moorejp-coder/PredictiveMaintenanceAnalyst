<?php

namespace App\Providers;

use App\Support\CanonicalUrl;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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
        // CloudFront does not send X-Forwarded-Proto. When APP_URL is https,
        // build every generated link as https so the browser does not block
        // the dashboard's /api/readings call as mixed content.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // One canonical address per page: APP_URL host, no query string,
        // no trailing slash.
        View::composer(['dashboard', 'faq', 'privacy', 'terms'], function ($view) {
            $view->with('canonicalUrl', CanonicalUrl::current());
        });
    }
}
