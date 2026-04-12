# as.onec_api — 1C JSON API for 1C-Bitrix (stocks, prices, catalog)

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![GitHub Repo](https://img.shields.io/badge/GitHub-Father1993%2Fbitrix__as__onec__api-181717?logo=github)](https://github.com/Father1993/bitrix_as_onec_api)

**English:** Drop-in module for **1C-Bitrix** (Bitrix Framework): **HTTP JSON** via [`JsonApiKernel`](lib/http/jsonapikernel.php) at **`/local/tools/as_onec_api.php`** — stocks (import + read), prices (import + read), product read by `xml_id`. Uses catalog / iblock D7 APIs and store mapping (including list-property based warehouse codes). Custom API in `local/`, not Bitrix core `/rest/`.

**Source code:** [github.com/Father1993/bitrix_as_onec_api](https://github.com/Father1993/bitrix_as_onec_api) — canonical repository for this module (`MODULE_ID` **`as.onec_api`**).  
**Manual API checks (Postman):** [docs/postman-testing.md](docs/postman-testing.md).

| | |
|---|---|
| **Module ID** | `as.onec_api` |
| **Integration** | Single HTTP entry: `local/tools/as_onec_api.php` (`path=/v1/...`) |
| **Auth** | `X-Stock-Import-Key` / `access_key` (body or query) / `login`+`password` (POST body only) |
| **PHP** | 7.4+ (project-tested; align with your Bitrix version) |

**Breaking change (1.0.6+):** the former REST method `as.stock.import` and webhook registration were **removed**. Clients that called `/rest/.../as.stock.import` must send the **same JSON body** via **POST** to the HTTP URLs below.

**Breaking change (1.1.3+):** `local/tools/as_onecstock_api.php` and `local/tools/as_onecstock_import.php` were **removed**. Use only **`/local/tools/as_onec_api.php`** with `path=/v1/...` (import: `?path=/v1/stocks/import`), or `public/http_import.php` for POST-only stock import.

## Links

- **Repository:** [github.com/Father1993/bitrix_as_onec_api](https://github.com/Father1993/bitrix_as_onec_api)
- **Clone:** `git clone https://github.com/Father1993/bitrix_as_onec_api.git local/modules/as.onec_api`
- **Issues:** [github.com/Father1993/bitrix_as_onec_api/issues](https://github.com/Father1993/bitrix_as_onec_api/issues)

## HTTP entry points (recommended)

### Канонический URL

- **`/local/tools/as_onec_api.php`** — единая точка входа: маршрут **`path`** (query) или `PATH_INFO`. Роутер: [`As\OnecApi\Http\JsonApiKernel`](lib/http/jsonapikernel.php).

| Метод | path | Описание |
|--------|------|----------|
| GET | `/v1/stocks` | Остатки по `xml_id` |
| POST | `/v1/stocks/import`, `/v1/stocks` | Импорт остатков |
| GET | `/v1/prices` | Цены по `xml_id` |
| POST | `/v1/prices` | Импорт цен (`items`: `product_xml_id`, `catalog_group_id`, `price`, `currency`) |
| GET | `/v1/products` | Элемент ИБ + `ProductTable` по `xml_id` |

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

- Versioned JSON API (`JsonApiKernel`): stocks, prices, products; single canonical `as_onec_api.php`.
- Bitrix **`rest`** module not required for this contour.
- Configurable limits via **Settings → Modules → as.onec_api** with fallback to `ONEC_STOCK_IMPORT_*` in `php_interface` (optional).
- Batch processing, body size limits, optional default store ID.
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

1. Copy or clone into **`local/modules/as.onec_api/`**:

   ```bash
   git clone https://github.com/Father1993/bitrix_as_onec_api.git local/modules/as.onec_api
   ```

2. Open **`/bitrix/admin/partner_modules.php`**, find **AS: API обмена с 1С** (`as.onec_api`) → **Install**. If upgrading from **`as.onecstock`**, install **`as.onec_api`** first — installer copies `b_option` from the legacy module when it is still installed, then uninstall the old module.  
   **`PARTNER_NAME`** / **`PARTNER_URI`** in `install/index.php` point to the author / repository.

3. Point your 1C (or other client) to **`POST`** **`/local/tools/as_onec_api.php?path=/v1/stocks/import`** (or `public/http_import.php` if you need a fixed path without `path=`) with the JSON contract in `stock_import_engine.php`. Configure **`ONEC_STOCK_IMPORT_ACCESS_KEY`** (or module settings) in `local/php_interface/include/config.php`.

4. Site-specific secrets — **do not commit production keys**.

On upgrade to **1.0.6+**, the module clears legacy **`OnRestServiceBuildDescription`** handlers in the database (if the **rest** module is installed).

## Reinstall and upgrades

- **Deploy new files only:** replace `local/modules/as.onec_api/`. On the next request that loads the module, `Installer::syncIfNewVersion()` runs: removes legacy REST handlers (if `rest` is installed), reapplies admin **W** rights, updates `install_script_version` in options.
- **Clean reinstall:** **partner_modules.php** → Uninstall `as.onec_api` → Install again.
- **Emergency install:** [`install/tools/force_install.php`](install/tools/force_install.php) once as admin, then delete that file on production.

## Development

- **Where to change logic:** `include/stock_import_engine.php` (import helpers), `lib/` (services, `Installer`), `options.php`, `public/`, project `local/tools/as_onec_api.php`.
- **Do not duplicate** HTTP response logic: extend [`include/http_import_response.php`](include/http_import_response.php) or the engine only.
- **Smoke test:** `php -l` on edited files; POST to `as_onec_api.php?path=/v1/stocks/import` with a tiny `items` array and valid key; or use [docs/postman-testing.md](docs/postman-testing.md).

## Scaling and operations

- Tune **limits** via module settings or `ONEC_STOCK_IMPORT_MAX_ITEMS`, `ONEC_STOCK_IMPORT_MAX_BODY_BYTES`, `ONEC_STOCK_IMPORT_BATCH_SIZE` in `config.php`.
- **Heavy load:** prefer fewer large batches within limits; PHP-FPM timeouts and memory; optional queue in front of the endpoint.
- **Observability:** log files under `upload/logs/` when logging is enabled in the engine; monitor HTTP 413/401 rates from the reverse proxy.

## Troubleshooting

### Why `module_admin.php` never lists this module (by design)

`ModuleManager::getModulesFromDisk()` filters by whether the module ID contains a **dot**:

- **`module_admin.php`** — modules **without** a dot.
- **`partner_modules.php`** — partner-style IDs **with** a dot, e.g. `as.onec_api`.

Install only from **`partner_modules.php`** unless you rename the module (breaking change).

### “Install does nothing” on `partner_modules.php` (silent)

Install runs only when `install=Y`, user can **`edit_other_settings`**, and **`check_bitrix_sessid()`** succeeds. Use a fresh admin session and the **Install** action from the table (not an old bookmark with stale `sessid`).

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

- **Установка:** **`/bitrix/admin/partner_modules.php`**. **API:** **`/local/tools/as_onec_api.php`**. Тесты в Postman: [docs/postman-testing.md](docs/postman-testing.md). Подробные curl и контракты: [`ADEV/stocks-import-from-1c-testing.md`](../../../ADEV/stocks-import-from-1c-testing.md).
- **Репозиторий:** [github.com/Father1993/bitrix_as_onec_api](https://github.com/Father1993/bitrix_as_onec_api).
- **Переустановка:** Удалить модуль → Установить; или обновить файлы — при смене версии в `install/version.php` выполнится `syncIfNewVersion()`. Аварийно: **`force_install.php`** один раз, затем удалить с прода.
- **Разработка:** `local/modules/as.onec_api/` (`stock_import_engine.php`, `lib/http/`, `lib/stock/`, `lib/price/`, `lib/product/`). Общий обзор проекта: корневой [`README.md`](../../../README.md).
