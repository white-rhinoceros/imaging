<?php

return [
    'not_defined_origin_disk' => 'A disk with images for processing is not defined',
    'not_defined_target_disk' =>  'No disk is defined for saving processed images',

    'creating_handler_failed' => "Unable to create the driver instance ':handler'",

    'creating_folder_failed' => "Unable to create the directory ':folder'",
    'folder_access_denied' => "There is no write permission for the directory ':folder'",

    'creating_file_failed' => "Unable to create the file ':file'",
    'file_missing_on_disk' => "There is no file ':file' on disk ':disk'",
    'file_not_image' => "The file ':file' is not an image or is not available",
    'file_is_unreadable' => "The file ':file' is not readable",
    'set_permission_failed' => "Unable to set the permissions ':permissions' on file ':file'",

    'unknown_imagetype' => "Not supported type ':type' of image file",

    'wrong_hex' => "The color ':color' in hex format is not set correctly",
    'wrong_dimensions' => "The dimensions are set incorrectly, the given dimensions are: width ':width', height ':height'",
    'wrong_x_position' => "Given position ':position' is wrong. Position must be XPositionType::LEFT, XPositionType::MIDDLE, XPositionType::RIGHT or integer",
    'wrong_y_position' => "Given position ':position' is wrong. Position must be YPositionType::BOTTOM, YPositionType::MIDDLE, MIDDLE::TOP or integer",

    'call_user_func_error' => "The method call ':method' failed with an error ':error'. Call parameters ':params'",

    'imagick_ext_missing' => "Imagick extension not found",
    'imagick_exception' => "The Imagick extension threw an exception: ':except'",

    'imagemagick_program_not_found' => "Executable Imagemagick program ':program' not found",
    'imagemagick_error_occurred' => "Execution of the Imagemagick command ':command' failed with code ':code'",

    'unknown_gd_error' => "Unknown error while execution of GD function ':func'",
    'gd_ext_missing' => "The GD extension ':extension' not found",
    'gd_webp_animation_error' => 'Animated WEBP images are not supported by the GD library',
];