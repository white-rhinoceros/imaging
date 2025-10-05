<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Whiterhino\Imaging\Exceptions\ImagingException;
use Whiterhino\Imaging\Imaging;
use Whiterhino\Imaging\Tests\TestCase;

final class ImageProcessingTest extends TestCase
{
    /**
     * @dataProvider handlerProvider
     */
    public function test_resize_and_watermark_creates_cached_file(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        Storage::fake('public');
        Storage::fake('imagecache');

        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        $watermarkPath = $this->copyFixtureToTemp('source/watermark.png');

        try {
            [$cached, $url] = Imaging::resizeAndWatermark('source.png', 'public', 100, 60, $watermarkPath);

            self::assertNotEmpty($cached);
            self::assertTrue(Storage::disk('imagecache')->exists($cached));
            self::assertNotEmpty($url);
        } finally {
            @unlink($watermarkPath);
        }
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_resize_and_watermark_uses_default_watermark_when_not_passed(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);

        $watermarkPath = $this->fixturePath('source/watermark.png');
        $this->configureHandler($handler, ['watermark_filename' => $watermarkPath]);

        Storage::fake('public');
        Storage::fake('imagecache');

        $source = $this->readFixture('source/base.png');
        Storage::disk('public')->put('source.png', $source);

        [$cached] = Imaging::resizeAndWatermark('source.png', 'public', 120, 80, null);

        self::assertNotEmpty($cached);
        self::assertTrue(Storage::disk('imagecache')->exists($cached));

        $processed = Storage::disk('imagecache')->get($cached);
        self::assertNotSame(md5($source), md5($processed));
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_resize_and_watermark_returns_empty_when_debug_disabled_and_watermark_missing(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);

        $missing = $this->fixturePath('source/does-not-exist.png');
        $this->configureHandler($handler, [
            'watermark_filename' => $missing,
            'debug' => false,
        ]);

        Storage::fake('public');
        Storage::fake('imagecache');

        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        $result = Imaging::resizeAndWatermark('source.png', 'public', 120, 60, null);

        self::assertSame([], $result);
        self::assertSame([], Storage::disk('imagecache')->allFiles());

        $this->app['config']->set('imaging.debug', true);
        $this->app['config']->set('imaging.watermark_filename', '');
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_resize_and_watermark_throws_when_watermark_unreadable_in_debug(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        Storage::fake('public');
        Storage::fake('imagecache');

        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        $this->expectException(ImagingException::class);

        Imaging::resizeAndWatermark('source.png', 'public', 100, 60, $this->fixturePath('source/missing-watermark.png'));
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_rotate_creates_cached_file(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        Storage::fake('public');
        Storage::fake('imagecache');

        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        [$cached, $url] = Imaging::rotate('source.png', 'public', 90);

        self::assertNotEmpty($cached);
        self::assertTrue(Storage::disk('imagecache')->exists($cached));
        self::assertNotEmpty($url);
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_rotate_throws_when_source_missing_in_debug(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        Storage::fake('public');
        Storage::fake('imagecache');

        $this->expectException(ImagingException::class);

        Imaging::rotate('missing.png', 'public', 45);
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_rotate_returns_empty_when_debug_disabled_and_source_missing(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);

        $this->configureHandler($handler, ['debug' => false]);

        Storage::fake('public');
        Storage::fake('imagecache');

        [$cached, $url] = Imaging::rotate('missing.png', 'public', 45);

        self::assertSame('', $cached);
        self::assertIsString($url);

        $this->app['config']->set('imaging.debug', true);
    }
}
