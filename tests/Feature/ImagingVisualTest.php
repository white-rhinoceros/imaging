<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickException;
use ReflectionClass;
use Whiterhino\Imaging\Imaging;
use Whiterhino\Imaging\Tests\TestCase;

final class ImagingVisualTest extends TestCase
{
    private const SAMPLE_IMAGES = ['girl.png', 'city.png', 'robot.png'];
    private const SNAPSHOT_DIR = 'snapshots';

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

        $isSnapshotUpdate = (bool) getenv('IMAGING_VISUAL_FIXTURES');

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

            $processedCount = 0;

            foreach ($operations as $operation => $callback) {
                [$cached] = $callback();

                if ($cached === null || $cached === '') {
                    continue;
                }

                $this->storeVisualResult(
                    $outputDir,
                    $cached,
                    $handlerShort,
                    $imageName,
                    $operation,
                    $isSnapshotUpdate
                );

                $processedCount++;
            }

            self::assertGreaterThan(
                0,
                $processedCount,
                'No visual operations completed; ensure fixtures exist.'
            );
        } finally {
            $this->app['config']->set('filesystems.disks.public', $originalPublicConfig);
            $this->app['config']->set('filesystems.disks.imagecache', $originalCacheConfig);
            Storage::forgetDisk('public');
            Storage::forgetDisk('imagecache');
        }
    }

    private function storeVisualResult(
        string $outputDir,
        string $cachedPath,
        string $handler,
        string $imageName,
        string $operation,
        bool $isSnapshotUpdate
    ): void
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
        $snapshotDir = $this->fixturePath(
            self::SNAPSHOT_DIR . '/' . strtolower($handler)
        );
        $snapshotPath = $snapshotDir . DIRECTORY_SEPARATOR . $fileName;

        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0777, true);
        }

        if ($isSnapshotUpdate) {
            file_put_contents($targetPath, $contents);
            file_put_contents($snapshotPath, $contents);
        } else {
            if (!file_exists($snapshotPath)) {
                self::markTestSkipped(sprintf(
                    'Snapshot missing for %s. Regenerate with IMAGING_VISUAL_FIXTURES=1 docker compose run --rm -e IMAGING_VISUAL_FIXTURES=1 tests vendor/bin/phpunit --group visual.',
                    $fileName
                ));
            }

            $expected = (string) file_get_contents($snapshotPath);
            $this->assertImageMatchesSnapshot($expected, $contents, $fileName, $snapshotPath);
        }

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

    private function assertImageMatchesSnapshot(
        string $expectedBlob,
        string $actualBlob,
        string $fileName,
        string $snapshotPath
    ): void {
        if ($expectedBlob === $actualBlob) {
            return;
        }

        if (function_exists('imagecreatefromstring')) {
            $this->assertImagesEqualWithGd($expectedBlob, $actualBlob, $fileName, $snapshotPath);

            return;
        }

        if (class_exists(Imagick::class)) {
            $this->assertImagesEqualWithImagick($expectedBlob, $actualBlob, $fileName);

            return;
        }

        self::assertSame(
            $expectedBlob,
            $actualBlob,
            sprintf('Snapshot mismatch for %s (binary compare).', $fileName)
        );
    }

    private function assertImagesEqualWithGd(
        string $expectedBlob,
        string $actualBlob,
        string $fileName,
        string $snapshotPath
    ): void {
        $expectedImage = imagecreatefromstring($expectedBlob);
        $actualImage = imagecreatefromstring($actualBlob);

        if ($expectedImage === false || $actualImage === false) {
            self::fail(sprintf('Unable to decode image data for %s', $fileName));
        }

        $expectedWidth = imagesx($expectedImage);
        $expectedHeight = imagesy($expectedImage);
        $actualWidth = imagesx($actualImage);
        $actualHeight = imagesy($actualImage);

        if ($expectedWidth !== $actualWidth || $expectedHeight !== $actualHeight) {
            imagedestroy($expectedImage);
            imagedestroy($actualImage);

            self::fail(sprintf(
                'Snapshot dimensions mismatch for %s (expected %dx%d, got %dx%d).',
                $fileName,
                $expectedWidth,
                $expectedHeight,
                $actualWidth,
                $actualHeight
            ));
        }

        $diffPixels = 0;

        for ($y = 0; $y < $expectedHeight; $y++) {
            for ($x = 0; $x < $expectedWidth; $x++) {
                if (imagecolorat($expectedImage, $x, $y) !== imagecolorat($actualImage, $x, $y)) {
                    $diffPixels++;
                }
            }
        }

        imagedestroy($expectedImage);
        imagedestroy($actualImage);

        if ($diffPixels > 0) {
            self::fail(sprintf(
                'Snapshot mismatch for %s (%d pixels differ). Run IMAGING_VISUAL_FIXTURES=1 to update snapshots. Current snapshot: %s',
                $fileName,
                $diffPixels,
                $snapshotPath
            ));
        }
    }

    private function assertImagesEqualWithImagick(string $expectedBlob, string $actualBlob, string $fileName): void
    {
        try {
            $expected = new Imagick();
            $expected->readImageBlob($expectedBlob);

            $actual = new Imagick();
            $actual->readImageBlob($actualBlob);

            [$diffImage, $metric] = $expected->compareImages($actual, Imagick::METRIC_MEANSQUAREERROR);
            $expected->clear();
            $actual->clear();
            $diffImage?->clear();

            if ($metric > 0.0) {
                self::fail(sprintf(
                    'Snapshot mismatch for %s (MSE=%f). Run IMAGING_VISUAL_FIXTURES=1 to update snapshots.',
                    $fileName,
                    $metric
                ));
            }
        } catch (ImagickException $exception) {
            self::fail(sprintf(
                'Unable to compare images for %s via Imagick: %s',
                $fileName,
                $exception->getMessage()
            ));
        }
    }
}
