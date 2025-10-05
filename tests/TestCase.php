<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
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
