## Библиотека обработки изображений 
### Основано на FuelPHP 1.8.2


После установки пакета, необходимо выполнить:
```shell
php artisan vendor:publish --provider="Whiterhino\Imaging\ImagingServiceProvider"
```

### Тестовое окружение

Чтобы запустить тесты пакета в Docker:

1. Соберите образ и установите зависимости:
   ```shell
   docker compose build tests
   docker compose run --rm tests composer install
   ```
2. Выполните тесты:
   ```shell
   docker compose run --rm tests composer test
   ```

При необходимости укажите свои `UID`/`GID`, чтобы избежать изменения прав на файлы:
```shell
UID=$(id -u) GID=$(id -g) docker compose run --rm tests composer test
```
