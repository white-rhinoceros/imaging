<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Handlers;

use Closure;
use SplFileInfo;
use Whiterhino\Imaging\Exceptions\ImagingException;
use Whiterhino\Imaging\Types\ImageType;
use Whiterhino\Imaging\Types\XPositionType;
use Whiterhino\Imaging\Types\YPositionType;

// Абстрактный базовый класс драйвера обработки изображений.
abstract class AbstractHandler implements HandlerContract
{
    // Типы файлов обрабатываемые данным драйвером.
    protected const ACCEPTED_IMAGETYPES = [];

    protected const NOT_TRANSPIRED_IMAGETYPES = [ImageType::JPEG];

    // region Атрибуты.

	// Информация об оригинальном файле.

    /** @var SplFileInfo Переданный файл изображения. */
    protected SplFileInfo $file;

    /** @var ImageType Тип обрабатываемого изображения */
    protected ImageType $origin_imagetype;

    // Обработка.

    /** @var array Очередь обработки изображения. */
    protected array $queued_actions  = [];

    // Настройки.

    /** @var string|null Цвет фона. */
    protected ?string $bgcolor = null;

    /** @var string Запасной цвет фона (см. описание метода self::SetSecondBgcolor). */
    protected string $second_bgcolor = '#FFF';

    /** @var int Качество сохраняемого изображения, для поддерживаемых форматов. */
    protected int $quality = 70;

    /** @var int Прозрачность водного знака. */
    protected int $watermark_alpha = 75;

	// endregion

    // region Реализация контракта HandlerContract

    /**
     * @inheritDoc
     */
    public function __construct(
        SplFileInfo $file,
        ?ImageType $force_imagetype = null,
        array $config = []
    )
    {
        $this->file = $file;

        if ($force_imagetype !== null) {
            $this->origin_imagetype = $force_imagetype;
        } else {
            $this->origin_imagetype = ImageType::getType($file->getExtension());
        }

        if (array_key_exists('bgcolor', $config)) {
            if ($config['bgcolor'] === null) {
                $this->bgcolor = null;
            } else {
                $this->bgcolor = (string)$config['bgcolor'];
            }
        }

        // Если задан - то только строка.
        if (array_key_exists('second_bgcolor', $config)) {
            $this->second_bgcolor = (string)$config['second_bgcolor'];
        }

        if (array_key_exists('quality', $config)) {
            $this->quality = (int)$config['quality'];
        }

        if (array_key_exists('watermark_alpha', $config)) {
            $this->watermark_alpha = (int)$config['watermark_alpha'];
        }

        // Метод реализуется в каждом конкретном драйвере.
        // Как именно загружается изображение нас здесь не интересует.
        $this->loadFromFile();
    }

    /**
     * @inheritDoc
     */
    public function reload(): static
    {
        $this->loadFromFile();

        return $this;
    }

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    public function save(SplFileInfo $file, ?int $permissions = null, ?ImageType $force_imagetype = null): static
    {
        // Определимся в каком формате будем сохранять изображение. Формат выберем по расширению файла,
        // а если задан $force_imagetype то на основе этого параметра.
        if ($force_imagetype !== null) {
            $save_imagetype = $force_imagetype;
            $new_extension = $force_imagetype->ext();
        } else {
            $new_extension = $file->getExtension();
            $save_imagetype = ImageType::getType($new_extension);

            if ($save_imagetype === null) {
                throw new ImagingException(__(
                    'imaging.unknown_imagetype',
                    ['type' => $new_extension]
                ));
            }
        }

        $pathname = $file->getPath() . $file->getBasename('.' . $file->getExtension()) . '.' . $new_extension;

        // Пробуем создать файл.
        if (!touch($pathname)) {
            throw new ImagingException(__(
                'imaging.creating_file_failed',
                ['file' => $pathname]
            ));
        }

        // Установить разрешения.
        if ($permissions !== null && !chmod($pathname, $permissions)) {
            throw new ImagingException(__(
                'imaging.set_permission_failed',
                ['permissions' => $permissions, 'file' => $pathname]
            ));
        }

        $this->saveToFile($pathname, $save_imagetype);

        return $this;
    }

    /**
     * @inheritDoc
     *
     * @throws ImagingException
     */
    public function saveInOriginalFolder(
        string $append,
        ?string $prepend = null,
        ?int $permissions = null,
        ?ImageType $force_imagetype = null
    ): static
    {
        $new_filename = $append . $this->file->getBasename() . $prepend;

        return $this->save(
            new SplFileInfo($this->file->getPath() . $new_filename),
            $permissions,
            $force_imagetype
        );
    }

    /**
     * @inheritDoc
     */
    public function output(?ImageType $imagetype = null): static
    {
        if ($imagetype === null) {
            $imagetype = $this->origin_imagetype;
        }

        $blob =  $this->getBlob($imagetype);

        header('Content-Type: ' . $imagetype->mimetype());
        echo $blob;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function blob(?ImageType $imagetype = null): string
    {
        if ($imagetype === null) {
            $imagetype = $this->origin_imagetype;
        }

		return $this->getBlob($imagetype);
    }

    /**
     * @inheritDoc
     */
    public function runQueue(): static
    {
        foreach ($this->queued_actions as $action => $args) {
            $exec = $this->callUserFuncArray([& $this, '_' . $action], $args);

            if ($exec !== true) {
                $arg_str = [];

                for ($i = 0, $max = count($args); $i < $max; $i++) {
                    $arg_str[$i] = var_export($args[$i], true);
                }

                throw new ImagingException(__(
                    'imaging.call_user_func_error',
                    [
                        'method' => static::class . '::_' . $action,
                        'error' => $exec,
                        'params' => $arg_str,
                    ]
                ));
            }
        }

        // Очистим очередь обработки.
        $this->queued_actions = [];
        
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function sizes(): array
    {
        return $this->sizesOfImageFile(null);
    }

    /**
     * @inheritDoc
     */
    public function crop(
        int|string $x1,
        int|string|null $y1,
        int|string|null $x2,
        int|string|null $y2,
        bool $add_padding = false
    ): static
    {
        $this->queue('crop', $x1, $y1, $x2, $y2, $add_padding);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function resize(
        int|string|null $width,
        int|string|null $height = null,
        bool $keep_ratio = true,
        bool $pad = false
    ): static
    {
        $this->queue('resize', $width, $height, $keep_ratio, $pad);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function watermark(
        SplFileInfo $filename,
        XPositionType|int $x_pos,
        YPositionType|int $y_pos,
        int $x_pad,
        ?int$y_pad
    ): static
    {
        $this->queue('watermark', $filename, $x_pos);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function rotate(int $degrees): static
    {
        $this->queue('rotate', $degrees);

        return $this;
    }

    // endregion

    // region Служебные методы

    /**
     * Добавляет в очередь имя служебного метода что-бы выполнить в дальнейшем.
     *
     * @param string $function Имя метода без ведущего _.
     * @param array $args Аргументы метода.
     * @return void
     */
    protected function queue(string $function, ...$args): void
    {
        $this->queued_actions[$function] = $args;
    }

    /**
     * Быстрый эквивалент call_user_func_array.
     *
     * @param callable $callback
     * @param array $args
     * @return mixed
     */
    protected function callUserFuncArray(callable $callback, array $args): mixed
    {
        //return call_user_func_array($callback, $args);

        // Имеем дело с "class::method" синтаксисом.
        if (is_string($callback) && str_contains($callback, '::')) {
            $callback = explode('::', $callback);
        }

        // Динамический вызов у объекта?
        if (is_array($callback) && isset($callback[1]) && is_object($callback[0])) {
            // Убедимся, что аргументы проиндексированы.
            if (count($args)) {
                $args = array_values($args);
            }

            [$instance, $method] = $callback;

            return $instance->{$method}(...$args);
        }

        // Статический вызов?
        if (is_array($callback) && isset($callback[1]) && is_string($callback[0])) {
            [$class, $method] = $callback;
            $class = '\\'.ltrim($class, '\\');

            return $class::{$method}(...$args);
        }

        // Если это строка, это обычная функция.
        if (is_string($callback) || $callback instanceOf Closure) {
            is_string($callback) && $callback = ltrim($callback, '\\');
        }

        return $callback(...$args);
    }

    /**
     * Конвертирует проценты и отрицательные числа в абсолютные целые.
     *
     * @param string|int $input Входное значение.
     * @param bool $xAxis Определяет с какой осью связано это значение X или Y.
     * @return int
     */
    protected function convertNumber(string|int $input, bool $xAxis): int
    {
        // Получим размеры обрабатываемого изображения.
        [$width, $height] = $this->sizes();
        $size = $xAxis ? $width : $height;

        if (is_string($input)) {
            // Удалим двойные минусы, запятую конвертируем в точку, что бы устранить неоднозначность.
            $input = str_replace(array('--', ','), array('', '.'), $input);

            // Если заданы проценты - с конвертируем в абсолютные значения.
            if (str_ends_with($input, '%')) {
                $ans = (int)floor((substr($input, 0, -1) / 100) * $size);
            } else {
                $ans = (int)$input;
            }
        } else {
            $ans = $input;
        }

        // Отрицательные числа соответствуют отсчету от нижнего правого угла.
        if ($input < 0) {
            $ans = $size + $input;
        }

        return $ans;
    }

    /**
     * Создает новый цвет используемый всеми драйверами на основе переданного hex цвета.
	 * Если прозрачность не передана - то возвращается прозрачность равная 100 (не прозрачное изображение).
     *
     * @param string|null $hex hex код цвета.
     * @return array rgba представление hex цвета и alpha значения.
     *
     * @throws ImagingException
     */
    protected function createDecColorFromHex(?string $hex): array
    {
        if ($hex === null) {
            $red = 0;
            $green = 0;
            $blue = 0;
            $alpha = 0;
        } else {
            // Если строка начинается с символа # - исключим его.
            if ($hex[0] === '#') {
                $hex = substr($hex, 1);
            }

            $len = strlen($hex);

            // Цвет закодирован в виде #FFFFFF или #FFFFFFF.
            if ($len === 6 || $len === 8) {
                $red   = hexdec(substr($hex, 0, 2));
                $green = hexdec(substr($hex, 2, 2));
                $blue  = hexdec(substr($hex, 4, 2));
                $alpha = (strlen($hex) === 8) ? hexdec(substr($hex, 6, 2)) : 255;
            } elseif ($len === 3 || $len === 4) {
                $red   = hexdec($hex[0] . $hex[0]);
                $green = hexdec($hex[1] . $hex[1]);
                $blue  = hexdec($hex[2] . $hex[2]);
                $alpha = (strlen($hex) === 4) ? hexdec($hex[3] . $hex[3]) : 255;
            } else {
                throw new ImagingException(__(
                    'imaging.wrong_hex',
                    ['color' => $hex]
                ));
            }
        }

        // Alpha не может быть больше 100 (приходит число от 0 до 255).
        $alpha = floor($alpha / 2.55);

        return [
            (int)$red,
            (int)$green,
            (int)$blue,
            (int)$alpha,
        ];
    }

    /**
     * Загружает изображение из файла.
     *
     * @return void
     */
    abstract protected function loadFromFile(): void;

    /**
     * Сохранение изображения в подготовленный файл.
     *
     * @param string $pathname Полный путь к файлу включая имя файла и его расширение.
     * @param ImageType $imagetype Тип изображения.
     * @return void
     */
    abstract protected function saveToFile(string $pathname, ImageType $imagetype): void;

    /**
     * Отдает изображение пользователю в виде бинарной строки.
     *
     * @param ImageType $imagetype Тип изображения.
     * @return string
     */
    abstract protected function getBlob(ImageType $imagetype): string;


    /**
     * Возвращает размеры переданного файла с изображением или текущего обрабатываемого файла.
     *
     * @param string|null $pathname Полный путь к файлу.
     * @return array<int, int> Массив содержащий длину и ширину.
     */
    abstract protected function sizesOfImageFile(?string $pathname): array;

    // endregion

    // region Методы обработки изображения

    /**
     * Обрезать изображение. Метод для постановки в очередь.
     *
     * Положительные целые числа или проценты суть координаты отсчитываемые от верхнего, левого угла.
     * Отрицательные целые числа или проценты суть координаты отсчитываемые от нижнего, правого угла.
     *
     * Пример вызова:
     *     crop(10, '10.5%') - обрезать изображение на 10 пикселей с лева и справа и на 10% сверху и снизу;
     *     crop(20) - обрезать по кругу на 20 пикселей;
     *     crop(10, 30, '30%') - с верху 10 пикселей, с лева 30 пикселей, снизу и справа 30%.
     *
     * @param int|string $x1 X - координата первого набора.
     * @param int|string|null $y1 Y - координата первого набора.
     * @param int|string|null $x2 X - координата второго набора.
     * @param int|string|null $y2 Y - координата второго набора.
     * @param bool $add_padding Следует ли добавить поля, если изображение увеличивается (в противном случае
     *                          увеличение холста изображения не будет).
     * @return bool|string True в случае успех, строка с описанием ошибки в противном случае.
     */
    abstract protected function _crop(
        int|string $x1,
        int|string|null $y1,
        int|string|null $x2,
        int|string|null $y2,
        bool $add_padding = false
    ): bool|string;

    /**
     * Приготавливает координаты для обрезки изображения.
     *
     * @param int|string $x1 X - координата первого набора.
     * @param int|string|null $y1 Y - координата первого набора.
     * @param int|string|null $x2 X - координата второго набора.
     * @param int|string|null $y2 Y - координата второго набора.
     * @return array
     */
    protected function prepareCropCoords(
        int|string $x1,
        int|string|null $y1,
        int|string|null $x2,
        int|string|null $y2
    ): array
    {
        $y1 === null and $y1 = $x1;
        $x2 === null and $x2 = - $x1;
        $y2 === null and $y2 = - $y1;

        $x1 = $this->convertNumber($x1, true);
        $y1 = $this->convertNumber($y1, false);
        $x2 = $this->convertNumber($x2, true);
        $y2 = $this->convertNumber($y2, false);

        return [$x1, $y1, $x2, $y2];
    }

    /**
     * Изменить размер изображения. Метод для постановки в очередь.
     *
     * @param int|string|null $width Новая ширина изображения.
     * @param int|string|null $height Новая высота изображения.
     * @param boolean $keep_ratio Значение false разрешает растягивание изображения.
     * @param boolean $pad Добавить отступ (padding) обрабатываемому изображению. Цвет фона задается в
     *                     методами static::setBgcolor и static::setJpegBgcolor.
     * @return bool|string True в случае успех, строка с описанием ошибки в противном случае.
     */
    abstract protected function _resize(
        int|string|null $width,
        int|string|null $height = null,
        bool $keep_ratio = true,
        bool $pad = false
    ): bool|string;

    /**
     * Приготавливает координаты для изменения размера изображения.
     *
     * @param int|string|null $width Новая ширина изображения.
     * @param int|string|null $height Новая высота изображения.
     * @param boolean $keep_ratio Значение false разрешает растягивание изображения.
     * @param boolean $pad Добавить отступ (padding) обрабатываемому изображению. Цвет фона задается в
     *                     методами static::setBgcolor и static::setJpegBgcolor.
     * @return array
     *
     * @throws ImagingException
     */
    protected function prepareResizeCoords(
        int|string|null $width,
        int|string|null $height = null,
        bool $keep_ratio = true,
        bool $pad = false
    ): array
    {
        if (empty($height) && empty($width)) {
            throw new ImagingException(__(
                'imaging.wrong_dimensions',
                ['width' => $width, 'height' => $height]
            ));
        }

        [$src_width, $src_height] = $this->sizes();

        if (empty($height) && $width) {
            if (str_ends_with($width, '%')) {
                $height = (int)$width;
            } else {
                $height = (int)((int)$width * ($src_height / $src_width));
            }
        }

        if (empty($width) && $height) {
            if (str_ends_with($height, '%')) {
                $width = (int)$height;
            } else {
                $width = (int)((int)$height * ($src_width / $src_height));
            }
        }

        // Ширина и высота нового изображения.
        $new_img_width  = $this->convertNumber($width, true);
        $new_img_height = $this->convertNumber($height, false);

        // Ширина и высота области в новом изображении куда будет скопировано (с растяжением или сжатием)
        // старое изображение. По умолчанию совпадает с размером нового изображения.
        $width  = $new_img_width;
        $height = $new_img_height;

        // Величина смещения от левого верхнего угла нового изображения, если область в новом изображении,
        // куда копируется старое, смещена (запрет на растяжение).
        $dist_x = 0;
        $dist_y = 0;

        if ($keep_ratio) {
            // Найдем наибольшее отношение. Используем расширение ext-bcmath для более точной математики.
            // Числа для него должны быть строками, что бы иметь произвольную точность.
            if (extension_loaded('bcmath')) {
                $width_ratio  = bcdiv((string)$width, (string)$src_width, 10);
                $height_ratio = bcdiv((string)$height, (string)$src_height, 10);
                $compare = bccomp($width_ratio, $height_ratio, 10);

                if ($compare > -1) {
                    $height = (int)ceil((float) bcmul((string)$src_height, $height_ratio, 10));
                    $width = (int)ceil((float) bcmul((string)$src_width, $height_ratio, 10));
                } else {
                    $height = (int)ceil((float) bcmul((string)$src_height, $width_ratio, 10));
                    $width = (int)ceil((float) bcmul((string)$src_width, $width_ratio, 10));
                }
            } else {
                $width_ratio  = $width / $src_width;
                $height_ratio = $height / $src_height;
                if ($width_ratio >= $height_ratio) {
                    $height = (int)ceil($src_height * $height_ratio);
                    $width = (int)ceil($src_width * $height_ratio);
                } else {
                    $height = (int)ceil($src_height * $width_ratio);
                    $width = (int)ceil($src_width * $width_ratio);
                }
            }
        }

        if ($pad) {
            $dist_x = (int)floor(($new_img_width - $width) / 2);
            $dist_y = (int)floor(($new_img_height - $height) / 2);
        } else {
            $new_img_width  = $width;
            $new_img_height = $height;
        }

        return [
            // Ширина и высота "окна" в новом изображении в которое будет
            // скопировано (с растяжением / сжатием) старое изображение.
            $width,
            $height,

            // Ширина и высота нового изображения.
            $new_img_width,
            $new_img_height,

            // Смещение "окна" в новом изображении.
            // Должно выполнятся условие:
            // (width + 2*dist_x = new_img_width) и (height + 2*dist_y = new_img_height).
            $dist_x,
            $dist_y,

            // Ширина и высота исходного изображения.
            $src_width,
            $src_height,
        ];
    }

    /**
     * Накладывает водный знак на изображения. Метод для постановки в очередь.
     *
     * @param SplFileInfo $filename Файл водного знака.
     * @param XPositionType|int $x_pos Положение водного знака по оси x.
     * @param YPositionType|int $y_pos Положение водного знака по оси y.
     * @param int $x_pad Отступ по оси x.
     * @param int|null $y_pad Отступ по оси y.
     * @return bool|string True в случае успех, строка с описанием ошибки в противном случае.
     */
    abstract protected function _watermark(
        SplFileInfo $filename,
        XPositionType|int $x_pos,
        YPositionType|int $y_pos,
        int $x_pad,
        ?int $y_pad
    ): bool|string;

    /**
     * Приготавливает параметры для наложения водного знака.
     *
     * @param SplFileInfo $filename Файл водного знака.
     * @param XPositionType|int $x_pos Положение водного знака по оси x.
     * @param YPositionType|int $y_pos Положение водного знака по оси y.
     * @param int $x_pad Отступ по оси x.
     * @param int|null $y_pad Отступ по оси y.
     * @return array
     *
     * @throws ImagingException
     */
    protected function prepareWatermarkParams(
        SplFileInfo $filename,
        XPositionType|int $x_pos,
        YPositionType|int $y_pos,
        int $x_pad,
        ?int $y_pad
    ): array
    {
        if (!$filename->isReadable()) {
            throw new ImagingException(__(
                'imaging.file_is_unreadable',
                ['file' => $filename->getPathname()]
            ));
        }

        $wm_imagetype = ImageType::getType($filename->getExtension());

        if ($wm_imagetype === null) {
            throw new ImagingException(__(
                'imaging.unknown_imagetype',
                ['type' => $filename->getExtension()]
            ));
        }

        [$wm_width, $wm_height] = $this->sizesOfImageFile($filename->getPathname());
        [$src_width, $src_height]  = $this->sizes();

        // Получим отступы по x и y.
        if ($y_pad === null) {
            $y_pad = $x_pad;
        }

        switch ($x_pos) {
            case XPositionType::LEFT:
                $x = $x_pad;
                break;
            case XPositionType::MIDDLE:
            case XPositionType::CENTER:
                $x = ($src_width / 2) - ($wm_width / 2);
                break;
            case XPositionType::RIGHT:
                $x = $src_width - $wm_width - $x_pad;
                break;
            default:
                if (is_numeric($x_pos)) {
                    $x = $x_pos;
                } else {
                    throw new ImagingException(__(
                        'imaging.wrong_x_position',
                        ['position' => $x_pos->name]
                    ));
                }
        }

        switch ($y_pos) {
            case YPositionType::TOP:
                $y = $y_pad;
                break;
            case YPositionType::MIDDLE:
            case YPositionType::CENTER:
                $y = ($src_height / 2) - ($wm_height / 2);
                break;
            case YPositionType::BOTTOM:
                $y = $src_height - $wm_height - $y_pad;
                break;
            default:
                if (is_numeric($y_pos)) {
                    $y = $y_pos;
                } else {
                    throw new ImagingException(__(
                        'imaging.wrong_y_position',
                        ['position' => $x_pos->name]
                    ));
                }
        }

        return [
            // В какое место положит водный знак на изображение.
            $x,
            $y,

            // Ширина и высота водного знака.
            $wm_width,
            $wm_height,
        ];
    }

    /**
     * Повернуть изображение.
     *
     * @param int $degrees Угол поворота по часовой стрелке (положительное число) и против часовой
     *                     стрелки (отрицательное число).
     * @return bool|string True в случае успех, строка с описанием ошибки в противном случае.
     */
    abstract protected function _rotate(int $degrees): bool|string;

    /**
     * Получить угол поворота.
     *
     * @param int $degrees Угол поворота по часовой стрелке (положительное число) и против часовой
     *                     стрелки (отрицательное число).
     * @return int Итоговый угол поворота.
     */
    protected function prepareRotateDegrees(int $degrees): int
    {
        // Остаток от деления на 360 градусов.
        $degrees %= 360;

        if ($degrees < 0) {
            // Отнимая — крутим в левую сторону.
            $degrees = 360 + $degrees;
        }

        return $degrees;
    }

    // endregion
}
