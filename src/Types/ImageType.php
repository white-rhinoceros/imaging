<?php

namespace Whiterhino\Imaging\Types;

// Тип определяющий типы изображений и расширения соответствующих файлов.
enum ImageType: int
{
    case GIF = 1; // IMAGETYPE_GIF

    case JPEG = 2; // IMAGETYPE_JPEG

    case PNG = 3; // IMAGETYPE_PNG

    case WEBP = 18; // IMAGETYPE_WEBP

    private const IMAGETYPE_TO_POSSIBLE_EXTENSION = [
        // PHP 8.2
        self::GIF->value => ['gif'],
        self::JPEG->value => ['jpeg', 'jpg', 'jpe'],
        self::PNG->value => ['png'],
        self::WEBP->value => ['webp'],
    ];

    /**
     * Получить тип изображения по расширению.
     *
     * @param string $ext
     * @return ImageType|null
     */
    public static function getType(string $ext): ?ImageType
    {
        foreach (self::IMAGETYPE_TO_POSSIBLE_EXTENSION as $key => $extensions) {
            if (in_array($ext, self::IMAGETYPE_TO_POSSIBLE_EXTENSION[$key])) {
                return self::from($key);
            }
        }

        return null;
    }

    /**
     * Получение расширения для типа изображения.
     *
     * @param bool $include_dot
     * @return string
     */
    public function ext(bool $include_dot = false): string
    {
        return image_type_to_extension($this->value, $include_dot);
    }

    /**
     * Получение Mime-типа для типа изображения.
     *
     * @return string
     */
    public function mimeType(): string
    {
        return image_type_to_mime_type($this->value);
    }
}