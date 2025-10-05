## Библиотека обработки изображений

*[English version](README.en.md)*

Laravel-пакет для централизованной обработки изображений: изменение размеров, обрезка, накладывание водяных знаков и кеширование результатов на диске.

### Требования
- PHP 8.2+
- Laravel 10.x/11.x (пакет использует auto-discovery провайдера)
- Один из PHP-движков для работы с изображениями: `ext-gd` или `ext-imagick`
- Настроенные файловые диски Laravel для исходных (`filesystem.disks.public` по умолчанию) и кешированных (`filesystem.disks.imagecache`) файлов

### Установка
1. Установите пакет через Composer:
   ```shell
   composer require white-rhinoceros/imaging
   ```
2. Опубликуйте конфигурацию и (опционально) языковые файлы:
   ```shell
   php artisan vendor:publish --provider="Whiterhino\\Imaging\\ImagingServiceProvider"
   php artisan vendor:publish --provider="Whiterhino\\Imaging\\ImagingServiceProvider" --tag=imaging-lang
   ```
   После публикации появится конфиг `config/imaging.php` и переводы `lang/{en,ru}/imaging.php`.
3. Убедитесь, что указанные в конфиге диски присутствуют в `config/filesystems.php`. При необходимости создайте диск `imagecache` с публичным доступом.

### Конфигурация
Основные параметры `config/imaging.php`:
- `def_handler` — обработчик по умолчанию: `GdHandler::class` или `ImagickHandler::class`.
- `def_origin_disk` — диск с исходными изображениями.
- `def_target_disk` — диск, куда будут складываться обработанные файлы.
- `def_imagetype` — формат сохранения результата (`ImageType::WEBP`, `ImageType::JPEG`, `null` — формат исходника).
- `temp_dir` — директория для временных файлов (по умолчанию системная tmp-директория).
- `debug` — режим отладки; при `true` выбрасываются исключения вместо возврата пустого результата.
- `bgcolor` и `second_bgcolor` — цвета фона при операциях с прозрачностью.
- `quality` — качество сохраняемых изображений.
- `watermark_*` — параметры водяного знака (файл, позиция, отступы, прозрачность).

При необходимости можно указать собственные настройки для конкретного драйвера, создавая экземпляр `ImageManager` вручную и передавая конфиг пятым аргументом (см. ниже).

### Использование
Пакет предоставляет статический фасад `Whiterhino\Imaging\Imaging` и сервис `ImageManager`. В большинстве кейсов достаточно методов фасада — они возвращают массив `[относительный_путь_к_файлу, публичный_URL]`.

```php
use Whiterhino\Imaging\Imaging;

[$cached, $url] = Imaging::resize('products/sku-1.jpg', 'public', 600, 400, Imaging::RESIZE_MODE_KEEPRATIO);
```

Доступные операции:
- `Imaging::resize($path, $originDisk, $width, $height, $mode)` — изменение размера с режимами `pad`, `stretch`, `keepratio`.
- `Imaging::crop($path, $originDisk, $x1, $y1, $x2, $y2, $mode)` — кадрирование с поддержкой пикселей и процентов.
- `Imaging::watermark($path, $originDisk, $watermarkFilename, $mode)` — добавление водяного знака (`single` или `fill`).
- `Imaging::resizeAndWatermark(...)` — объединяет изменение размера и нанесение водяного знака.

Методы автоматически используют кеш: если исходник не менялся, вернётся ранее обработанный файл. Для принудительного пересоздания можно передать `$force = true` в `ImageManager::make()` или просто удалить файл с кешем.

#### Использование сервиса напрямую
При необходимости можно работать с `ImageManager` вручную, например чтобы объединить несколько операций:

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

Если нужны настройки, отличные от глобального конфига, создайте менеджер вручную и передайте массив параметров драйверу пятым аргументом конструктора:

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

### Тестирование
Пакет комплектуется Docker-окружением для изоляции зависимостей.

**Запуск в Docker:**
1. Соберите окружение и установите зависимости:
   ```shell
   docker compose build tests
   docker compose run --rm tests composer install
   ```
2. Запустите тесты:
   ```shell
   docker compose run --rm tests composer test
   ```
   При необходимости задайте свои UID/GID, чтобы избежать изменения прав:
   ```shell
   UID=$(id -u) GID=$(id -g) docker compose run --rm tests composer test
   ```

**Локальный запуск (без Docker):**
1. Установите зависимости:
   ```shell
   composer install
   ```
2. Запустите тесты:
   ```shell
   composer test
   ```

#### Визуальные (интеграционные) тесты и снепшоты
Визуальные тесты проверяют, что генерация изображений в GD и Imagick даёт ожидаемый результат. Они используют два набора файлов:
- `tests/Fixtures/process/<handler>/input` и `.../output` — временные артефакты, удобные для просмотра.
- `tests/Fixtures/snapshots/<handler>/*.png` — эталонные файлы, с которыми сравниваются текущие результаты.

**Обязательный шаг перед обычным тестовым прогоном** — сгенерировать снепшоты:
```shell
docker compose run --rm -e IMAGING_VISUAL_FIXTURES=1 \
  tests vendor/bin/phpunit --group visual --testdox
```
Если оболочка не поддерживает `-e`, используйте обёртку:
```shell
docker compose run --rm tests \
  bash -lc 'IMAGING_VISUAL_FIXTURES=1 vendor/bin/phpunit --group visual --testdox'
```
Локально команда выглядит так же, но без Docker:
```shell
IMAGING_VISUAL_FIXTURES=1 vendor/bin/phpunit --group visual --testdox
```

Что происходит:
1. В `process/input` и `process/output` появляются файлы, чтобы можно было визуально изучить текущий результат.
2. В `snapshots/` обновляются эталонные изображения.

При обычном `composer test` временные файлы не сохраняются: тест считывает результат из кеша, сравнивает его по MD5 с соответствующим файлом в `snapshots/` и удаляет промежуточные данные. Если снепшота нет — тест помечается `skipped`. Поэтому после любой правки логики обязательно обновляйте эталоны.

#### Coverage-тесты
- `composer test:coverage` — локальный отчёт покрытия (нужен Xdebug).
- Через Docker:
  ```shell
  docker compose run --rm -e XDEBUG_MODE=coverage tests \
    vendor/bin/phpunit --configuration=phpunit.coverage.xml.dist
  ```

Отчёт сохраняется в каталоге `coverage/`.

### Проверки качества
- `composer lint` — проверка синтаксиса всех PHP-файлов.
- `composer test` — запуск PHPUnit.
- `composer test:coverage` — отчёт покрытия (требуется Xdebug; для Docker добавьте `-e XDEBUG_MODE=coverage`).
- `docker compose run --rm -e XDEBUG_MODE=coverage tests vendor/bin/phpunit --configuration=phpunit.coverage.xml.dist` — альтернатива для генерации покрытия внутри контейнера.

### Полезно знать
- Логи ошибок пишутся в канал `imaging`; настройте его в `config/logging.php`, чтобы не терять сообщения о недоступных файлах.
- Для корректного формирования URL кешированных изображений диск `def_target_disk` должен быть публичным или иметь корректно настроенный `url`.
- Если вы используете CDN, можно обернуть возвращаемый URL и подменять домен на стороне приложения.
- Быстрая диагностика окружения: `php artisan imaging:diagnose` проверит доступность дисков, временной директории и необходимых расширений.
