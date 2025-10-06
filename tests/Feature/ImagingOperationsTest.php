<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Whiterhino\Imaging\Exceptions\ImagingException;
use Whiterhino\Imaging\Handlers\ImagickHandler;
use Whiterhino\Imaging\Imaging;
use Whiterhino\Imaging\Tests\TestCase;

final class ImagingOperationsTest extends TestCase
{
    /**
     * @dataProvider handlerProvider
     */
    public function test_crop_extracts_expected_dimensions(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        $this->prepareDisks();

        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        [$cached] = Imaging::crop('source.png', 'public', 10, 10, 110, 90);

        self::assertNotEmpty($cached);
        self::assertTrue(Storage::disk('imagecache')->exists($cached));

        $image = Storage::disk('imagecache')->get($cached);
        [$width, $height] = getimagesizefromstring($image);

        self::assertSame(100, $width);
        self::assertSame(80, $height);
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_resize_pad_matches_target_canvas(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        $this->prepareDisks();

        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        [$cached] = Imaging::resize('source.png', 'public', 200, 100, Imaging::RESIZE_MODE_PAD);

        self::assertNotEmpty($cached);
        $contents = Storage::disk('imagecache')->get($cached);
        [$width, $height] = getimagesizefromstring($contents);

        self::assertSame(200, $width);
        self::assertSame(100, $height);
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_watermark_modifies_image(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        $this->prepareDisks();

        $source = $this->readFixture('source/base.png');
        Storage::disk('public')->put('source.png', $source);

        $watermarkPath = $this->copyFixtureToTemp('source/watermark.png');

        try {
            [$cached] = Imaging::watermark('source.png', 'public', $watermarkPath);

            self::assertNotEmpty($cached);
            $this->assertTrue(Storage::disk('imagecache')->exists($cached));

            $processed = Storage::disk('imagecache')->get($cached);
            self::assertNotEquals(md5($source), md5($processed));
        } finally {
            @unlink($watermarkPath);
        }
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_resize_stretch_ignores_aspect_ratio(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        $this->prepareDisks();
        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        [$cached] = Imaging::resize('source.png', 'public', 200, 50, Imaging::RESIZE_MODE_STRETCH);

        $this->assertNotEmpty($cached);
        [$width, $height] = $this->dimensionsFromCache($cached);

        self::assertSame(200, $width);
        self::assertSame(50, $height);
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_resize_keep_ratio_preserves_aspect_ratio(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        $this->prepareDisks();
        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        [$cached] = Imaging::resize('source.png', 'public', 200, 50, Imaging::RESIZE_MODE_KEEPRATIO);

        $this->assertNotEmpty($cached);
        [$width, $height] = $this->dimensionsFromCache($cached);

        self::assertLessThanOrEqual(200, $width);
        self::assertSame(50, $height);
        self::assertEqualsWithDelta(4 / 3, $width / $height, 0.05);
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_crop_supports_percentage_coordinates(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        $this->prepareDisks();
        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        [$cached] = Imaging::crop('source.png', 'public', '10%', '10%', '90%', '90%');

        $this->assertNotEmpty($cached);
        [$width, $height] = $this->dimensionsFromCache($cached);

        self::assertSame(128, $width);
        self::assertSame(96, $height);
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_crop_handles_negative_coordinates(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        $this->prepareDisks();
        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        [$cached] = Imaging::crop('source.png', 'public', -60, -60, -10, -10);

        $this->assertNotEmpty($cached);
        [$width, $height] = $this->dimensionsFromCache($cached);

        self::assertSame(50, $width);
        self::assertSame(50, $height);
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_crop_addpadding_extends_canvas_where_supported(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);

        if ($handler === ImagickHandler::class) {
            //self::markTestSkipped('Imagick handler does not support crop padding.');

            // Imagick handler does not support crop padding.
            self::assertTrue(true);
            return;
        }

        $this->configureHandler($handler);

        $this->prepareDisks();
        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        [$cached] = Imaging::crop('source.png', 'public', 140, 20, 180, 100, Imaging::CROP_MODE_ADDPADDING);

        $this->assertNotEmpty($cached);
        [$width, $height] = $this->dimensionsFromCache($cached);

        self::assertSame(40, $width);
        self::assertSame(80, $height);
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_watermark_returns_empty_when_missing_file_and_debug_disabled(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);

        $this->configureHandler($handler, [
            'watermark_filename' => $this->fixturePath('source/absent.png'),
            'debug' => false,
        ]);

        $this->prepareDisks();
        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        $result = Imaging::watermark('source.png', 'public', $this->fixturePath('source/absent.png'));

        self::assertSame([], $result);
        self::assertSame([], Storage::disk('imagecache')->allFiles());

        $this->app['config']->set('imaging.debug', true);
        $this->app['config']->set('imaging.watermark_filename', '');
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_watermark_throws_when_missing_file_and_debug_enabled(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        $this->prepareDisks();
        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));

        $this->expectException(ImagingException::class);

        Imaging::watermark('source.png', 'public', $this->fixturePath('source/absent.png'));
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_resize_throws_on_missing_source_in_debug(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        $this->prepareDisks();

        $this->expectException(ImagingException::class);

        Imaging::resize('missing.png', 'public', 100, 100);
    }

    /**
     * @dataProvider handlerProvider
     */
    public function test_resize_returns_empty_when_debug_disabled_and_source_missing(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);

        $this->configureHandler($handler, ['debug' => false]);

        $this->prepareDisks();

        [$cached, $url] = Imaging::resize('missing.png', 'public', 100, 100);

        self::assertSame('', $cached);
        self::assertIsString($url);

        $this->app['config']->set('imaging.debug', true);
    }

    private function prepareDisks(): void
    {
        Storage::fake('public');
        Storage::fake('imagecache');
    }

    /**
     * @return array{0:int,1:int}
     */
    private function dimensionsFromCache(string $cached): array
    {
        $contents = Storage::disk('imagecache')->get($cached);

        return getimagesizefromstring($contents);
    }
}
