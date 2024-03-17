<?php

declare(strict_types=1);
namespace Whiterhino\Imaging\Handlers;

use GdImage;
use SplFileInfo;
use Whiterhino\Imaging\Exceptions\ImagingException;
use Whiterhino\Imaging\Types\ImageType;
use Whiterhino\Imaging\Types\XPositionType;
use Whiterhino\Imaging\Types\YPositionType;

// Драйвер обработки изображений на основе GD.
final class GdHandler extends AbstractHandler
{
    // Допустимые расширения изображений для данного драйвера.
    protected const ACCEPTED_IMAGETYPES = [ImageType::PNG, ImageType::GIF, ImageType::JPEG, ImageType::WEBP];

    // Функция используемая для изменения размеров изображения.
    protected const GD_RESIZE_FUNC = "imagecopyresampled";

    /** @var GdImage Экземпляр GdImage текущего обрабатываемого изображения. */
    protected GdImage $image;

    /** @var int Ширина обрабатываемого изображения. */
    protected int $width;

    /** @var int Высота обрабатываемого изображения. */
    protected int $height;

    // region Реализация абстрактного класса

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    protected function loadFromFile(): void
    {
        if (isset($this->image)) {
            imagedestroy($this->image);
        }

        [$this->image, $this->width, $this->height]
            = self::loadGdImage($this->file, $this->origin_imagetype);
    }

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    protected function saveToFile(string $pathname, ImageType $imagetype): void
    {
        $this->addBackground();

        $process_function = 'image' . $imagetype->ext();
        $vars = [$this->image, $pathname];

        if ($imagetype === ImageType::JPEG) {
            $vars[] = $this->quality;
        }

        if (!self::callUserFuncArray($process_function, $vars)) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => $process_function]));
        }
    }

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    protected function getBlob(ImageType $imagetype): string
    {
        $this->addBackground();

        $process_function = 'image' . $imagetype->ext();
        $vars = [$this->image, null];

        if ($imagetype === ImageType::JPEG) {
            $vars[] = $this->quality;
        }

        ob_start();
        $result = self::callUserFuncArray($process_function, $vars);
        $blob_str = ob_get_clean();

        if (!$result) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => $process_function]));
        }

        return $blob_str;
    }

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    public function sizes(): array
    {
        $width  = imagesx($this->image);
        $height = imagesy($this->image);

        if ($width === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagesx']));
        }

        if ($height === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagesy']));
        }

        return [$width, $height];
    }

    // endregion

    // region Методы обработки изображения.

    // Все методы должны начитаться с '_' и возвращать true в случае успеха
    // или строку с описанием ошибки в противном случае.

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

        $crop_width = $width;
        $crop_height = $height;

        // Если вырезаемый кусок из исходного изображения "вылезает" за его пределы ...
        if ($x1 + $width > $this->width) {

            $crop_width = $this->width - $x1;
        }

        if ($y1 + $height > $this->height) {
            $crop_height = $this->height - $y1;
        }

        if (!$add_padding) {
            $width = $crop_width;
            $height = $crop_height;
        }

        try {
            $image = $this->createTransparentImage($width, $height);
        } catch (ImagingException $e) {
            return $e->getMessage();
        }

        if (imagecopy($image, $this->image, 0, 0, $x1, $y1, $crop_width, $crop_height)) {
            $this->image = $image;

            return true;
        }

        return __('imaging.unknown_gd_error', ['func' => 'imagecopy']);
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
            [$width, $height, $new_image_width, $new_image_height, $dist_x, $dist_y, $src_width, $src_height]
                = $this->prepareResizeCoords($width, $height, $keep_ratio, $pad);

            $image = $this->createTransparentImage($new_image_width, $new_image_height);
        } catch (ImagingException $e) {
            return $e->getMessage();
        }


        if (call_user_func(
            self::GD_RESIZE_FUNC,
            $image, $this->image, $dist_x, $dist_y, 0, 0, $width, $height, $src_width, $src_height
        )) {
            $this->image = $image;

            return true;
        }

        return __('imaging.unknown_gd_error', ['func' => self::GD_RESIZE_FUNC]);
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
            [$x, $y, $wm_width, $wm_height] = $this->prepareWatermarkParams($filename, $x_pos, $y_pos, $x_pad, $y_pad);

            [$watermark,,] = self::loadGdImage($filename);

            // Корд ниже нужен для предотвращения сбоя в GD с отрицательными координатами по X.
            if ($x < 0 || $y < 0) {
                // Новая длинна и ширина для водного знака.
                $new_wm_width = ($x < 0) ? ($wm_width + $x) : $wm_width;
                $new_wm_height = ($y < 0) ? ($wm_height + $y) : $wm_height;

                // Создадим прозрачное изображение размерами нового водного знака.
                $tmp_watermark = $this->createTransparentImage($new_wm_width, $new_wm_height);

                if(!imagecopy(
                    $tmp_watermark,
                    $watermark,
                    0, 0,
                    $x < 0 ? abs($x) : 0,
                    $y < 0 ? abs($y) : 0,
                    $new_wm_width,
                    $new_wm_height
                )) {
                    return __('imaging.unknown_gd_error', ['func' => 'imagecopy']);
                }

                // Установим переменные для объединения изображений.
                $watermark = $tmp_watermark;
                $x = max($x, 0);
                $y = max($y, 0);
            }

            // Используется в качестве обходного пути из-за отсутствия альфа-поддержки в imagecopymerge.
            $this->imageMerge($this->image, $watermark, $x, $y, $this->watermark_alpha);

            return true;
        } catch (ImagingException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @inheritDoc
     */
    protected function _rotate(int $degrees): bool|string
    {
        $degrees = $this->prepareRotateDegrees($degrees);

        $bgcolor = $this->bgcolor;

        if (
			$bgcolor === null
			&& in_array($this->origin_imagetype, self::NOT_TRANSPIRED_IMAGETYPES, true)
		) {
            $bgcolor = $this->second_bgcolor;
        }

        try {
            $color = self::createColorFromHex($this->image, $bgcolor, 100);

            $result = imagerotate($this->image, 360 - $degrees, $color);

            if ($result === false) {
                return __('imaging.unknown_gd_error', ['func' => 'imagerotate']);
            }

            $this->image = $result;

            return true;
        } catch (ImagingException $e) {
            return $e->getMessage();
        }
    }

    // endregion

    // region Вспомогательные статические методы

    /**
     * Загружает изображение из файла.
     *
     * @param SplFileInfo $file
     * @param ImageType|null $imagetype
     * @return array
     *
     * @throws ImagingException
     */
    private static function loadGdImage(SplFileInfo $file, ImageType $imagetype = null): array
    {
        // Если тип изображения не передан, пробуем определить тип изображения по расширению.
        if ($imagetype === null) {
            $imagetype = ImageType::getType($file->getExtension());

            if ($imagetype === null) {
                throw new ImagingException(__(
                    'imaging.unknown_imagetype',
                    ['type' => $file->getExtension()]
                ));
            }
        }

        $process_function = 'imagecreatefrom' . $imagetype->ext();

        if (!function_exists($process_function)) {
            throw new ImagingException(__(
                'imaging.gd_ext_missing',
                ['extension' => $process_function]
            ));
        }

        // Загрузим изображение из файла.
        [$width, $height] = self::sizesOfImageFile($file->getPathname());

        if ($imagetype === ImageType::WEBP) {
            $info = self::webpInfo($file->getPathname());

            // Если не удалось определить информацию об webp файле, проверку не делаем.
            if (($info !== null) && isset($info['animation']) && $info['animation'] === true) {
                throw new ImagingException(__('imaging.gd_webp_animation_error'));
            }
        }

        $tmp_image = $process_function($file->getPathname());

        if ($tmp_image === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => $process_function]));
        }

        return [$tmp_image, $width, $height];

//        // Создадим новое прозрачное изображение и наложим на него загруженное.
//        $image = $this->createTransparentImage($width, $height, $imagetype);
//
//        if (!imagecopy($image, $tmp_image, 0, 0, 0, 0, $width, $height)) {
//            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagecopy']));
//        }
//
//        return [$image, $width, $height];
    }

	/**
	 * Создать новый цвет с помощью GD.
	 *
	 * @param GdImage $image Изображение на основе которого создается цвет.
	 * @param string|null $hex Hex код цвета (null создаст черный цвет прозрачности alpha, если-же alpha тоже null, то будет создано полностью прозрачный черный цвет).
	 * @param int|null $alpha Прозрачность цвета (если задано, то не использовать прозрачность переданную с hex); от 0 (полностью прозрачное изображение) до 100 (не прозрачное).
	 * @return int RGBA представление hex цвета и alpha канала.
	 *
	 * @throws ImagingException
	 */
	private static function createColorFromHex(GdImage $image, ?string $hex, ?int $alpha = null): int
	{
		// Если цвет не передан, то будет взят полностью прозрачный черный цвет.
		[$red, $green, $blue, $def_alpha] = self::createDecColorFromHex($hex);

		if ($hex === null && $alpha === null) {
			$alpha = 127;
		} else {
			$alpha === null and $alpha = $def_alpha;

			// Конвертируем alpha в понятный imagecolorallocatealpha функции формат.
			$alpha = 127 - (int)floor($alpha * 1.27);
		}

		$result = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);

		if ($result === false) {
			throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagecolorallocatealpha']));
		}

		return $result;
	}

    /**
     * Get WebP file info.
     *
     * @link https://www.php.net/manual/en/function.pack.php unpack format reference.
     * @link https://developers.google.com/speed/webp/docs/riff_container WebP document.
     * @param string $file
     * @return array|null Return associative array if success, return `null` for otherwise.
     */
    private static function webpInfo(string $file): array|null
    {
        $file = realpath($file);

        $fp = fopen($file, 'rb');
        if (!$fp) {
            return null;
        }
        $data = fread($fp, 90);
        fclose($fp);
        unset($fp);

        $header_format = 'A4Riff/'  // Get n string.
            . 'I1Filesize/'         // Get integer (file size but not actual size).
            . 'A4Webp/'             // Get n string.
            . 'A4Vp/'               // Get n string.
            . 'A74Chunk';
        $header = unpack($header_format, $data);

        if (!isset($header['Riff']) || strtoupper($header['Riff']) !== 'RIFF') {
            return null;
        }

        if (!isset($header['Webp']) || strtoupper($header['Webp']) !== 'WEBP') {
            return null;
        }

        if (!isset($header['Vp']) || !str_contains(strtoupper($header['Vp']), 'VP8')) {
            return null;
        }

        $answer = [];

        if (
            str_contains(strtoupper($header['Chunk']), 'ANIM') ||
            str_contains(strtoupper($header['Chunk']), 'ANMF')
        ) {
            $answer['animation'] = true;
        } else {
            $answer['animation'] = false;
        }

        if (str_contains(strtoupper($header['Chunk']), 'ALPH')) {
            $answer['alpha'] = true;
        } elseif (str_contains(strtoupper($header['Vp']), 'VP8L')) {
			// if it is VP8L, I assume that this image will be transparency
			// as described in https://developers.google.com/speed/webp/docs/riff_container#simple_file_format_lossless
			$answer['alpha'] = true;
		} else {
			$answer['alpha'] = false;
		}

        return $answer;
    }

    // endregion

    // region Вспомогательные закрытые методы.

    /**
     * Создает новое прозрачное изображение размера $width x $height.
     *
     * @param int $width Ширина создаваемого изображения.
     * @param int $height Высота создаваемого изображения.
     * @return GdImage
     *
     * @throws ImagingException
     */
    private function createTransparentImage(
        int $width,
        int $height
    ): GdImage
    {
        // 1. Создаем чистое, полноцветное, прозрачное изображение.
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagecreatetruecolor']));
        }

        // Указываем, что это изображение имеет альфа канал.
        $result = imagesavealpha($image, true);
        if ($result === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagesavealpha']));
        }

        // 2. Определяем цвет фона и создаем сам цвет.
        // Отметим, что фоновый цвет ни как не влияет на будущую прозрачность изображения (например, при сохранении).
        $hex_color = $this->bgcolor;

        if (
            $hex_color === null
            && in_array($this->origin_imagetype, self::NOT_TRANSPIRED_IMAGETYPES, true)
        ) {
            $hex_color = $this->second_bgcolor;
        }

        $bgcolor = self::createColorFromHex($image, $hex_color, 0);

        // 3. Добавляем фоновый цвет в изображение.

        // Устанавливаем цвет, для этого переводим режим наложения в значение false,
        // добавляем фоновый цвет, затем переключаем его обратно.
        $result = imagealphablending($image, false);
        if ($result === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagealphablending']));
        }

        $result = imagefilledrectangle($image, 0, 0, $width, $height, $bgcolor);
        if ($result === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagefilledrectangle']));
        }

        $result = imagealphablending($image, true);
        if ($result === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagealphablending']));
        }

        return $image;
    }

    /**
     * Объединяет изображения вместе.
     *
     * @param GdImage $bottom_image Изображение на которое накладывают изображение (нижнее).
     * @param GdImage $top_image Накладываемое изображение (верхнее).
     * @param int $x Положение верхнего изображения на X-оси.
     * @param int $y Положение верхнего изображения на Y-оси.
     * @param int $alpha Прозрачность верхнего изображения от 0 (прозрачное) до 100 (не прозрачное).
     * @throws ImagingException
     */
    private function imageMerge(
        GdImage $bottom_image,
        GdImage $top_image,
        int $x,
        int $y,
        int $alpha
    ): void
    {
        $top_image_width = imagesx($top_image);
        $top_image_height = imagesy($top_image);
        if ($top_image_width === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagesx']));
        }
        if ($top_image_height === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagesy']));
        }

        $tmp_image = $this->createTransparentImage($top_image_width, $top_image_height);

        $result_1 = imagecopy($tmp_image, $bottom_image, 0, 0, $x, $y, $top_image_width, $top_image_height);
        $result_2 = imagecopy($tmp_image, $top_image, 0, 0, 0, 0, $top_image_width, $top_image_height);
        if ($result_1 === false || $result_2 === false) {
            throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagecopy']));
        }

        if (imagecolortransparent($top_image) === -1) {
            $result = imagealphablending($bottom_image, false);
            if ($result === false) {
                throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagealphablending']));
            }

            $result = imagecopymerge($bottom_image, $tmp_image, $x, $y, 0, 0, $top_image_width, $top_image_height, $alpha);
            if ($result === false) {
                throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagecopymerge']));
            }

            $result = imagealphablending($bottom_image, true);
            if ($result === false) {
                throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagealphablending']));
            }
        } else {
            $result = imagecopy($bottom_image, $tmp_image, $x, $y, 0, 0, $top_image_width, $top_image_height);
            if ($result === false) {
                throw new ImagingException(__('imaging.unknown_gd_error', ['func' => 'imagecopy']));
            }
        }
    }

    /**
     * Добавить фон на текущее изображение.
     *
     * @return void
     *
     * @throws ImagingException
     */
    private function addBackground(): void
    {
        // Нужно прозрачное изображение.
        if ($this->bgcolor === null) {
            return;
        }

        [$width, $height] = $this->sizes();

        // Создадим прозрачное изображение.
        $bg_image = $this->createTransparentImage($width, $height);

        // Создадим непрозрачный цвет.
        $color = self::createColorFromHex($bg_image, $this->bgcolor, 100);

        // Заливаем изображение непрозрачным цветом.
        imagefill($bg_image, 0, 0, $color);

        $this->imageMerge($bg_image, $this->image, 0, 0, 100);
        $this->image = $bg_image;
    }

    // endregion
}

