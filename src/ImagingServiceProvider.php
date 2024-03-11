<?php

namespace Whiterhino\Imaging;

use Illuminate\Support\ServiceProvider;

class ImagingServiceProvider  extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/imaging.php', 'fortify');

        // Это как-то выполняется всегда!!!
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $configPath = __DIR__ . '/../config/imaging.php';

        $this->publishes([$configPath => $this->app->configPath('imaging.php')], 'config');


    }

}
