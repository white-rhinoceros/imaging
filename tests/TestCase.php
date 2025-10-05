<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Whiterhino\Imaging\Handlers\GdHandler;
use Whiterhino\Imaging\Handlers\ImagickHandler;
use Whiterhino\Imaging\ImagingServiceProvider;
use Whiterhino\Imaging\Types\ImageType;

abstract class TestCase extends Orchestra
{
    /** @var string */
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = (string) config('imaging.temp_dir');

        if ($this->tempDir !== '' && !is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [ImagingServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:UmZGSFVIOWRMTW9yMUthTFJlSk5YczVNcHhUS0tEYkE=');

        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/public'),
        ]);

        $app['config']->set('filesystems.disks.imagecache', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/imagecache'),
        ]);

        $app['config']->set('filesystems.default', 'public');

        $config = require __DIR__ . '/../stubs/imaging.php';
        $config['temp_dir'] = storage_path('framework/cache/imaging-tests');
        $config['debug'] = true;

        if (!function_exists('imagewebp')) {
            // Fallback to a widely supported format when GD lacks WebP support.
            $config['def_imagetype'] = ImageType::PNG;
        }

        $app['config']->set('imaging', $config);
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function handlerProvider(): array
    {
        return [
            'gd' => [GdHandler::class],
            'imagick' => [ImagickHandler::class],
        ];
    }

    protected function skipIfHandlerUnavailable(string $handler): void
    {
        switch ($handler) {
            case GdHandler::class:
                if (!function_exists('imagecreatetruecolor')) {
                    self::markTestSkipped('GD extension required.');
                }
                break;
            case ImagickHandler::class:
                if (!extension_loaded('imagick')) {
                    self::markTestSkipped('Imagick extension required.');
                }
                break;
            default:
                self::markTestSkipped('Unknown handler provided: ' . $handler);
        }
    }

    protected function configureHandler(string $handler, array $overrides = []): void
    {
        $config = $this->app['config'];

        $config->set('imaging.def_handler', $handler);
        $config->set('imaging.def_imagetype', $overrides['def_imagetype'] ?? ImageType::PNG);

        foreach ($overrides as $key => $value) {
            $config->set('imaging.' . $key, $value);
        }
    }

    protected function fixturePath(string $relative = ''): string
    {
        $base = __DIR__ . '/Fixtures';

        return $relative === '' ? $base : $base . '/' . ltrim($relative, '/');
    }

    protected function readFixture(string $relative): string
    {
        $path = $this->fixturePath($relative);

        return (string) file_get_contents($path);
    }

    protected function copyFixtureToTemp(string $relative): string
    {
        $sourcePath = $this->fixturePath($relative);
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imaging-' . uniqid('', true) . '.' . $extension;

        copy($sourcePath, $tempPath);

        return $tempPath;
    }

    protected function ensureProcessDirectory(): string
    {
        $path = $this->fixturePath('process');

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}
