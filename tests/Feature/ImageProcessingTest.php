<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Whiterhino\Imaging\Imaging;
use Whiterhino\Imaging\Tests\TestCase;

final class ImageProcessingTest extends TestCase
{
    public function test_resize_and_watermark_creates_cached_file(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension required.');
        }

        Storage::fake('public');
        Storage::fake('imagecache');

        Storage::disk('public')->put('source.png', $this->createPng(100, 100, [255, 0, 0]));

        $watermarkPath = sys_get_temp_dir() . '/wm_' . uniqid('', true) . '.png';
        file_put_contents($watermarkPath, $this->createPng(20, 20, [0, 0, 255]));

        try {
            [$cached, $url] = Imaging::resizeAndWatermark('source.png', 'public', 50, 50, $watermarkPath);

            self::assertNotEmpty($cached);
            self::assertTrue(Storage::disk('imagecache')->exists($cached));
            self::assertNotEmpty($url);
        } finally {
            @unlink($watermarkPath);
        }
    }

    public function test_rotate_creates_cached_file(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension required.');
        }

        Storage::fake('public');
        Storage::fake('imagecache');

        Storage::disk('public')->put('source.png', $this->createPng(80, 40, [0, 255, 0]));

        [$cached, $url] = Imaging::rotate('source.png', 'public', 90);

        self::assertNotEmpty($cached);
        self::assertTrue(Storage::disk('imagecache')->exists($cached));
        self::assertNotEmpty($url);
    }

    private function createPng(int $width, int $height, array $rgb): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagepng($image);
        $data = (string) ob_get_clean();
        imagedestroy($image);

        return $data;
    }
}
