<?php

declare(strict_types=1);

namespace Whiterhino\Imaging;

use App\Types\ImageType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SplFileInfo;

/**
 * Обработка изображений "на лету" с кешированием.
 */
final class ImageManager
{
    // Константы определяющие способы изменения размера изображений.
    public const RESIZE_MODE_PAD = 'pad'; // Добавить поля цветом фона.
    public const RESIZE_MODE_STRETCH = 'stretch'; // Растянуть.
    public const RESIZE_MODE_KEEPRATIO = 'keepratio'; // Сохранить пропорции, уменьшив по меньшей пропорции.

    // Поддиректория во временной директории для временных изображений.
    private const TMP_SUBDIR = 'imaging';

    /** @var array */
    private static array $instances = [];

    /**
     * Фабричная функция создающая менеджер обработки изображений.
     *
     * @param string $disk
     * @param string|null $handler_name
     * @param ImageType|null $imagetype
     * @return ImageManager
     *
     * @throws ImagingException
     */
    public static function create(string $disk, string $handler_name = null, ImageType $imagetype = null): ImageManager
    {
        if (empty($disk)) {
            throw new ImagingException("Не передан диск для поиска изображений");
        }

        $handler = $handler_name ?? Config::get('store.imaging.default_handler');

        return self::$instances[$disk] ?? (self::$instances[$disk] = new self($disk, $handler, $imagetype));
    }

    /** @var string Имя диска, на котором хранятся исходные изображения. */
    private string $disk_name;    

    /** @var Filesystem Диск, на котором хранятся исходные изображения. */
    private Filesystem $disk;

    /** @var Filesystem Диск, на котором хранятся обработанные изображения. */
    private Filesystem $public_cache_disk;

    /** @var string Имя класса обработчика. */
    private string $handler_name;

    /** @var string Директория для хранения временных файлов. */
    private string $tmp_dir;


    /** @var ImageType|null Определяет тип кешируемого изображения. Null - тип оригинального файла. */
    private ?ImageType $imagetype;

    /**
     * @param string $disk_name Диск с изображениями.
     * @param string $handler_name Имя обработчика.
     *
     * @throws ImagingException
     */
    private function __construct(string $disk_name, string $handler_name, ?ImageType $imagetype)
    {
        $this->disk_name = $disk_name;
        $this->handler_name = $handler_name;
        $this->imagetype = $imagetype;

        $this->disk = Storage::disk($disk_name);
        $this->public_cache_disk = Storage::disk(Config::get('store.imaging.public_cache_disk'));
        $this->tmp_dir = rtrim(Config::get('store.imaging.temp_dir'), '\\/') . '/' . self::TMP_SUBDIR . '/';

        if (
            ! is_dir($this->tmp_dir)
            && !mkdir($this->tmp_dir)
            && !is_dir($this->tmp_dir)
        ) {
            throw new ImagingException(__(
                'imaging.creating_folder_failed',
                ['folder' => $this->tmp_dir]
            ));
        }
    }

    /**
     * Обрезать изображение используя координаты или проценты.
     * Положительные целые числа или проценты суть координаты отсчитываемые от верхнего, левого угла.
     * Отрицательные целые числа или проценты суть координаты отсчитываемые от нижнего, правого угла.
     *
     * Пример вызова:
     *     crop(10, '10%') - обрезать изображение на 10 пикселей с лева и справа и на 10% сверху и снизу;
     *     crop(20) - обрезать по кругу на 20 пикселей;
     *     crop(10, 30, '30%') - с верху 10 пикселей, с лева 30 пикселей, снизу и справа 30%.
     *
     * @param int|string $x1 X - координата первого набора.
     * @param int|string|null $y1 Y - координата первого набора.
     * @param int|string|null $x2 X - координата второго набора.
     * @param int|string|null $y2 Y - координата второго набора.
     * @return string Ссылка на обработанный файл на диске заданный в настройке public_cache_disk.
     *
     * @throws ImagingException
     */
    public function crop(
        string $filename,
        int|string $x1,
        int|string|null $y1,
        int|string|null $x2,
        int|string|null $y2
    ): string
    {
        $cached_img_filename = $this->getImgFilenameForCache(
            $filename,
            '-crop-' . $x1 . 'x' . $y1 . 'x' . $x2 . 'x' . $y2
        );

        return $this->make(
            $filename,
            $cached_img_filename,
            fn (HandlerContract $image) => $image->crop($x1, $y1, $x2, $y2)
        );
    }

    /**
     * Изменяет размер изображения.
     *
     * @param string $filename Имя файла на диске.
     * @param int $width Новая ширина.
     * @param int $height Новая длина.
     * @param string $mode Константа метода изменения изображения.
     * @return string Ссылка на обработанный файл на диске заданный в настройке public_cache_disk.
     *
     * @throws ImagingException
     */
    public function resize(string $filename, int $width, int $height, string $mode = self::RESIZE_MODE_PAD): string
    {
        $cached_img_filename = $this->getImgFilenameForCache(
            $filename,
            '-resize-' . $mode . '-' . $width . 'x' . $height
        );

        return $this->make(
            $filename,
            $cached_img_filename,
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
    }

    /**
     * Добавляет водный знак на изображение.
     *
     * @param string|null $filename Файл водного знака.
     * @return string Ссылка на обработанный файл на диске заданный в настройке public_cache_disk.
     *
     * @throws ImagingException
     */
    public function watermark(?string $filename): string
    {
        $filename = $filename ?? Config::get('store.imaging.watermark_filename');

        $cached_img_filename = $this->getImgFilenameForCache(
            $filename,
            '-watermark-' . md5($filename)
        );

        return $this->make(
            $filename,
            $cached_img_filename,
            function(HandlerContract $image) use ($filename) {
                $image->watermark(
                    new SplFileInfo($filename),
                    Config::get('store.imaging.watermark_x_position'),
                    Config::get('store.imaging.watermark_y_position'),
                    Config::get('store.imaging.watermark_x_pad'),
                    Config::get('store.imaging.watermark_y_pad')
                );
            }
        );
    }

    /**
     * Заполняет изображение водными знаками.
     *
     * @param string|null $filename Файл водного знака.
     * @return string Ссылка на обработанный файл на диске заданный в настройке public_cache_disk.
     *
     * @throws ImagingException
     */
    public function fillWatermark(?string $filename): string
    {
        // TODO!
        // Использовать rotate()
    }

    /**
     * Поворачивает изображение.
     *
     * @param int $degrees Угол поворота по часовой стрелке (положительное число) и против часовой
     *                     стрелки (отрицательное число).
     * @return string Ссылка на обработанный файл на диске заданный в настройке public_cache_disk.
     *
     * @throws ImagingException
     */
    public function rotate(int $degrees): string
    {
        // TODO!
    }

    /**
     * Создает имя файла для обработанного изображения.
     *
     * @param string $filename
     * @param string $operation_suffix
     * @return string
     */
    private function getImgFilenameForCache(string $filename, string $operation_suffix): string
    {
        return $this->disk_name . '/'
            . mb_substr($filename, 0, mb_strrpos($filename, '.')) . $operation_suffix
            . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    }

    /**
     * Выполнить последовательность преобразований над изображением.
     *
     * @param string $filename Имя исходного файла.
     * @param string $cached_img_filename Имя обработанного изображения (может включать директории).
     * @param callable $callback Функция обратного вызова обработки изображения.
     * @return string Ссылка на файл после преобразования на публичном диске.
     *
     * @throws ImagingException
     */
    private function make(string $filename, string $cached_img_filename, callable $callback): string
    {
        // Если нужное изображение уже существует, то просто отдадим его.
        $cachedImage = $this->getCachedImg($filename, $cached_img_filename);
        if ($cachedImage !== false) {
            return $cachedImage;
        }

        if ($this->disk->missing($filename)) {
            if (Config::get('store.imaging.debug')) {
                throw new ImagingException(__(
                    'imaging.file_missing_on_disk',
                    ['file' => $filename, 'disk' => $this->disk_name]
                ));
            }

            Log::channel('imaging')->error(
                __(
                    'imaging.file_missing_on_disk',
                    ['file' => $filename, 'disk' => $this->disk_name]
                )
            );

            return '';
        }

        // Создает временную копию изображения (для манипуляций).
        $tmp_img = $this->createTmpImage($filename);

        // Экземпляр драйвера.
        $image = $this->createHandler($tmp_img);

        // Добавляем в очередь модификации изображения.
        $callback($image);

        // Выполняем обработку.
        $image->runQueue();

        // Создаем кешированное изображение.
        $this->createCachedImage($image->blob($this->imagetype), $cached_img_filename);

        // Возвращаемы ссылку на кешированное изображение.
        return $this->public_cache_disk->url($cached_img_filename);
    }

    /**
     * Получить кешированное (обработанное) изображение или false при его отсутствии.
     *
     * @param string $filename Имя исходного изображения.
     * @param string $cached_img_filename Имя кешированного изображения.
     * @return string|bool
     */
    private function getCachedImg(string $filename, string $cached_img_filename): string|bool
    {
        $source_time = $this->disk->lastModified($filename);

        if (
            $this->public_cache_disk->exists($cached_img_filename)
            && $this->public_cache_disk->lastModified($cached_img_filename) > $source_time
        ) {
            return $this->public_cache_disk->url($cached_img_filename);
        }

        return false;
    }

    /**
     * Создает временную копию изображения.
     *
     * @param string $filename
     * @return string
     *
     * @throws ImagingException
     */
    private function createTmpImage(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        do {
            $imageTemp = $this->tmp_dir . Str::random(32) . '.' . $extension;
        } while (is_file($imageTemp));

        if(!file_put_contents(
            $imageTemp,
            $this->disk->get($filename)
        )) {
            throw new ImagingException(__(
                'imaging.creating_file_failed',
                ['file' => $imageTemp]
            ));
        }

        return $imageTemp;
    }

    /**
     * Создает экземпляр драйвера для переданного изображения.
     *
     * @param string $filename Полный путь к изображению.
     * @return HandlerContract
     * 
     * @throws ImagingException
     */
    private function createHandler(string $filename): HandlerContract
    {
        $file = new SplFileInfo($filename);

        if (empty($this->handler_name) || !class_exists($this->handler_name)) {
            throw new ImagingException(__(
                'imaging.creating_handler_failed',
                ['handler' => $this->handler_name]
            ));
        }

        /** @var HandlerContract $handler */
        $handler = new $this->handler_name(
            $file,
            null,
            Config::get('store.imaging.debug'),
            [
                'imagemagick_dir' => Config::get('store.imaging.imagemagick_dir'),
                'temp_dir' => Config::get('store.imaging.temp_dir'),
            ]
        );

        $handler->setBgcolor(Config::get('store.imaging.bgcolor'));
        $handler->setJpegBgcolor(Config::get('store.imaging.jpeg_bgcolor'));
        $handler->setQuality(Config::get('store.imaging.quality'));
        $handler->setWatermarkAlpha(Config::get('store.imaging.watermark_alpha'));

        return $handler;
    }

    /**
     * Создает кешированное (обработанное изображение).
     *
     * @param string $blob
     * @param string $cached_img_filename
     */
    private function createCachedImage(string $blob, string $cached_img_filename): void
    {
        // Очистка путей.
        $cached_img_filename = str_replace(
            ['\\', '../'], ['/', ''], rtrim($cached_img_filename, '\\/')
        );

        // Сохранить изображение на laravel диск.
        $this->public_cache_disk->put($cached_img_filename, $blob);
    }
}
