<?php

return [
    'not_defined_origin_disk' => 'Не задан диск с изображениями для обработки',
    'not_defined_target_disk' =>  'Не задан диск для сохранения обработанных изображений',

    'creating_handler_failed' => "Невозможно создать экземпляр драйвера ':handler'",

    'creating_folder_failed' => "Невозможно создать директорию ':folder'",
    'folder_access_denied' => "Нет разрешения на запись для директории ':folder'",

    'creating_file_failed' => "Невозможно создать файл ':file'",
    'file_missing_on_disk' => "На диске ':disk' отсутствует файл ':file'",
    'file_not_image' => "Файл ':file' не является изображением или недоступен",
    'file_is_unreadable' => "Файл ':file' недоступен для чтения",
    'set_permission_failed' => "Ошибка установки разрешений ':permissions' на файл ':file'",

    'unknown_imagetype' => "Не поддерживаемый тип ':type' файла изображения",

    'wrong_hex' => "Цвет ':color' в hex формате задан не верно",
    'wrong_dimensions' => "Размеры заданы не корректно, переданные размеры: ширина ':width', высота ':height'",
    'wrong_x_position' => "Переданная позиция ':position' неверна. Позиция должна быть XPositionType::LEFT, XPositionType::MIDDLE, XPositionType::RIGHT или числом типа int",
    'wrong_y_position' => "Переданная позиция ':position' неверна. Позиция должна быть YPositionType::BOTTOM, YPositionType::MIDDLE, MIDDLE::TOP или числом типа int",

    'call_user_func_error' => "Вызов метода ':method' завершился ошибкой ':error'. Параметры вызова ':params'",

    'imagick_ext_missing' => "Не найдено расширение Imagick",
    'imagick_exception' => "Расширение Imagick выбросило исключение: ':except'",

    'unknown_gd_error' => "Неизвестная ошибка во время выполнения GD функции ':func'",
    'gd_ext_missing' => "Не найдено расширение GD ':extension'",
    'gd_webp_animation_error' => 'Анимированные WEBP изображения не поддерживаются библиотекой GD',
];
