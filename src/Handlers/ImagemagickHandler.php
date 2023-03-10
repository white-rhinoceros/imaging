<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Handlers;

use Illuminate\Support\Str;
use SplFileInfo;
use Whiterhino\Imaging\Exceptions\ImagingException;
use Whiterhino\Imaging\Types\ImageType;
use Whiterhino\Imaging\Types\XPositionType;
use Whiterhino\Imaging\Types\YPositionType;

/**
 * Драйвер обработки изображений на основе Imagemagick.
 */
final class ImagemagickHandler extends AbstractHandler
{
    // Поддиректория во временной директории для работы драйвера.
    protected const TMP_SUBDIR = 'imagemagick';

    // Допустимые расширения изображений для данного драйвера.
    protected const ACCEPTED_IMAGETYPES = [ImageType::PNG, ImageType::GIF, ImageType::JPEG];

    /** @var string Расположение исполняемого файла imagemagick.  */
    private string $imagemagick_dir = '/usr/bin/';

    /** @var string Директория для хранения временных файлов. */
    private string $temp_dir;

    /** @var string Временное изображение, с которым проводятся все манипуляции. */
    private string $temp_image;

    /** @var string Хеш имени временного изображения. */
    private string $temp_image_hash;

    /** @var array Кеш размеров изображений. */
    private array $sizes_cache = [];

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
        parent::__construct($file, $force_imagetype, $config);

        if (isset($config['imagemagick_dir'])) {
            $this->imagemagick_dir = rtrim($config['imagemagick_dir'], '\\/') . DIRECTORY_SEPARATOR;
        }

        $this->temp_dir = ($config['temp_dir'] ?? sys_get_temp_dir()) . DIRECTORY_SEPARATOR . self::TMP_SUBDIR;

        if (
            !is_dir($this->temp_dir)
            && !mkdir($this->temp_dir)
            && !is_dir($this->temp_dir)
        ) {
            throw new ImagingException(__(
                'imaging.creating_folder_failed',
                ['folder' => $this->temp_dir]
            ));
        }

        if (!touch($this->temp_dir . 'touch')) {
            throw new ImagingException(__(
                'imaging.folder_access_denied',
                ['folder' => $this->temp_dir]
            ));
        }
    }

    /**
     * Деструктор.
     */
    public function __destruct()
    {
        if (is_file($this->temp_image)) {
            unlink($this->temp_image);
        }
    }

    // region Реализация абстрактного класса

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    public function loadFromFile(): void
    {
        $this->clearSizesCache();

        // Попробуем создать файл со временным изображением.
        if (!isset($this->temp_image)) {
            $this->temp_image = $this->generateTmpFilename(ImageType::PNG->ext());
        } elseif (is_file($this->temp_image)) {
            unlink($this->temp_image);
        }

        $this->temp_image_hash = md5($this->temp_image);

        $this->exec(
            'convert',
            "-auto-orient '" . $this->file->getPathname() . "'[0] '" . $this->temp_image . "'"
        );
    }

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    protected function saveToFile(string $pathname, ImageType $imagetype): void
    {
        $this->addBackground($imagetype);

        $old = "'" . $this->temp_image . "'";
        $new = "'" . $pathname . "'";

        if(
            in_array($imagetype, self::NOT_TRANSPIRED_IMAGETYPES)
            && $this->quality > 0
            && $this->quality < 100
        ) {
            $quality = "'" . $this->quality . "%'";
            $this->exec(
                'convert',
                $old . ' -auto-orient -quality ' . $quality . ' ' . $new
            );
        } else {
            $this->exec(
                'convert',
                $old . ' ' . $new
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
        $this->addBackground($imagetype);

        $image = "'" . $this->temp_image . "'";

        if (
            in_array($imagetype, self::NOT_TRANSPIRED_IMAGETYPES)
            && $this->quality > 0
            && $this->quality < 100
        ) {
            $quality = "'" . $this->quality . "%'";

            ob_start();

            $this->exec(
                'convert',
                $image . ' -auto-orient -quality ' . $quality . ' ' . strtolower($imagetype->ext()) . ':-',
                true
            );

            return ob_get_clean();
        }

        if(!str_ends_with($this->temp_image, $imagetype->ext())) {
            ob_start();

            $this->exec(
                'convert',
                $image . ' -auto-orient ' . strtolower($imagetype->ext()) . ':-',
                true
            );

            return ob_get_clean();
        }

        return file_get_contents($this->temp_image);
    }

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    public function sizesOfImageFile(?string $pathname): array
    {
        if ($pathname === null) {
            $pathname = $this->temp_image;
            $fileHash = $this->temp_image_hash;
        } else {
            $fileHash = md5($pathname);
        }

        if ($this->sizes_cache[$fileHash]) {
            $size = $this->sizes_cache[$fileHash];
        } else {
            $output = $this->exec(
                'identify',
                "-format '%w %h' '" . $pathname . "'[0]"
            );

            [$width, $height] = explode(" ", $output[0]);
            $size = [$width, $height];

            $this->sizes_cache[$fileHash] = $size;
        }

        return $size;
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
        int|string|null $y2
    ): bool|string
    {
        [$x1, $y1, $x2, $y2] = $this->prepareCropCoords($x1, $y1, $x2, $y2);

        $image = "'" . $this->temp_image . "'";

        try {
            $this->exec(
                'convert',
                $image . ' -auto-orient -crop ' . ($x2 - $x1) . 'x' . ($y2 - $y1) . '+' . $x1 . '+' . $y1 . ' +repage ' . $image
            );
        } catch (ImagingException $e) {
            return $e->getMessage();
        }

        $this->clearSizesCache();

        return true;
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

            $image = "'" . $this->temp_image . "'";

            $this->exec(
                'convert',
                "-auto-orient -define png:size=" . $new_image_width . "x" . $new_image_height
                . " " . $image . " -background none"
                . " -resize \"" . ($pad ? $width : $new_image_width) . "x" . ($pad ? $height : $new_image_height) . "!\""
                . " -gravity center"
                . " -extent " . $new_image_width . "x" . $new_image_height . " " . $image
            );
        } catch (ImagingException $e) {
            return $e->getMessage();
        }

        $this->clearSizesCache();

        return true;
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

            $x >= 0 and $x = '+' . $x;
            $y >= 0 and $y = '+' . $y;

            $image = "'" . $this->temp_image . "'";

            $this->exec(
                'composite',
                '-compose atop -geometry ' . $x . $y . ' ' .
                '-dissolve ' . $this->watermark_alpha . '% ' .
                '"' . $filename . '" "' . $this->temp_image . '" ' . $image
            );
        } catch (ImagingException $e) {
            return $e->getMessage();
        }

        $this->clearSizesCache();

        return true;
    }

    /**
     * @inheritDoc
     */
    protected function _rotate(int $degrees): bool|string
    {
        $degrees = $this->prepareRotateDegrees($degrees);

        $image = "'" . $this->temp_image . "'";

        try {
            $this->exec(
                'convert',
                $image . " -background none -auto-orient -virtual-pixel background +distort ScaleRotateTranslate " . $degrees . " +repage " . $image
            );
        } catch (ImagingException $e) {
            return $e->getMessage();
        }

        $this->clearSizesCache();

        return true;
    }

    // endregion

    // region Вспомогательные методы

    /**
     * Очистить кеш размеров текущего изображения.
     */
    private function clearSizesCache(): void
    {
        unset($this->sizes_cache[$this->temp_image_hash]);
    }

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
        if (in_array($imagetype, self::NOT_TRANSPIRED_IMAGETYPES) || $this->bgcolor !== null) {
            $bgcolor = $this->bgcolor;

            if (in_array($imagetype, self::NOT_TRANSPIRED_IMAGETYPES) && $bgcolor === null) {
                $bgcolor = $this->second_bgcolor;
            }

            $image = "'" . $this->temp_image . "'";

            $color = $this->createColorFromHex($bgcolor, 100);
            [$width, $height] = $this->sizes();

            $command = '-auto-orient -size ' . $width . 'x' . $height . ' ' . 'canvas:' . $color . ' '
                . $image.' -composite '.$image;

            $this->exec(
                'convert',
                $command
            );
        }
    }

    /**
     * Выполнить imagemagick команду и вернуть ее вывод.
     *
     * @param string $program Команда.
     * @param string $params Параметры.
     * @param boolean $passthru Вернуть вывод команды в случае false или отобразить результат.
     * @return array Результат выполнения.
     *
     * @throws ImagingException
     */
    private function exec(string $program, string $params, bool $passthru = false): array
    {
        $imagemagick_program = realpath($this->imagemagick_dir . $program);

        if ($imagemagick_program === false) {
            $imagemagick_program = realpath($this->imagemagick_dir . $program . '.exe');
        }

        if ($imagemagick_program === false) {
            throw new ImagingException(__(
                'imaging.imagemagick_program_not_found',
                [
                    'program' => $this->imagemagick_dir . $program,
                ]
            ));
        }

        $command = $imagemagick_program . " " . $params;

        $code = 0;
        $output = [];

        $passthru ? passthru($command) : exec($command, $output, $code);

        if ($code !== 0) {
            throw new ImagingException(__(
                'imaging.imagemagick_error_occurred',
                [
                    'code' => $code,
                    'command' => $command,
                ]
            ));
        }

        return $output;
    }

    /**
     * @param string $ext
     * @return string
     */
    private function generateTmpFilename(string $ext): string
    {
        $ext = trim($ext, '.');

        do {
            $imageTemp = $this->temp_dir . Str::random(32) . '.' . $ext;
        } while (is_file($imageTemp));

        return $imageTemp;
    }

    /**
     * Создает новый цвет с помощью ImageMagick.
     *
     * @param string $hex Hex код цвета.
     * @param int|null $alpha Прозрачность цвета (если задано - не использовать прозрачность переданную с hex);
     *                        от 0 (полностью прозрачное изображение) до 100 (не прозрачное).
     * @return string RGBA представление hex цвета и alpha канала.
     *
     * @throws ImagingException
     */
    private function createColorFromHex(string $hex, ?int $alpha = null): string
    {
        [$red, $green, $blue, $def_alpha] = $this->createDecColorFromHex($hex);

        $alpha === null and $alpha = $def_alpha;

        return "\"rgba(" . $red . ", " . $green . ", " . $blue . ", " . round($alpha / 100, 2) . ")\"";
    }

    // endregion
}
