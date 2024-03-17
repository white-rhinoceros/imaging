<?php

declare(strict_types=1);
namespace Whiterhino\Imaging\Handlers;

use Whiterhino\Imaging\Exceptions\ImagingException;
use Whiterhino\Imaging\Types\ImageType;
use Imagick;
use ImagickException;
use ImagickPixel;
use ImagickPixelException;
use InvalidArgumentException;
use SplFileInfo;
use Whiterhino\Imaging\Types\XPositionType;
use Whiterhino\Imaging\Types\YPositionType;

// Драйвер обработки изображений на основе Imagick.
final class ImagickHandler extends AbstractHandler
{
    // Допустимые расширения изображений для данного драйвера.
    protected const ACCEPTED_IMAGETYPES = [ImageType::PNG, ImageType::GIF, ImageType::JPEG];

    /** @var Imagick Экземпляр Imagemagick класса. */
    protected Imagick $imagick;

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    public function __construct(
        SplFileInfo $file,
        ?ImageType $force_imagetype = null,
        array $config = []
    )
    {
        if (!extension_loaded('imagick')) {
            throw new ImagingException(__(
                'imaging.imagick_ext_missing'
            ));
        }

        if (!isset($this->imagick)) {
            $this->imagick = new Imagick();
        }

        parent::__construct($file, $force_imagetype, $config);
    }

    // region Реализация абстрактного класса

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    protected function loadFromFile(): void
    {
        try {
            $this->imagick->readImage($this->file->getPathname());

            // Код ниже связан с exif авто-вращением.
            $orientation = $this->imagick->getImageOrientation();

            switch($orientation) {
                // Повернуть на 180 градусов.
                case Imagick::ORIENTATION_BOTTOMRIGHT:
                    $this->imagick->rotateimage("#000", 180);
                    break;

                 // Повернуть на 90 градусов по часовой стрелки.
                case Imagick::ORIENTATION_RIGHTTOP:
                    $this->imagick->rotateimage("#000", 90);
                    break;

                // Повернуть на 90 градусов против часовой стрелки.
                case Imagick::ORIENTATION_LEFTBOTTOM:
                    $this->imagick->rotateimage("#000", -90);
                    break;
            }
        } catch (ImagickException $e) {
            throw new ImagingException(
                __('imaging.imagick_exception', ['except' => $e->getMessage()]),
                $e->getCode(),
                $e->getPrevious()
            );
        }
    }

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    protected function saveToFile(string $pathname, ImageType $imagetype): void
    {
        $this->prepareImageToOutput($imagetype);

        try {
            //$this->imagick->writeImage($pathname);
            file_put_contents($pathname, $this->imagick->getImageBlob());
        } catch (ImagickException $e) {
            throw new ImagingException(
                __('imaging.imagick_exception', ['except' => $e->getMessage()]),
                $e->getCode(),
                $e->getPrevious()
            );
        }
    }

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    protected function getBlob(ImageType $imagetype): string
    {
        $this->prepareImageToOutput($imagetype);

        try {
            return $this->imagick->getImageBlob();
        } catch (ImagickException $e) {
            throw new ImagingException(
                __('imaging.imagick_exception', ['except' => $e->getMessage()]),
                $e->getCode(),
                $e->getPrevious()
            );
        }
    }

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    public function sizes(): array
    {
        try {
            return [
                $this->imagick->getImageWidth(),
                $this->imagick->getImageHeight(),
            ];
        } catch (ImagickException $e) {
            throw new ImagingException(
                __('imaging.imagick_exception', ['except' => $e->getMessage()]),
                $e->getCode(),
                $e->getPrevious()
            );
        }
    }

    // endregion

    // region Методы обработки изображения.

    /**
     * @inheritDoc
     */
    protected function _crop(
        int|string $x1,
        int|string|null $y1,
        int|string|null $x2,
        int|string|null $y2,
        bool $add_padding = false
    ): bool|string
    {
        [$x1, $y1, $x2, $y2] = $this->prepareCropCoords($x1, $y1, $x2, $y2);

        $width = $x2 - $x1;
        $height = $y2 - $y1;

        try {
            $this->imagick->cropImage($width, $height, $x1, $y1);
            $this->imagick->setImagePage(0, 0, 0, 0);

            return true;
        } catch (ImagickException $e) {
            return __('imaging.imagick_exception', ['except' => $e->getMessage()]);
        }
    }

    /**
     * @inheritDoc
     */
    protected function _resize(
        int|string|null $width,
        int|string|null $height = null,
        bool $keep_ratio = true,
        bool $pad = false
    ): bool|string
    {
        try {
            [$width, $height, $new_image_width, $new_image_height]
                = $this->prepareResizeCoords($width, $height, $keep_ratio, $pad);

            $this->imagick->scaleImage($width, $height, $keep_ratio);

            if ($pad) {
                $tmp_image = new Imagick();

                $bgcolor = $this->getConfBgColor($this->origin_imagetype);

                $tmp_image->newImage(
                    $new_image_width,
                    $new_image_height,
                    $this->createColorFromHex($bgcolor,  $bgcolor === null ? 0 : 100),
                    'png'
                );

                $tmp_image->compositeImage(
                    $this->imagick,
                    Imagick::COMPOSITE_DEFAULT,
                    ($new_image_width - $width) / 2,
                    ($new_image_height - $height) / 2
                );

                $this->imagick = $tmp_image;
            }

            return true;
        } catch (ImagickException|ImagickPixelException $e) {
            return __('imaging.imagick_exception', ['except' => $e->getMessage()]);
        } catch (ImagingException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @inheritDoc
     */
    protected function _watermark(
        SplFileInfo $filename,
        XPositionType|int $x_pos,
        YPositionType|int $y_pos,
        int $x_pad,
        ?int $y_pad
    ): bool|string
    {
        try {
            [$x, $y] = $this->prepareWatermarkParams($filename, $x_pos, $y_pos, $x_pad, $y_pad);

            $wm_image = new Imagick();

            $wm_image->readImage($filename);
            $wm_image->evaluateImage(
                Imagick::EVALUATE_MULTIPLY,
                $this->watermark_alpha / 100,
                Imagick::CHANNEL_ALPHA
            );
            $this->imagick->compositeImage($wm_image, Imagick::COMPOSITE_DEFAULT, $x, $y);

            return true;
        } catch (ImagickException|InvalidArgumentException $e) {
            return __('imaging.imagick_exception', ['except' => $e->getMessage()]);
        } catch (ImagingException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @inheritDoc
     */
    protected function _rotate(int $degrees): bool|string
    {
        try {
            $degrees = $this->prepareRotateDegrees($degrees);
            $bgcolor = $this->getConfBgColor($this->origin_imagetype);

            $this->imagick->rotateImage(
                $this->createColorFromHex($bgcolor,  $bgcolor === null ? 0 : 100),
                $degrees
            );

            return true;
        } catch (ImagickException|ImagickPixelException $e) {
            return __('imaging.imagick_exception', ['except' => $e->getMessage()]);
        } catch (ImagingException $e) {
            return $e->getMessage();
        }
    }

    // endregion

    // region Служебные методы

    /**
     * Добавить фон на текущее изображение.
     *
     * @param ImageType $imagetype
     * @return void
     *
     * @throws ImagingException
     */
    private function addBackground(ImageType $imagetype): void
    {
        try {
            // Или это не прозрачное изображение или цвет фона требуется.
            if (in_array($imagetype, self::NOT_TRANSPIRED_IMAGETYPES) || $this->bgcolor !== null) {
                [$width, $height] = $this->sizes();
                $bgcolor = $this->getConfBgColor($imagetype);

                $tmp_image = new Imagick();
                $tmp_image->newImage(
                    $width,
                    $height,
                    $this->createColorFromHex($bgcolor,  $bgcolor === null ? 0 : 100),
                    'png'
                );

                $tmp_image->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);
                $tmp_image->setImageArtifact('compose:args', "1,0,-0.5,0.5");

                $tmp_image->compositeImage(
                    $this->imagick, // Накладываем текущее изображение.
                    Imagick::COMPOSITE_DEFAULT,  // Imagick::CHANNEL_DEFAULT // Imagick::COMPOSITE_MATHEMATICS
                    0,
                    0
                );

                $this->imagick = $tmp_image;
            }
        } catch (ImagickException|ImagickPixelException $e) {
            throw new ImagingException(
                __('imaging.imagick_exception') . ' ' . $e->getMessage(),
                $e->getCode(),
                $e->getPrevious()
            );
        }
    }

    /**
     * Получить цвет фона из настроек в зависимости от типа изображения.
     *
     * @param ImageType $imagetype
     * @return string|null
     */
    private function getConfBgColor(ImageType $imagetype): ?string
    {
        $bgcolor = $this->bgcolor;

        if (in_array($imagetype, self::NOT_TRANSPIRED_IMAGETYPES) && $bgcolor === null) {
            $bgcolor = $this->second_bgcolor;
        }

        return $bgcolor;
    }

    /**
     * Создает новый цвет с помощью Imagick.
     *
     * @param string|null $hex Hex код цвета.
     * @param int|null $alpha Прозрачность цвета (если задано - не использовать прозрачность переданную с hex);
     *                        от 0 (полностью прозрачное изображение) до 100 (не прозрачное).
     * @return ImagickPixel RGBA представление hex цвета и alpha канала.
     *
     * @throws ImagickPixelException
     * @throws ImagingException
     */
    protected function createColorFromHex(?string $hex, ?int $alpha = null): ImagickPixel
    {
        [$red, $green, $blue, $def_alpha] = self::createDecColorFromHex($hex);

        $alpha === null and $alpha = $def_alpha;

        return new ImagickPixel(
            'rgba(' . $red . ', ' . $green . ', ' . $blue . ', ' . round($alpha / 100, 2) . ')'
        );
    }


    /**
     * Получить изображение как бинарную строку.
     *
     * @param ImageType $imagetype
     * @return void
     *
     * @throws ImagingException
     */
    private function prepareImageToOutput(ImageType $imagetype): void
    {
        $this->addBackground($imagetype);

        try {
            if ($this->imagick->getImageFormat() !== $imagetype->ext()) {
                $this->imagick->setImageFormat($imagetype->ext());
            }

            if(
                $this->quality > 0
                && $this->quality < 100
                && in_array($imagetype, self::NOT_TRANSPIRED_IMAGETYPES)
            ) {
                $this->imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
                $this->imagick->setImageCompressionQuality($this->quality);
                $this->imagick->stripImage();
            }
        } catch (ImagickException $e) {
            throw new ImagingException(
                __('imaging.imagick_exception', ['except' => $e->getMessage()]),
                $e->getCode(),
                $e->getPrevious()
            );
        }
    }

    // endregion
}
