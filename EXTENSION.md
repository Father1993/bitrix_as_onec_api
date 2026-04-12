# Расширение модуля `as.onec_api` (для разработчиков и ИИ)

Краткая карта кода: куда вносить изменения, чтобы не ломать контракт HTTP и ленивую загрузку движка.

Пользовательский контракт, breaking changes и URL — в [README.md](README.md).

## Поток HTTP JSON API

```mermaid
flowchart LR
  tools["local/tools/as_onec_api.php"]
  prolog["prolog_before.php"]
  boot["include/api_http_bootstrap.php"]
  kernel["JsonApiKernel::dispatch"]
  svc["Read/Import сервисы"]
  eng["stock_import_engine.php"]

  tools --> prolog --> boot --> kernel --> svc
  svc -->|"StockEngineBootstrap::ensureLoaded"| eng
```

- Рабочая точка входа веб-сервера: `local/tools/as_onec_api.php` ([относительно корня сайта](../../tools/as_onec_api.php)). Установка модуля в админке **не** копирует этот файл — эталон в репозитории: [tools/as_onec_api.php](tools/as_onec_api.php) (скопировать в `local/tools/`).
- После prolog: [include/api_http_bootstrap.php](include/api_http_bootstrap.php) — `Loader::includeModule('as.onec_api')`, создание [JsonApiKernel](lib/Http/JsonApiKernel.php).
- Процедурный движок [include/stock_import_engine.php](include/stock_import_engine.php) **не** подключается из [include.php](include.php); его подгружает [StockEngineBootstrap](lib/Stock/StockEngineBootstrap.php) после подключения `catalog` / `iblock`.
- [include.php](include.php) регистрирует **явный PSR-4** для `As\OnecApi\` (в дополнение к `.settings.php`), чтобы классы находились сразу после установки модуля и на Linux с регистрозависимыми путями.

## Куда править

| Задача | Файл(ы) |
|--------|---------|
| Скрипт точки входа `local/tools/` | Эталон в репозитории: [tools/as_onec_api.php](tools/as_onec_api.php) — на сайт копируется вручную, не через Install |
| Новый HTTP-маршрут | [lib/Http/JsonApiKernel.php](lib/Http/JsonApiKernel.php) — `routes()` и обработчик |
| Авторизация GET / query | [lib/Http/ApiKeyGuard.php](lib/Http/ApiKeyGuard.php); функции в [include/stock_import_engine.php](include/stock_import_engine.php) (`asStockApiAuthBySecretKey`, `asStockImportFrom1cAuth`) |
| Общий preflight чтения по `xml_id` | [lib/Catalog/CatalogReadPreflight.php](lib/Catalog/CatalogReadPreflight.php) — `CatalogReadPreflight` |
| Импорт остатков (строки, склады, ORM) | [include/stock_import_engine.php](include/stock_import_engine.php); обёртка [lib/Stock/ImportService.php](lib/Stock/ImportService.php) |
| Импорт цен | [lib/Price/PriceImportService.php](lib/Price/PriceImportService.php) |
| Чтение остатков / цен / товара | [lib/Stock/StockReadService.php](lib/Stock/StockReadService.php), [lib/Price/PriceReadService.php](lib/Price/PriceReadService.php), [lib/Product/ProductReadService.php](lib/Product/ProductReadService.php) |
| Лимиты и опции модуля | [lib/StockImportOptions.php](lib/StockImportOptions.php), [options.php](options.php) |
| Установка / синхрон версии | [install/index.php](install/index.php), [lib/Installer.php](lib/Installer.php) |
| Единый JSON-ответ, общий 413 для импортов | [lib/Http/JsonResponse.php](lib/Http/JsonResponse.php) (`send`, `withApiVersion`, `payloadTooLarge`) |

## Правило ленивой загрузки

Любой код, вызывающий функции из `stock_import_engine.php`, должен:

1. Выполнить `Loader::includeModule('catalog')` и `Loader::includeModule('iblock')` (или эквивалентную проверку).
2. Вызвать `StockEngineBootstrap::ensureLoaded()`.

Так сделано в guard, read/import сервисах и в [CatalogReadPreflight](lib/Catalog/CatalogReadPreflight.php).

## D7 ORM (ориентир)

Используются среди прочего: `ElementTable`, `PropertyTable`, `PropertyEnumerationTable`, `ProductTable`, `StoreTable`, `StoreProductTable`, `PriceTable`, `GroupTable`. Старый API каталога (`CIBlockElement::GetList` и т.п.) в модуле не используется для этих сценариев.

Исключение по ядру: после батча остатков при складском учёте может вызываться `\CCatalogStore::recalculateProductsBalances()` (см. комментарий `@todo` в [ImportService](lib/Stock/ImportService.php)).

## Ограничения проекта

- Кастомизация сайта — в `local/`; ядро `bitrix/` не править.
- ID инфоблоков и константы каталога — из `local/php_interface/include/config.php` (движок опирается на `IBLOCK_CATALOG`, SKU, опционально офферы и свойства склада).

## Чеклист регрессии после правок

- Маршруты и параметры из [README.md](README.md): `path=/v1/stocks`, `/v1/stocks/import`, `/v1/prices`, `/v1/products`.
- POST импорт остатков и цен: лимиты тела, авторизация ключом / `login`+`password` (только POST).
- GET с `access_key` в query или ключом в заголовке.
- Настройки модуля: права `>= W` на запись; сценарии из README (sessid намеренно не проверяется в форме).
- См. также [ADEV/stocks-import-from-1c-testing.md](../../../ADEV/stocks-import-from-1c-testing.md) при наличии.
