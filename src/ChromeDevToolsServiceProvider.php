<?php

namespace Kai\ChromeDevTools;

use Illuminate\Support\ServiceProvider;
use Kai\ChromeDevTools\Services\ChromeDevTools;

class ChromeDevToolsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        // Bind the service class to the service container
        $this->app->bind('devtools', function ($app) {
            // Read configuration from the published config file
            $wsUrl = config('devtools.websocket_url', 'ws://127.0.0.1:9222/devtools/browser');
            $timeout = config('devtools.timeout', 15);
            
            return new ChromeDevTools($wsUrl, $timeout);
        });

        // Merge default configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/devtools.php', 'devtools'
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish configuration file
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/devtools.php' => config_path('devtools.php'),
            ], 'config'); // The tag 'config' is what users use with vendor:publish
        }
    }
}
