<?php

return [
    'not_defined_origin_disk' => 'Source disk for images is not defined',
    'not_defined_target_disk' => 'Target disk for processed images is not defined',

    'creating_handler_failed' => "Unable to create the driver instance ':handler'",

    'creating_folder_failed' => "Unable to create directory ':folder'",
    'folder_access_denied' => "No write permission for directory ':folder'",

    'creating_file_failed' => "Unable to create file ':file'",
    'file_missing_on_disk' => "File ':file' does not exist on disk ':disk'",
    'file_not_image' => "File ':file' is not an image or is unavailable",
    'file_is_unreadable' => "File ':file' is not readable",
    'set_permission_failed' => "Unable to set permissions ':permissions' on file ':file'",

    'unknown_imagetype' => "Unsupported image type ':type'",

    'wrong_hex' => "Color ':color' is not a valid hex value",
    'wrong_dimensions' => "Dimensions are invalid; received width ':width', height ':height'",
    'wrong_x_position' => "Position ':position' is invalid. Expected XPositionType::LEFT, XPositionType::MIDDLE, XPositionType::RIGHT, or an integer",
    'wrong_y_position' => "Position ':position' is invalid. Expected YPositionType::BOTTOM, YPositionType::MIDDLE, YPositionType::TOP, or an integer",

    'call_user_func_error' => "Method ':method' failed with error ':error'. Parameters: ':params'",

    'imagick_ext_missing' => "The Imagick extension is not available",
    'imagick_exception' => "The Imagick extension threw an exception: ':except'",

    'unknown_gd_error' => "Unknown error while executing GD function ':func'",
    'gd_ext_missing' => "GD extension ':extension' is not available",
    'gd_webp_animation_error' => 'Animated WEBP images are not supported by the GD library',
];
