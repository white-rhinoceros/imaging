<?php

namespace Whiterhino\Imaging;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use SplFileInfo;
use Whiterhino\Imaging\Exceptions\ImagingException;
use Whiterhino\Imaging\Handlers\HandlerContract;

class Imaging
{
    // Константы определяющие способы изменения размера изображений.
    public const RESIZE_MODE_PAD = 'pad'; // Добавить поля цветом фона.
    public const RESIZE_MODE_STRETCH = 'stretch'; // Растянуть.
    public const RESIZE_MODE_KEEPRATIO = 'keepratio'; // Сохранить пропорции, уменьшив по меньшей пропорции.

    // Константы определяющие способы обрезки изображений.
    public const CROP_MODE_IGNORE = 'ignore';
    public const CROP_MODE_ADDPADDING = 'addpadding';

    // Константы определяющие способ наложения водного знака.
    public const WATERMARK_MODE_SINGLE = 'single';
    public const WATERMARK_MODE_FILL = 'fill';

    /**
     * Обрезать изображение используя координаты или проценты.
     * Положительные целые числа или проценты суть координаты отсчитываемые от верхнего, левого угла.
     * Отрицательные целые числа или проценты суть координаты отсчитываемые от нижнего, правого угла.
     *
     * Пример вызова:
     *     crop('public:image.jpg', 10, '10%') - обрезать изображение на 10 пикселей с лева и справа и на 10% сверху и снизу;
     *     crop('public:image.jpg', 20) - обрезать по кругу на 20 пикселей;
     *     crop('public:image.jpg', 10, 30, '30%') - сверху 10 пикселей, с лева 30 пикселей, снизу и справа 30%.
     *
     * @param string $filepath
     * @param string|null $disk
     * @param int|string $x1 X - координата первого набора.
     * @param int|string|null $y1 Y - координата первого набора.
     * @param int|string|null $x2 X - координата второго набора.
     * @param int|string|null $y2 Y - координата второго набора.
     * @param string $mode
     * @return array [Файл, Ссылка на обработанный файл]
     *
     * @throws ImagingException
     */
    public static function crop(
        string $filepath,
        ?string $disk,
        int|string $x1,
        int|string|null $y1,
        int|string|null $x2,
        int|string|null $y2,
        string $mode = self::CROP_MODE_IGNORE
    ): array
    {
        $cached = self::getFilenameForCache(
            $disk,
            $filepath,
            '-crop-' . $mode . '-' . $x1 . 'x' . $y1 . 'x' . $x2 . 'x' . $y2
        );

        $manager = App::make(ImageManager::class, [$disk]);

        $processed_file = $manager->make(
            $filepath,
            $cached,
            fn(HandlerContract $image) => $image->crop($x1, $y1, $x2, $y2, !($mode === self::CROP_MODE_IGNORE))
        );

        return [$processed_file, $manager->generateUrl($processed_file)];
    }

    /**
     * Изменяет размер изображения.
     *
     * @param string $filepath
     * @param string|null $disk
     * @param int $width Новая ширина.
     * @param int $height Новая длина.
     * @param string $mode Константа метода изменения изображения.
     * @return array [Файл, Ссылка на обработанный файл]
     *
     * @throws ImagingException
     */
    public static function resize(
        string $filepath,
        ?string $disk,
        int $width,
        int $height,
        string $mode = self::RESIZE_MODE_PAD
    ): array
    {
        $cached = self::getFilenameForCache(
            $disk,
            $filepath,
            '-resize-' . $mode . '-' . $width . 'x' . $height
        );

        $manager = App::make(ImageManager::class, [$disk]);

        $processed_file = $manager->make(
            $filepath,
            $cached,
            function(HandlerContract $image) use ($width, $height, $mode) {
                // Учитываем режим изменение размера.
                switch ($mode) {
                    case self::RESIZE_MODE_STRETCH:
                        $image->resize($width, $height, false);
                        break;

                    case self::RESIZE_MODE_KEEPRATIO:
                        $image->resize($width, $height, true, false);
                        break;

                    case self::RESIZE_MODE_PAD:
                    default:
                        $image->resize($width, $height, true, true);
                }
            }
        );

        return [$processed_file, $manager->generateUrl($processed_file)];
    }

    /**
     * Добавляет водный знак на изображение.
     *
     * @param string $filepath
     * @param string|null $disk
     * @param string|null $filename Файл водного знака (абсолютное расположение).
     * @param string $mode Режим создания водного знака.
     * @return array [Файл, Ссылка на обработанный файл]*
     * @throws ImagingException
     */
    public static function watermark(
        string $filepath,
        ?string $disk,
        ?string $filename,
        string $mode = self::WATERMARK_MODE_SINGLE,
    ): array
    {
        /** @var string $def_wm */
        $def_wm = Config::get('imaging.watermark_filename');
        $filename = $filename ?? $def_wm;

        if (!is_readable($filename)) {
            if (Config::get('imaging.debug')) {
                throw new ImagingException(__(
                    'imaging.file_is_unreadable',
                    ['file' => $filename]
                ));
            }

            return [];
        }

        $cached = self::getFilenameForCache(
            $disk,
            $filepath,
            '-watermark-' . md5($filename)
        );

        $manager = App::make(ImageManager::class, [$disk]);

        $processed_file = $manager->make(
            $filepath,
            $cached,
            function(HandlerContract $image) use ($filename) {
                $image->watermark(
                    new SplFileInfo($filename),
                    Config::get('imaging.watermark_x_position'),
                    Config::get('imaging.watermark_y_position'),
                    Config::get('imaging.watermark_x_pad'),
                    Config::get('imaging.watermark_y_pad')
                );
            }
        );

        return [$processed_file, $manager->generateUrl($processed_file)];
    }

    /**
     * Изменяет размер изображения и добавляет водный знак.
     *
     * @param string $filepath
     * @param string|null $disk
     * @param int $width Новая ширина.
     * @param int $height Новая длина.
     * @param string|null $filename Файл водного знака (абсолютное расположение).
     * @param string $mode Режим создания водного знака.
     * @return array [Файл, Ссылка на обработанный файл]
     *
     * @throws ImagingException
     */
    public static function resizeAndWatermark(
        string $filepath,
        ?string $disk,
        int $width,
        int $height,
        ?string $filename,
        string $mode = self::WATERMARK_MODE_SINGLE,
    ): array
    {
        throw new \RuntimeException("Метод пока не реализован");
    }



    /**
     * Поворачивает изображение.
     *
     * @param int $degrees Угол поворота по часовой стрелке (положительное число) и против часовой стрелки (отрицательное число).
     * @return array [Файл, Ссылка на обработанный файл]
     *
     * @throws ImagingException
     */
    public static function rotate(int $degrees): array
    {
        throw new \RuntimeException("Метод пока не реализован");
    }

    /**
     * Создает имя файла для обработанного изображения.
     *
     * @param string $disk
     * @param string $filename
     * @param string $operation_suffix
     * @return string
     */
    private static function getFilenameForCache(string $disk, string $filename, string $operation_suffix): string
    {
        return $disk . '/' . mb_substr($filename, 0, mb_strrpos($filename, '.')) . $operation_suffix;
    }
}
