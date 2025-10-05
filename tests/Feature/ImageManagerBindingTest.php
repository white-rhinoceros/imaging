<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Whiterhino\Imaging\ImageManager;
use Whiterhino\Imaging\Tests\TestCase;

final class ImageManagerBindingTest extends TestCase
{
    public function test_it_resolves_image_manager_from_container(): void
    {
        Storage::fake('public');
        Storage::fake('imagecache');

        $manager = $this->app->make(ImageManager::class, ['public']);

        self::assertInstanceOf(ImageManager::class, $manager);
    }
}
