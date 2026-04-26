# as.onec_api — 1C JSON API for 1C-Bitrix (stocks, prices, catalog)

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![GitHub Repo](https://img.shields.io/badge/GitHub-Father1993%2Fbitrix__as__onec__api-181717?logo=github)](https://github.com/Father1993/bitrix_as_onec_api)

**English:** Drop-in module for **1C-Bitrix** (Bitrix Framework): **HTTP JSON** via [`JsonApiKernel`](lib/Http/JsonApiKernel.php) at **`/local/tools/as_onec_api.php`** — stocks (import + read), prices (import + read), product read by `xml_id`, order read, and order status import from 1C. Uses catalog / iblock / sale D7 APIs and store mapping (including list-property based warehouse codes). Custom API in `local/`, not Bitrix core `/rest/`.

**Source code:** [github.com/Father1993/bitrix_as_onec_api](https://github.com/Father1993/bitrix_as_onec_api) — canonical repository for this module (`MODULE_ID` **`as.onec_api`**).  
**Manual API checks (Postman):** [docs/postman-testing.md](docs/postman-testing.md).  
**Склады для 1С-программиста:** [docs/stocks-api-for-1c-postman.md](docs/stocks-api-for-1c-postman.md), справочник складов (GET/POST): [docs/stores-api-postman.md](docs/stores-api-postman.md).  
**Доработка архитектуры / карта файлов для ИИ:** [EXTENSION.md](EXTENSION.md).

| | |
|---|---|
| **Module ID** | `as.onec_api` |
| **Integration** | Single HTTP entry: `local/tools/as_onec_api.php` (`path=/v1/...`) |
| **Auth** | `X-Stock-Import-Key` / `access_key` (body or query) / `login`+`password` (POST body only) |
| **PHP** | 7.4+ (project-tested; align with your Bitrix version) |

**Breaking change (1.0.6+):** the former REST method `as.stock.import` and webhook registration were **removed**. Clients that called `/rest/.../as.stock.import` must send the **same JSON body** via **POST** to the HTTP URLs below.

**Breaking change (1.1.3+):** `local/tools/as_onecstock_api.php` and `local/tools/as_onecstock_import.php` were **removed**. Use only **`/local/tools/as_onec_api.php`** with `path=/v1/...` (import: `?path=/v1/stocks/import`), or `public/http_import.php` for POST-only stock import.

## Critical behavior

Кратко, что важно не сломать при сопровождении (эксплуатация):

| Тема | Суть |
|------|------|
| Точка входа HTTP | Один скрипт `local/tools/as_onec_api.php`, маршруты `path=` / `PATH_INFO`, роутер [`JsonApiKernel`](lib/Http/JsonApiKernel.php). |
| Ленивый движок | [`StockEngineBootstrap::ensureLoaded()`](lib/Stock/StockEngineBootstrap.php) вызывается в сервисах **после** `Loader::includeModule('catalog'/'iblock')`. [`include.php`](include.php) не подключает `stock_import_engine.php` при каждом `includeModule`. |
| Импорт остатков | [`asStockImportFrom1cRun()`](include/stock_import_engine.php) — обёртка для агентов/старого кода → [`ImportService::run()`](lib/Stock/ImportService.php); валидация построчная, агрегированные остатки по складам пересчитываются один раз на весь запрос. |
| Breaking 1.1.3 | Удалены `local/tools/as_onecstock_*.php`; только POST: [`public/http_import.php`](public/http_import.php). |
| Лимиты | `b_option` модуля (настройки админки) + fallback `ONEC_STOCK_IMPORT_*` в `php_interface`. |

## Текущее состояние интеграции

На текущий момент модуль `as.onec_api` закрывает единый HTTP JSON-контур для интеграции с 1С по остаткам, ценам, товарам и заказам. Основная точка входа одна: `local/tools/as_onec_api.php`, а маршрутизация делается через `path=/v1/...`.

По остаткам:

- чтение остатков идёт через `GET /v1/stocks`;
- импорт остатков идёт через `POST /v1/stocks/import` или `POST /v1/stocks`;
- логика работы учитывает оба режима Битрикс: без складского учёта и со складским учётом;
- при включённом складском учёте запись идёт в складские остатки, а агрегированные остатки каталога пересчитываются один раз на весь запрос;
- если у товара неоднозначный `XML_ID`, запись блокируется и позиция попадает в `errors[]`, чтобы не обновить не тот товар.

По заказам и статусам:

- добавлен `GET /v1/orders` для чтения заказов прямо из модуля;
- добавлен `POST /v1/orders/status` для входящего контура `1С -> Bitrix`;
- входящий код статуса 1С может либо напрямую трактоваться как `STATUS_ID` Битрикс, либо проходить через JSON-мэппинг в настройках;
- API умеет возвращать `updated`, `failed`, `no_change`, `results[]`, `errors[]`, чтобы 1С могла разбирать итог по строкам, а не только по HTTP-коду;
- защита от проектных мутаций при сохранении заказа реализована через флаг `AS_ONEC_API_SKIP_ORDER_MUTATORS`, чтобы не срабатывали побочные изменения из `city_handlers.php`;
- входной `comment` считается служебной информацией интеграции и логируется, но не записывается в `USER_DESCRIPTION`, потому что это поле показывается пользователю в личном кабинете.

## Module settings

Файл: [`options.php`](options.php). Форма **Настройки → Настройки продукта → Модули → as.onec_api** (`bitrix/admin/settings.php`):

- Сохранение (`Update` / `RestoreDefaults`) выполняется только при **`GetGroupRight('as.onec_api') >= 'W'`**.
- **`check_bitrix_sessid()` не вызывается** намеренно: при вложенном выводе формы через ядро `settings.php` проверка sessid давала ложный отказ. Защита — права на модуль и стандартная админ-сессия Bitrix (как у многих partner-модулей).

Это **не** то же самое, что кнопка **Install** на `partner_modules.php`: там sessid по-прежнему проверяет **ядро** (см. раздел Troubleshooting ниже).

## Links

- **Repository:** [github.com/Father1993/bitrix_as_onec_api](https://github.com/Father1993/bitrix_as_onec_api)
- **Clone:** `git clone https://github.com/Father1993/bitrix_as_onec_api.git local/modules/as.onec_api`
- **Issues:** [github.com/Father1993/bitrix_as_onec_api/issues](https://github.com/Father1993/bitrix_as_onec_api/issues)

## HTTP entry points (recommended)

### Канонический URL

- **`/local/tools/as_onec_api.php`** — единая точка входа: маршрут **`path`** (query) или `PATH_INFO`. Роутер: [`As\OnecApi\Http\JsonApiKernel`](lib/Http/JsonApiKernel.php).

| Метод | path | Описание |
|--------|------|----------|
| GET | `/v1/stocks` | Остатки по `xml_id` (см. поля ответа ниже) |
| POST | `/v1/stocks/import`, `/v1/stocks` | Импорт остатков |
| GET | `/v1/prices` | Цены по `xml_id` |
| POST | `/v1/prices` | Импорт цен (`items`: `product_xml_id`, `catalog_group_id`, `price`, `currency`) |
| GET | `/v1/products` | Элемент ИБ + `ProductTable` по `xml_id` |
| GET | `/v1/orders` | Заказ(ы) Bitrix: список, фильтр по `status_id`, поиск по `order_xml_id` / `order_id` |
| POST | `/v1/orders/status` | Импорт статусов заказов из 1С (`order_xml_id`/`order_id`, `status_code_1c`, опционально `paid`, `allow_delivery`, `deducted`) |
| GET | `/v1/stores` | Справочник складов (`b_catalog_store`): список или один склад по `store_id` / `store_xml_id` / `code` (см. [docs/stores-api-postman.md](docs/stores-api-postman.md)) |
| POST | `/v1/stores` | Создание/обновление складов (пакет `items`, как цены/остатки) |

**POST `/v1/stocks` / `/v1/stocks/import`, POST `/v1/prices`, POST `/v1/orders/status` и POST `/v1/stores` — важное поведение:**

- Каждая строка `items` теперь либо применяется, либо попадает в `failed` / `errors[]` с причиной. Некорректные строки больше не отбрасываются молча на этапе нормализации.
- Если в каталоге найдено несколько элементов с одним и тем же `XML_ID`, такая строка не импортируется и возвращается ошибка по позиции.
- Для заказов поиск идёт по `order_xml_id` или `order_id`; при дубле `XML_ID` заказа строка считается ошибочной.
- Для успешного HTTP-ответа `200` ориентируйтесь на поля `updated`, `failed`, `no_change`, `results`, `errors`, `errors_truncated`, а не только на код ответа.

**GET `/v1/stocks` — поля JSON:**

- **`inventory_management: false`:** **`quantity`** — значение `ProductTable.QUANTITY`.
- **`inventory_management: true`:** **`stores`** (остатки по `b_catalog_store_product`), **`quantity_total`** — сумма `stores[].amount`; **`catalog_quantity`** — `ProductTable.QUANTITY` (часто совпадает с «Остаток» в карточке ТП). Пока по товару нет строк складов, `quantity_total` может быть `0`, а `catalog_quantity` — ненулевым; для сверки с витриной используйте **`catalog_quantity`**, для склада — **`stores` / `quantity_total`**.

- **`/local/modules/as.onec_api/public/http_import.php`** — альтернатива: только POST импорт остатков (внутри выставляет `path=/v1/stocks/import`).
- **`public/http_stocks_import.php`** — после чужого `prolog`; по умолчанию `ONEC_STOCK_IMPORT_SKIP_AUTH = true`.

Пример:

```bash
curl -sS -G "https://example.ru/local/tools/as_onec_api.php" \
  --data-urlencode "path=/v1/stocks" \
  --data-urlencode "xml_id=YOUR-PRODUCT-XML-ID" \
  -H "X-Stock-Import-Key: YOUR_KEY"
```

## Features

- Versioned JSON API (`JsonApiKernel`): stocks, prices, products, stores (read + write), orders; single canonical `as_onec_api.php`.
- Versioned JSON API (`JsonApiKernel`): order read plus order status import from 1C.
- Bitrix **`rest`** module not required for this contour.
- Limits and lazy-load details: [Critical behavior](#critical-behavior).
- Batch processing, body size limits, per-item validation with explicit errors, optional default store ID, optional order status mapping and transition policy.
- Optional: `public/http_stocks_import.php` after `prolog` for tests or a thin proxy.

## Requirements

- 1C-Bitrix with **Catalog** and **Iblock** modules.

## Installation

### Where the module appears in the admin (important)

Module ID is **`as.onec_api`** (contains a **dot**). In 1C-Bitrix such IDs are treated as **partner-style** modules:

- Listed under **`/bitrix/admin/partner_modules.php`** (Настройки → Настройки продукта → **Модули** → партнёрские / маркетплейс — точное название зависит от редакции).
- **Often not shown** on **`/bitrix/admin/module_admin.php`** (modules **without** a dot in the ID).

If you do not see `as.onec_api` on `module_admin.php`, that is **expected**. Install from **`partner_modules.php`**.

This module is deployed as files under `local/modules/as.onec_api/` (not necessarily from [marketplace.1c-bitrix.ru](https://marketplace.1c-bitrix.ru/)).

### HTTP entry script in `local/tools/` (not created by Install)

The JSON API is invoked via **`/local/tools/as_onec_api.php`**. **Installing the module in the admin (`partner_modules.php` → Install) does not copy anything into `local/tools/`** — Bitrix only registers the module under `local/modules/as.onec_api/`.

The repository therefore includes a **reference copy** of that script inside the module: [`tools/as_onec_api.php`](tools/as_onec_api.php) (same content as the site entry point).

After you deploy the module, **copy or symlink it once** into `local/tools/` (create the directory if needed):

```bash
mkdir -p local/tools
cp local/modules/as.onec_api/tools/as_onec_api.php local/tools/as_onec_api.php
```

If `local/tools/as_onec_api.php` already exists (e.g. from an older setup), compare it with the module copy when upgrading.

1. Copy or clone into **`local/modules/as.onec_api/`**:

   ```bash
   git clone https://github.com/Father1993/bitrix_as_onec_api.git local/modules/as.onec_api
   ```

2. Open **`/bitrix/admin/partner_modules.php`**, find **AS: API обмена с 1С** (`as.onec_api`) → **Install**. If upgrading from **`as.onecstock`**, install **`as.onec_api`** first — installer copies `b_option` from the legacy module when it is still installed, then uninstall the old module.  
   **`PARTNER_NAME`** / **`PARTNER_URI`** in `install/index.php` point to the author / repository.

3. Point your 1C (or other client) to **`POST`** **`/local/tools/as_onec_api.php?path=/v1/stocks/import`** (or `public/http_import.php` if you need a fixed path without `path=`) with the JSON contract in `stock_import_engine.php`. Configure **`ONEC_STOCK_IMPORT_ACCESS_KEY`** (or module settings) in `local/php_interface/include/config.php`.
4. To read orders from the module itself, use **`GET /local/tools/as_onec_api.php?path=/v1/orders`** with `X-Stock-Import-Key` (or `access_key` in query). Optional query params: `order_xml_id`, `order_id`, `status_id`, `page`, `items_per_page`.
5. For order statuses use **`POST /local/tools/as_onec_api.php?path=/v1/orders/status`**. By default the incoming `status_code_1c` is treated as the target `STATUS_ID` Bitrix; for external codes from 1С configure JSON mapping in module settings.
6. If 1С must also control payment/shipment flags, explicitly enable this in module settings (`order_status_sync_payment`, `order_status_sync_shipment`) or via matching constants/options.
7. Site-specific secrets — **do not commit production keys**.

On upgrade to **1.0.6+**, the module clears legacy **`OnRestServiceBuildDescription`** handlers in the database (if the **rest** module is installed).

## Reinstall and upgrades

- **Deploy new files only:** replace `local/modules/as.onec_api/`. On the next request that loads the module, `Installer::syncIfNewVersion()` runs: removes legacy REST handlers (if `rest` is installed), reapplies admin **W** rights, updates `install_script_version` in options.
- **Clean reinstall:** **partner_modules.php** → Uninstall `as.onec_api` → Install again.
- **Emergency install:** [`install/tools/force_install.php`](install/tools/force_install.php) once as admin, then delete that file on production.

## Development

- **Where to change logic:** `include/stock_import_engine.php` (import helpers), `lib/` (services, `Installer`), `options.php`, `public/`, site entry `local/tools/as_onec_api.php` (reference copy in [`tools/as_onec_api.php`](tools/as_onec_api.php)).
- **Do not duplicate** HTTP response logic: extend [`include/http_import_response.php`](include/http_import_response.php) or the engine only.
- **Smoke test:** `php -l` on edited files; POST to `as_onec_api.php?path=/v1/stocks/import` with a tiny `items` array and valid key; or use [docs/postman-testing.md](docs/postman-testing.md).

## Scaling and operations

- Tune **limits** via module settings or `ONEC_STOCK_IMPORT_MAX_ITEMS`, `ONEC_STOCK_IMPORT_MAX_BODY_BYTES`, `ONEC_STOCK_IMPORT_BATCH_SIZE` in `config.php`.
- For order status sync you can configure JSON mapping and allowed transitions in module settings: `order_status_map_json`, `order_status_allowed_transitions_json`.
- **Heavy load:** prefer fewer large batches within limits; PHP-FPM timeouts and memory; optional queue in front of the endpoint.
- **Observability:** log files under `upload/logs/` when logging is enabled in the engine; monitor HTTP 413/401 rates from the reverse proxy.

## Orders status API

### GET `/v1/orders`

Контур чтения заказов теперь вынесен в модуль и может использоваться вместо прямой зависимости от legacy-скрипта `orders_export_to_1c.php`.

Поддерживаются query-параметры:

- `order_xml_id` или `order_id` для чтения одного заказа;
- `status_id` для фильтрации списка;
- `page`, `items_per_page` для пагинации списка;
- `order_xml_id_like` для отбора по части внешнего кода заказа.

Возвращаются:

- базовые поля заказа (`id`, `xml_id`, `price`, `currency`, `status_id`, `status_name`, `payed`, `canceled`);
- агрегированные флаги `allow_delivery` / `deducted`;
- свойства заказа как словарь `properties`;
- позиции заказа `items`;
- детали оплат `payments` и отгрузок `shipments`;
- `pickup` и `order_description` для совместимости с существующим контуром обмена.

### POST `/v1/orders/status`

Минимальный контракт:

```json
{
  "items": [
    {
      "order_xml_id": "ORDER-XML-ID",
      "status_code_1c": "P",
      "event_at": "2026-04-21 10:30:00",
      "comment": "Оплачен в 1С"
    }
  ]
}
```

Поддерживаются:

- `order_xml_id` или `order_id` для поиска заказа;
- `status_code_1c` / `status_code` / `status` / `event_code` как входящий код статуса;
- опционально `paid`, `allow_delivery`, `deducted`;
- опционально `event_at`, если нужно проставлять даты оплаты/отгрузки.

Поведение:

- если JSON-мэппинг не задан, модуль трактует входящий код как целевой `STATUS_ID` Bitrix;
- если статус уже установлен и дополнительные флаги не меняются, запись идёт в `no_change`;
- если заказ отменён, смена статуса блокируется без отдельной политики;
- если заданы `order_status_allowed_transitions_json`, API проверяет разрешённые переходы;
- изменение оплаты и отгрузки выполняется только при включённых опциях модуля;
- в `results[]` возвращаются успешные и `no_change`-результаты по строкам, чтобы 1С могла разбирать итог без чтения логов;
- входной `comment` попадает в интеграционный лог, но не записывается в `USER_DESCRIPTION`, чтобы не портить пользовательский комментарий в личном кабинете.

## Stocks API: что важно для интеграции

- При `inventory_management=false` эндпоинт `GET /v1/stocks` возвращает одно поле `quantity`, и импорт обновляет `ProductTable.QUANTITY`.
- При `inventory_management=true` чтение возвращает `stores[]`, `quantity_total` и `catalog_quantity`; для сверки по складам ориентируйтесь на `stores[]` и `quantity_total`, а для витрины и карточки товара проверяйте `catalog_quantity`.
- В режиме складского учёта для записи нужен склад: `store_id`, либо `store_xml_id` / `store_code`, либо настроенный `default_store_id`.
- `store_xml_id` умеет резолвиться не только в `b_catalog_store.XML_ID` / `CODE`, но и через значение списка `MESTO_KHRANENIYA` у ТП, если в проекте этот контур используется.
- Значение `amount` не может быть отрицательным; `0` является валидным значением и означает обнуление остатка.
- Успешный HTTP `200` для импортов не гарантирует, что все строки применились: итог всегда проверяется по `updated`, `failed`, `errors[]`, `errors_truncated`.

## Troubleshooting

### Why `module_admin.php` never lists this module (by design)

`ModuleManager::getModulesFromDisk()` filters by whether the module ID contains a **dot**:

- **`module_admin.php`** — modules **without** a dot.
- **`partner_modules.php`** — partner-style IDs **with** a dot, e.g. `as.onec_api`.

Install only from **`partner_modules.php`** unless you rename the module (breaking change).

### `partner_modules.php`: “Install does nothing” (silent)

**Только установка модуля:** ядро выполняет install, если `install=Y`, у пользователя есть **`edit_other_settings`**, и проходит **`check_bitrix_sessid()`**. Откройте страницу заново, зайдите админом и нажмите **Install** в таблице (не старый bookmark с просроченным `sessid`). К **форме настроек** модуля в `settings.php` это не относится — см. [Module settings](#module-settings).

**Emergency install:** `/local/modules/as.onec_api/install/tools/force_install.php` once — then **delete on production**.

### Module already installed or stuck state

```sql
SELECT MODULE_ID, VERSION, INSTALLED, DATE_ACTIVE
FROM b_module
WHERE MODULE_ID IN ('as.onec_api', 'as.onecstock', 'mk27.onecstock');
```

If installation fails mid-way, the installer attempts **roll back** (legacy REST cleanup and `unRegisterModule`) when an exception is thrown after `registerModule`.

### PHP errors

Check the site PHP/webserver error log when clicking **Install**.

## Author

**Andrej Spinej** — GitHub: [@Father1993](https://github.com/Father1993).  
Module namespace: `As\OnecApi`.

## License

MIT — see [LICENSE](LICENSE).

---

**Русский (кратко):**

- **Установка:** **`/bitrix/admin/partner_modules.php`**. **API:** **`/local/tools/as_onec_api.php`** — файл в `local/tools/` **не создаётся** установкой модуля; эталон лежит в репозитории: **`local/modules/as.onec_api/tools/as_onec_api.php`** (скопировать в `local/tools/` вручную). **Настройки модуля:** сохранение при праве **W** на `as.onec_api`, **без** `check_bitrix_sessid()` в форме (см. раздел *Module settings* выше). Тесты в Postman: [docs/postman-testing.md](docs/postman-testing.md). Подробные curl: [`ADEV/stocks-import-from-1c-testing.md`](../../../ADEV/stocks-import-from-1c-testing.md).
- **Репозиторий:** [github.com/Father1993/bitrix_as_onec_api](https://github.com/Father1993/bitrix_as_onec_api).
- **Переустановка:** Удалить модуль → Установить; или обновить файлы — при смене версии в `install/version.php` выполнится `syncIfNewVersion()`. Аварийно: **`force_install.php`** один раз, затем удалить с прода.
- **Разработка:** `local/modules/as.onec_api/` (`stock_import_engine.php`, `lib/Http/`, `lib/Stock/`, `lib/Price/`, `lib/Product/`). Общий обзор проекта: корневой [`README.md`](../../../README.md).
