<?php

namespace Whiterhino\Imaging\Handlers;

use SplFileInfo;
use Whiterhino\Imaging\Exceptions\ImagingException;
use Whiterhino\Imaging\Types\ImageType;
use Whiterhino\Imaging\Types\XPositionType;
use Whiterhino\Imaging\Types\YPositionType;

// Контракт драйвера обработчика изображения.
interface HandlerContract
{
	/**
	 * @param SplFileInfo $file Обрабатываемый файл.
	 * @param ImageType|null $force_imagetype Позволяет принудительно рассматривать файл, как изображение данного типа.
	 * @param array $config Дополнительные настройки.
	 */
    public function __construct(
        SplFileInfo $file,
        ?ImageType $force_imagetype = null,
        array $config = []
    );

    /**
     * Загружает изображение повторно.
     *
     * @return HandlerContract
     */
    public function reload(): static;

    /**
     * Сохраняет изображение (создает файл изображения и опционально пробует установить разрешения на файл).
     *
     * @param SplFileInfo $file Экземпляр SplFileInfo класса описывающего сохраняемый файл.
     * @param int|null $permissions Набор разрешений в Unix стиле.
     * @param ImageType|null $force_imagetype Принудительно сохранить как изображение данного типа.
     * @return HandlerContract
     */
    public function save(SplFileInfo $file, ?int $permissions = null, ?ImageType $force_imagetype = null): static;

    /**
     * Сохраняет изображение в исходную директорию добавляя префикс и/или суффикс.
     *
     * @param string $append Строка добавляемая к имени файла с лева.
     * @param string|null $prepend Строка добавляемая к имени файла с права.
     * @param ImageType|null $force_imagetype Принудительно сохранить как изображение данного типа.
     * @param int|null $permissions Набор разрешений в Unix стиле.
     * @return HandlerContract
     */
    public function saveInOriginalFolder(
        string $append,
        ?string $prepend = null,
        ?int $permissions = null,
        ?ImageType $force_imagetype = null
    ): static;

    /**
     * Выводит изображение в браузер.
     *
     * @param ImageType|null $imagetype Определяет тип выводимого изображения (null - тип исходного изображения).
     * @return HandlerContract
     */
    public function output(?ImageType $imagetype = null): static;

    /**
     * Отдает изображение в виде бинарной строки.
     *
     * @param ImageType|null $imagetype Определяет тип изображения (null - тип исходного изображения).
     * @return string
     */
    public function blob(?ImageType $imagetype = null): string;

    /**
     * Выполняет очередь обработки.
     *
     * @return HandlerContract
     *
     * @throws ImagingException
     */
    public function runQueue(): static;

    /**
     * Возвращает размеры обрабатываемого файла.
     *
     * @return array<int, int> Массив содержащий длину и ширину.
     */
    public function sizes(): array;

    /**
     * Обрезает изображение используя координаты или проценты.
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
     * @return  HandlerContract
     */
    public function crop(
        int|string $x1,
        int|string|null $y1,
        int|string|null $x2,
        int|string|null $y2,
        bool $add_padding = false
    ): static;

    /**
     * Изменяет размер изображения. Если $width или $height имеет значение null в этом случае отношение
     * сторон будет сохранено.
     *
     * @param int|string|null $width Новая ширина изображения.
     * @param int|string|null $height Новая высота изображения.
     * @param boolean $keep_ratio Значение false разрешает растягивание изображения.
     * @param boolean $pad Добавить отступ (padding) обрабатываемому изображению. Цвет фона задается в
     *                     методами static::setBgcolor и static::setJpegBgcolor.
     * @return HandlerContract
     */
    public function resize(
        int|string|null $width,
        int|string|null $height = null,
        bool $keep_ratio = true,
        bool $pad = false
    ): static;

    /**
     * Добавляет водный знак на изображение.
     *
     * @param SplFileInfo $filename Файл водного знака.
     * @param XPositionType|int $x_pos Положение водного знака по оси x.
     * @param YPositionType|int $y_pos Положение водного знака по оси y.
     * @param int $x_pad Отступ по оси x.
     * @param int|null $y_pad Отступ по оси y.
     * @return HandlerContract
     */
    public function watermark(
        SplFileInfo $filename,
        XPositionType|int $x_pos,
        YPositionType|int $y_pos,
        int $x_pad,
        ?int $y_pad
    ): static;

    /**
     * Поворачивает изображение.
     *
     * @param int $degrees Угол поворота по часовой стрелке (положительное число) и против часовой
     *                     стрелки (отрицательное число).
     * @return HandlerContract
     */
    public function rotate(int $degrees): static;
}
