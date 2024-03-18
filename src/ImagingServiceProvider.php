<?php

namespace Whiterhino\Imaging;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

class ImagingServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Привязываем способ создания ImageManager сервиса.
        // В приложении этот сервис можно получить так:
        // $manager = App::make(ImageManager::class, 'public');
        // Где public - диск на котором ищутся файлы для обработки.
        $this->app->bind(ImageManager::class, function (Container $app, array $params) {
            /** @var ConfigRepository $laravelConfig */
            $laravel_config = $app->make(ConfigRepository::class);

            return new ImageManager(
                $params[0],
                $laravel_config->get('imaging.def_target_disk'),
                $laravel_config->get('imaging.def_handler'),
                $laravel_config->get('imaging.def_imagetype')
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // После установки пакета, необходимо выполнить:
        // php artisan vendor:publish --provider="Whiterhino\Imaging\ImagingServiceProvider"

        // Если надо только опубликовать конфиг:
        // php artisan vendor:publish --provider="Whiterhino\Imaging\ImagingServiceProvider" --tag=imaging-config

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../stubs/imaging.php' => config_path('imaging.php'),
            ], 'imaging-config');

            $this->publishes([
                __DIR__.'/../lang/en/imaging.php' => lang_path('en/imaging.php'),
                __DIR__.'/../lang/ru/imaging.php' => lang_path('ru/imaging.php'),
            ], 'imaging-lang');
        }
    }

}
