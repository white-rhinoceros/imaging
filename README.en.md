## Imaging Library

*Primary documentation is available in Russian: see `README.md`.*

Laravel package for centralized image processing: resizing, cropping, watermarking, and caching derivatives on disk.

### Requirements
- PHP 8.2+
- Laravel 10.x/11.x (the service provider is auto-discovered)
- One of the PHP imaging engines: `ext-gd` or `ext-imagick`
- Configured Laravel filesystem disks for originals (`filesystem.disks.public` by default) and cached files (`filesystem.disks.imagecache`)

### Installation
1. Require the package via Composer:
   ```shell
   composer require white-rhinoceros/imaging
   ```
2. Publish the configuration (and optionally translations):
   ```shell
   php artisan vendor:publish --provider="Whiterhino\\Imaging\\ImagingServiceProvider"
   php artisan vendor:publish --provider="Whiterhino\\Imaging\\ImagingServiceProvider" --tag=imaging-lang
   ```
   The config will appear at `config/imaging.php` and translations under `lang/{en,ru}/imaging.php`.
3. Ensure the disks referenced in the config exist in `config/filesystems.php`. Create a public `imagecache` disk if needed.

### Configuration
Key options in `config/imaging.php`:
- `def_handler` — default handler class (`GdHandler::class` or `ImagickHandler::class`).
- `def_origin_disk` — disk with source images.
- `def_target_disk` — disk where processed files are stored.
- `def_imagetype` — output format (`ImageType::WEBP`, `ImageType::JPEG`, or `null` for original type).
- `temp_dir` — directory used for temporary files (defaults to the system temp path).
- `debug` — when `true`, processing errors throw `ImagingException`; otherwise methods return an empty result.
- `bgcolor` and `second_bgcolor` — colors used for operations that require filling transparent areas.
- `quality` — image quality for formats that support it.
- `watermark_*` — watermark file path, position, padding, and opacity settings.

To override handler configuration per instance, create `ImageManager` manually and pass options as the fifth constructor argument (see below).

### Usage
The package exposes the static facade `Whiterhino\Imaging\Imaging` and the service `ImageManager`. The facade methods return `[relative_path, public_url]` in most cases.

```php
use Whiterhino\Imaging\Imaging;

[$cached, $url] = Imaging::resize('products/sku-1.jpg', 'public', 600, 400, Imaging::RESIZE_MODE_KEEPRATIO);
```

Available operations:
- `Imaging::resize($path, $originDisk, $width, $height, $mode)` — resizing with `pad`, `stretch`, or `keepratio` modes.
- `Imaging::crop($path, $originDisk, $x1, $y1, $x2, $y2, $mode)` — cropping with pixel or percentage offsets.
- `Imaging::watermark($path, $originDisk, $watermarkFilename, $mode)` — adding a watermark (`single` or `fill`).
- `Imaging::resizeAndWatermark(...)` — combines resizing and watermarking.

Results are cached: if the source file is unchanged, the cached derivative is reused. To force regeneration, call `ImageManager::make()` with `$force = true` or clear the cached file.

#### Working with `ImageManager`
Use the container to obtain the manager and queue multiple operations:

```php
use Illuminate\Support\Facades\App;
use Whiterhino\Imaging\ImageManager;
use Whiterhino\Imaging\Handlers\HandlerContract;
use SplFileInfo;

$manager = App::make(ImageManager::class, ['public']);

$cached = $manager->make(
    'products/sku-1.jpg',
    'products/cache/sku-1-thumb',
    function (HandlerContract $image) {
        $image->resize(300, 300, true, true);
        $image->watermark(new SplFileInfo(storage_path('app/watermarks/default.png')));
    }
);

$url = $manager->generateUrl($cached);
```

Need handler-specific overrides? Instantiate the manager directly:

```php
use Whiterhino\Imaging\Handlers\GdHandler;
use Whiterhino\Imaging\ImageManager;
use Whiterhino\Imaging\Types\ImageType;

$customManager = new ImageManager(
    'public',
    'imagecache',
    GdHandler::class,
    ImageType::WEBP,
    [
        'quality' => 85,
        'bgcolor' => '#FFFFFF',
    ]
);
```

### Testing
The repository includes Docker tooling for isolated test runs.

**Docker run:**
1. Build the image and install dependencies:
   ```shell
   docker compose build tests
   docker compose run --rm tests composer install
   ```
2. Execute the test suite:
   ```shell
   docker compose run --rm tests composer test
   ```
   Provide your UID/GID if you want to avoid permission changes:
   ```shell
   UID=$(id -u) GID=$(id -g) docker compose run --rm tests composer test
   ```

**Local run (without Docker):**
1. Install dependencies:
   ```shell
   composer install
   ```
2. Run tests:
   ```shell
   composer test
   ```

#### Visual tests
Generate visual fixtures by running the `visual` group with `IMAGING_VISUAL_FIXTURES=1`. The generated inputs and outputs are stored under `tests/Fixtures/process/<handler>/{input,output}`.

- In Docker (make sure the variable is passed into the container):
  ```shell
  docker compose run --rm -e IMAGING_VISUAL_FIXTURES=1 tests vendor/bin/phpunit --group visual
  ```
  If your shell does not support `-e`, wrap the command:
  ```shell
  docker compose run --rm tests bash -lc 'IMAGING_VISUAL_FIXTURES=1 vendor/bin/phpunit --group visual'
  ```
- Locally:
  ```shell
  IMAGING_VISUAL_FIXTURES=1 vendor/bin/phpunit --group visual
  ```

The resulting images can be reviewed manually. Re-running the command overwrites the fixtures, so archive them if needed.

If you see `Tests: 9, Assertions: 0, Skipped: 9`, install the missing imaging extension or use the provided Docker image — the skip indicates that the current PHP runtime lacks `ext-gd` or `ext-imagick`.

Tests rely on `orchestra/testbench`, so you do not need a full Laravel application — PHP plus the selected imaging extension is enough.

### Tips
- Imaging errors are logged via the `imaging` log channel; configure it in `config/logging.php` to avoid missing warnings about missing files.
- The target disk should be publicly accessible (or provide `url`/`temporaryUrl` configuration) for `generateUrl()` to return usable links.
- Integrating with a CDN? Wrap the returned URL and adjust the host as needed.
