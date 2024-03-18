<?php

namespace Whiterhino\Imaging;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
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

    /**
     * Обрезать изображение используя координаты или проценты.
     * Положительные целые числа или проценты суть координаты отсчитываемые от верхнего, левого угла.
     * Отрицательные целые числа или проценты суть координаты отсчитываемые от нижнего, правого угла.
     *
     * Пример вызова:
     *     crop('public:image.jpg', 10, '10%') - обрезать изображение на 10 пикселей с лева и справа
     *     и на 10% сверху и снизу;
     *     crop('public:image.jpg', 20) - обрезать по кругу на 20 пикселей;
     *     crop('public:image.jpg', 10, 30, '30%') - сверху 10 пикселей, с лева 30 пикселей, снизу и
     *     справа 30%.
     *
     * @param string $filepath
     * @param string|null $disk
     * @param int|string $x1 X - координата первого набора.
     * @param int|string|null $y1 Y - координата первого набора.
     * @param int|string|null $x2 X - координата второго набора.
     * @param int|string|null $y2 Y - координата второго набора.
     * @param string $mode
     * @return string Ссылка на обработанный файл.
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
    ): string
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

        return $manager->generateUrl($processed_file);
    }

    /**
     * Изменяет размер изображения.
     *
     * @param string $filepath
     * @param string|null $disk
     * @param int $width Новая ширина.
     * @param int $height Новая длина.
     * @param string $mode Константа метода изменения изображения.
     * @return string Ссылка на обработанный файл.
     *
     * @throws ImagingException
     */
    public function resize(
        string $filepath,
        ?string $disk,
        int $width,
        int $height,
        string $mode = self::RESIZE_MODE_PAD
    ): string
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
                // Изменение размера.
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

        return $manager->generateUrl($processed_file);
    }

//    /**
//     * Добавляет водный знак на изображение.
//     *
//     * @param string|null $filename Файл водного знака.
//     * @return string Ссылка на обработанный файл.
//     *
//     * @throws ImagingException
//     */
//    public function watermark(?string $filename): string
//    {
//        $filename = $filename ?? Config::get('imaging.watermark_filename');
//
//        $cached_img_filename = $this->getFilenameForCache(
//            $filename,
//            '-watermark-' . md5($filename)
//        );
//
//        return $this->make(
//            $filename,
//            $cached_img_filename,
//            function(HandlerContract $image) use ($filename) {
//                $image->watermark(
//                    new SplFileInfo($filename),
//                    Config::get('imaging.watermark_x_position'),
//                    Config::get('imaging.watermark_y_position'),
//                    Config::get('imaging.watermark_x_pad'),
//                    Config::get('imaging.watermark_y_pad')
//                );
//            }
//        );
//    }

//    /**
//     * Заполняет изображение водными знаками.
//     *
//     * @param string|null $filename Файл водного знака.
//     * @return string Ссылка на обработанный файл.
//     *
//     * @throws ImagingException
//     */
//    public function fillWatermark(?string $filename): string
//    {
//        Сложный make для водного знака.
//    }

//    /**
//     * Поворачивает изображение.
//     *
//     * @param int $degrees Угол поворота по часовой стрелке (положительное число) и против часовой
//     *                     стрелки (отрицательное число).
//     * @return string Ссылка на обработанный файл.
//     *
//     * @throws ImagingException
//     */
//    public function rotate(int $degrees): string
//    {
//          Использовать rotate()
//    }

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
