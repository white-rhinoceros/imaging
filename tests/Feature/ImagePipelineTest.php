<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use SplFileInfo;
use Whiterhino\Imaging\Handlers\HandlerContract;
use Whiterhino\Imaging\ImageManager;
use Whiterhino\Imaging\Types\XPositionType;
use Whiterhino\Imaging\Types\YPositionType;
use Whiterhino\Imaging\Tests\TestCase;

final class ImagePipelineTest extends TestCase
{
    /**
     * @dataProvider handlerProvider
     */
    public function test_pipeline_executes_operations(string $handler): void
    {
        $this->skipIfHandlerUnavailable($handler);
        $this->configureHandler($handler);

        Storage::fake('public');
        Storage::fake('imagecache');

        Storage::disk('public')->put('source.png', $this->readFixture('source/base.png'));
        $watermark = new SplFileInfo($this->fixturePath('source/watermark.png'));

        /** @var ImageManager $manager */
        $manager = $this->app->make(ImageManager::class, ['public']);

        $pipelinePath = $manager
            ->pipeline('source.png', 'cache/pipeline')
            ->resize(160, 90, keepRatio: true, pad: true)
            ->rotate(90)
            ->watermark($watermark, XPositionType::RIGHT, YPositionType::BOTTOM, 12, 12)
            ->run();

        self::assertNotEmpty($pipelinePath);
        self::assertTrue(Storage::disk('imagecache')->exists($pipelinePath));

        $closurePath = $manager->make(
            'source.png',
            'cache/closure',
            static function (HandlerContract $handler) use ($watermark): void {
                $handler->resize(160, 90, true, true)
                    ->rotate(90)
                    ->watermark($watermark, XPositionType::RIGHT, YPositionType::BOTTOM, 12, 12);
            },
        );

        self::assertNotEmpty($closurePath);

        $pipelineContents = Storage::disk('imagecache')->get($pipelinePath);
        $closureContents = Storage::disk('imagecache')->get($closurePath);

        self::assertSame($closureContents, $pipelineContents);

        [$pathWithUrl, $url] = $manager
            ->pipeline('source.png', 'cache/url', true)
            ->resize(120, 120, keepRatio: true, pad: true)
            ->watermark($watermark, XPositionType::CENTER, YPositionType::CENTER, 0, 0)
            ->runWithUrl();

        self::assertNotEmpty($pathWithUrl);
        self::assertNotEmpty($url);
    }
}
