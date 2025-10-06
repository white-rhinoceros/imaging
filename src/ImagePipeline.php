<?php

declare(strict_types=1);

namespace Whiterhino\Imaging;

use SplFileInfo;
use Whiterhino\Imaging\Handlers\HandlerContract;
use Whiterhino\Imaging\Types\XPositionType;
use Whiterhino\Imaging\Types\YPositionType;

final class ImagePipeline
{
    /** @var array<int, callable(HandlerContract):void> */
    private array $steps = [];

    public function __construct(
        private readonly ImageManager $manager,
        private string $filename,
        private string $cached,
        private bool $force
    ) {
    }

    public function force(bool $force = true): self
    {
        $this->force = $force;

        return $this;
    }

    public function then(callable $callback): self
    {
        $this->steps[] = $callback;

        return $this;
    }

    public function resize(
        int|string|null $width,
        int|string|null $height = null,
        bool $keepRatio = true,
        bool $pad = false
    ): self {
        return $this->then(static fn (HandlerContract $handler) => $handler->resize($width, $height, $keepRatio, $pad));
    }

    public function crop(
        int|string $x1,
        int|string|null $y1 = null,
        int|string|null $x2 = null,
        int|string|null $y2 = null,
        bool $addPadding = false
    ): self {
        return $this->then(static fn (HandlerContract $handler) => $handler->crop($x1, $y1, $x2, $y2, $addPadding));
    }

    /**
     * @param SplFileInfo|string $file
     */
    public function watermark(
        SplFileInfo|string $file,
        XPositionType|int $xPosition,
        YPositionType|int $yPosition,
        int $xPadding,
        ?int $yPadding = null
    ): self {
        $watermark = $file instanceof SplFileInfo ? $file : new SplFileInfo($file);

        return $this->then(static fn (HandlerContract $handler) => $handler->watermark($watermark, $xPosition, $yPosition, $xPadding, $yPadding));
    }

    public function rotate(int $degrees): self
    {
        return $this->then(static fn (HandlerContract $handler) => $handler->rotate($degrees));
    }

    public function call(callable $callback): self
    {
        return $this->then($callback);
    }

    public function run(): ?string
    {
        return $this->manager->make(
            $this->filename,
            $this->cached,
            function (HandlerContract $handler): void {
                foreach ($this->steps as $step) {
                    $step($handler);
                }
            },
            $this->force
        );
    }

    /**
     * @return array{0:?string, 1:string}
     */
    public function runWithUrl(): array
    {
        $processed = $this->run();
        $url = '';

        if (is_string($processed) && $processed !== '') {
            $url = $this->manager->generateUrl($processed);
        }

        return [$processed, $url];
    }
}
