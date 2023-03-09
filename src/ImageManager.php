<?php

declare(strict_types=1);

namespace Whiterhino\Imaging;

use Whiterhino\Imaging\Exceptions\ImagingException;
use Whiterhino\Imaging\Handlers\HandlerContract;
use Whiterhino\Imaging\Types\ImageType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SplFileInfo;

/**
 * Менеджер обработки изображений.
 */
final class ImageManager
{
    // Субдиректория внутри временной директории.
    private const TMP_SUBDIR = 'imaging';

    /** @var Filesystem Экземпляр диска, на котором хранятся исходные изображения. */
    private Filesystem $origin_disk;

    /** @var Filesystem Экземпляр диска, для хранения обработанные изображения. */
    private Filesystem $target_disk;

    /** @var string Драйвер. */
    private string $handler_name;

    /** @var string Директория для хранения временных файлов. */
    private string $tmp_dir;

    /** @var ImageType|null Определяет тип для сохранения обработанного изображения. Null - тип оригинального файла. */
    private ?ImageType $imagetype;

    /**
     * @param string $origin_disk_name Диск с изображениями.
     * @param string $handler_name Имя обработчика (драйвера).
     * @param ImageType|null $imagetype Тип изображения, в котором будут сохраняться обработанные файлы.
     *                                  Null - использовать оригинальный формат.
     * @throws ImagingException
     */
    public function __construct(
        string $origin_disk_name,
        string $target_disk_name,
        string $handler_name,
        ?ImageType $imagetype
    )
    {
        $this->handler_name = $handler_name;
        $this->imagetype = $imagetype;

        if (empty($origin_disk_name)) {
            throw new ImagingException(__('imaging.not_defined_origin_disk'));
        }

        if (empty($target_disk_name)) {
            throw new ImagingException(__('imaging.not_defined_target_disk'));
        }

        $this->origin_disk = Storage::disk($origin_disk_name);
        $this->target_disk = Storage::disk($target_disk_name);
        $this->tmp_dir = rtrim(Config::get('imaging.temp_dir'), '\\/') . '/' . self::TMP_SUBDIR . '/';

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
     * Выполнить последовательность преобразований над изображением.
     *
     * @param string $filename Имя исходного файла.
     * @param string $cached Имя обработанного изображения (может включать директории, без расширения).
     * @param callable $callback Функция обратного вызова обработки изображения.
     * @return string|null Имя файла с обработанным изображением.
     *
     * @throws ImagingException
     */
    public function make(string $filename, string $cached, callable $callback): ?string
    {
        if ($this->origin_disk->missing($filename)) {
            if (Config::get('imaging.debug')) {
                throw new ImagingException(__(
                    'imaging.file_missing_on_disk',
                    ['file' => $this->origin_disk->path($filename), 'disk' => '']
                ));
            }

            Log::channel('imaging')->error(
                __(
                    'imaging.file_missing_on_disk',
                    ['file' => $this->origin_disk->path($filename), 'disk' => '']
                )
            );

            return '';
        }

        if ($this->imagetype !== null) {
            $cached .= $this->imagetype->ext(true); 
        } else {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $cached .= ImageType::getType($extension)->ext(true);
        }
            
        // Если нужное изображение уже существует, то просто отдадим его.
        if ($this->checkCachedImg($filename, $cached) === true) {
            return $cached;
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
        $this->createCachedImage($image->blob($this->imagetype), $cached);

        return $cached;
    }

    /**
     * Возвращаемы ссылку на кешированное изображение.
     *
     * @param string $filename
     * @return string
     */
    public function generateUrl(string $filename): string
    {
        return $this->target_disk->url($filename);
    }

    /**
     * Проверяет существует-ли кешированное изображение и актуально ли оно.
     *
     * @param string $filename Имя исходного изображения.
     * @param string $cached_img_filename Имя кешированного изображения.
     * @return string|bool
     */
    private function checkCachedImg(string $filename, string $cached_img_filename): string|bool
    {
        $source_time = $this->origin_disk->lastModified($filename);

        if (
            $this->target_disk->exists($cached_img_filename)
            && $this->target_disk->lastModified($cached_img_filename) > $source_time
        ) {
            return true;
        }

        return false;
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
        $this->target_disk->put($cached_img_filename, $blob);
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
            $this->origin_disk->get($filename)
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
            [
                'imagemagick_dir' => Config::get('imaging.imagemagick_dir'),
                'temp_dir' => Config::get('imaging.temp_dir'),
            ]
        );

        $handler->setBgcolor(Config::get('imaging.bgcolor'));
        $handler->setSecondBgcolor(Config::get('imaging.second_color'));
        $handler->setQuality(Config::get('imaging.quality'));
        $handler->setWatermarkAlpha(Config::get('imaging.watermark_alpha'));

        return $handler;
    }
}
