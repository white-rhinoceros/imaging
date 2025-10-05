<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Whiterhino\Imaging\Imaging;
use Whiterhino\Imaging\Tests\TestCase;

final class ImagingVisualTest extends TestCase
{
    private const SAMPLE_IMAGES = ['girl.png', 'city.png', 'robot.png'];

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function visualDataProvider(): array
    {
        $data = [];

        foreach (self::handlerProvider() as $handlerKey => [$handler]) {
            foreach (self::SAMPLE_IMAGES as $image) {
                $data[$handlerKey . ':' . $image] = [$handler, $image];
            }
        }

        return $data;
    }

    /**
     * @dataProvider visualDataProvider
     * @group visual
     */
    public function test_visual_processing_fixtures_are_generated(string $handler, string $imageName): void
    {
        if (!getenv('IMAGING_VISUAL_FIXTURES')) {
            self::markTestSkipped('Set IMAGING_VISUAL_FIXTURES=1 to generate visual fixtures.');
        }

        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        $handlerShort = (new ReflectionClass($handler))->getShortName();
        $processRoot = $this->ensureProcessDirectory();
        $handlerRoot = $processRoot . DIRECTORY_SEPARATOR . $handlerShort;
        $inputDir = $handlerRoot . DIRECTORY_SEPARATOR . 'input';
        $outputDir = $handlerRoot . DIRECTORY_SEPARATOR . 'output';

        foreach ([$handlerRoot, $inputDir, $outputDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        $sourcePath = $this->fixturePath('source/' . $imageName);
        $inputPath = $inputDir . DIRECTORY_SEPARATOR . $imageName;
        copy($sourcePath, $inputPath);

        $originalPublicConfig = $this->app['config']->get('filesystems.disks.public');
        $originalCacheConfig = $this->app['config']->get('filesystems.disks.imagecache');

        try {
            $this->app['config']->set('filesystems.disks.public', [
                'driver' => 'local',
                'root' => $inputDir,
            ]);
            $this->app['config']->set('filesystems.disks.imagecache', [
                'driver' => 'local',
                'root' => $outputDir,
            ]);

            Storage::forgetDisk('public');
            Storage::forgetDisk('imagecache');

            $operations = [
                'resize_pad' => fn () => Imaging::resize($imageName, 'public', 320, 200, Imaging::RESIZE_MODE_PAD),
                'resize_and_watermark' => fn () => Imaging::resizeAndWatermark($imageName, 'public', 320, 200, $this->fixturePath('source/watermark.png')),
                'crop' => fn () => Imaging::crop($imageName, 'public', 20, 20, 220, 180),
                'rotate' => fn () => Imaging::rotate($imageName, 'public', 45),
                'watermark' => fn () => Imaging::watermark($imageName, 'public', $this->fixturePath('source/watermark.png')),
            ];

            foreach ($operations as $operation => $callback) {
                [$cached] = $callback();

                if ($cached === null || $cached === '') {
                    continue;
                }

                $this->storeVisualResult($outputDir, $cached, $handlerShort, $imageName, $operation);
            }
        } finally {
            $this->app['config']->set('filesystems.disks.public', $originalPublicConfig);
            $this->app['config']->set('filesystems.disks.imagecache', $originalCacheConfig);
            Storage::forgetDisk('public');
            Storage::forgetDisk('imagecache');
        }
    }

    private function storeVisualResult(string $outputDir, string $cachedPath, string $handler, string $imageName, string $operation): void
    {
        $disk = Storage::disk('imagecache');

        if (!$disk->exists($cachedPath)) {
            return;
        }

        $contents = $disk->get($cachedPath);
        $extension = pathinfo($cachedPath, PATHINFO_EXTENSION) ?: 'png';
        $testName = sprintf('%s_%s_%s', $handler, pathinfo($imageName, PATHINFO_FILENAME), $operation);
        $fileName = $this->slug($testName) . '.' . $extension;
        $targetPath = $outputDir . DIRECTORY_SEPARATOR . $fileName;

        file_put_contents($targetPath, $contents);
        $disk->delete($cachedPath);

        $this->cleanupEmptyDirectories($disk, dirname($cachedPath));
    }

    private function slug(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    private function cleanupEmptyDirectories(FilesystemAdapter $disk, string $relativePath): void
    {
        $relativePath = trim($relativePath, '\\/');

        while ($relativePath !== '' && $relativePath !== '.' && $relativePath !== DIRECTORY_SEPARATOR) {
            $absolutePath = $disk->path($relativePath);

            if (!is_dir($absolutePath) || !$this->isDirectoryEmpty($absolutePath)) {
                break;
            }

            @rmdir($absolutePath);

            $relativePath = trim(dirname($relativePath), '\\/');
        }
    }

    private function isDirectoryEmpty(string $path): bool
    {
        $items = @scandir($path);

        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                return false;
            }
        }

        return true;
    }
}
