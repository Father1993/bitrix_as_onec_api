# as.onecstock — 1C stock import for 1C-Bitrix

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![GitHub Repo](https://img.shields.io/badge/GitHub-Father1993%2Fbitrix--as--onecstock-181717?logo=github)](https://github.com/Father1993/bitrix-as-onecstock)

**English:** Drop-in module for **1C-Bitrix** (Bitrix Framework) that exposes an **HTTP POST (JSON)** endpoint to **import catalog stock** from **1C** (or any client posting JSON). Uses catalog / iblock APIs and supports store mapping (including list-property based warehouse codes).

| | |
|---|---|
| **Module ID** | `as.onecstock` |
| **Integration** | HTTP POST + JSON (see URLs below) |
| **Auth** | `X-Stock-Import-Key` / `access_key` in body / `login`+`password` in body |
| **PHP** | 7.4+ (project-tested; align with your Bitrix version) |

**Breaking change (1.0.6+):** the former REST method `as.stock.import` and webhook registration were **removed**. Clients that called `/rest/.../as.stock.import` must switch to the **same JSON body** via **POST** to one of the HTTP URLs below.

## Links

- **Repository:** [github.com/Father1993/bitrix-as-onecstock](https://github.com/Father1993/bitrix-as-onecstock)
- **Clone:** `git clone https://github.com/Father1993/bitrix-as-onecstock.git`
- **Issues / ideas:** [github.com/Father1993/bitrix-as-onecstock/issues](https://github.com/Father1993/bitrix-as-onecstock/issues)

## HTTP entry points (recommended)

- **`/local/tools/as_onecstock_import.php`** — удобный URL для интеграций (полный prolog, проверка ключа как у внешнего клиента).
- **`/local/modules/as.onecstock/public/http_import.php`** — то же назначение, если веб-сервер отдаёт файлы из `local`.
- **`public/http_stocks_import.php`** (в каталоге модуля) — подключать **после** чужого `prolog` (агент, внутренний сценарий); по умолчанию `ONEC_STOCK_IMPORT_SKIP_AUTH = true`.

Метод запроса: **POST**, ответ: **JSON**, коды HTTP и формат ошибок — как в `asStockImportFrom1cRun()` (`include/stock_import_engine.php`).

## Features

- Single HTTP contour for inbound stock JSON (no REST module required for import).
- Configurable limits via **Settings → Modules → as.onecstock** with fallback to `ONEC_STOCK_IMPORT_*` constants in `php_interface` (optional).
- Batch processing, body size limits, optional default store ID.
- Optional include: `public/http_stocks_import.php` after `prolog` for tests or a thin proxy.

## Requirements

- 1C-Bitrix with **Catalog** and **Iblock** modules.

## Installation

### Where the module appears in the admin (important)

Module ID is **`as.onecstock`** (contains a **dot**). In 1C-Bitrix such IDs are treated as **partner-style** modules:

- They are listed under **`/bitrix/admin/partner_modules.php`** (Настройки → Настройки продукта → **Модули** → подраздел партнёрских / маркетплейс-модулей — точное название пункта меню зависит от редакции).
- They are **often not shown** on the short list **`/bitrix/admin/module_admin.php`**, which is oriented at modules **without** a dot in the ID.

So if you do not see `as.onecstock` on `module_admin.php`, that is **expected**. Install from **`partner_modules.php`** (or use the same entry from **Product settings → Modules** tree if your build links there).

This module is deployed as files under `local/modules/as.onecstock/` (not necessarily downloaded from [marketplace.1c-bitrix.ru](https://marketplace.1c-bitrix.ru/)).

1. Copy this folder to:

   `local/modules/as.onecstock/`

   Or clone into that path:

   ```bash
   git clone https://github.com/Father1993/bitrix-as-onecstock.git local/modules/as.onecstock
   ```

2. Open **`/bitrix/admin/partner_modules.php`**, find **AS: 1C stock import** (`as.onecstock`) → **Install**.  
   The installer defines **`PARTNER_NAME`** / **`PARTNER_URI`** for correct partner-module registration.

3. Point your 1C (or other client) to **`POST`** on **`/local/tools/as_onecstock_import.php`** (or `public/http_import.php` under the module) with the same JSON contract as documented in `stock_import_engine.php`. Configure **`ONEC_STOCK_IMPORT_ACCESS_KEY`** (or equivalent module settings) in `local/php_interface/include/config.php`.

4. Site-specific secrets (e.g. `ONEC_STOCK_IMPORT_ACCESS_KEY`) should live in your `local/php_interface/include/config.php` or environment policy — **do not commit production keys**.

On upgrade to **1.0.6+**, the module run clears legacy **`OnRestServiceBuildDescription`** handlers from the database (if the **rest** module is installed), so old REST registrations for this module are removed automatically.

## Reinstall and upgrades

- **Deploy new files only:** replace `local/modules/as.onecstock/`. On the next request that loads the module, `Installer::syncIfNewVersion()` runs: removes legacy REST handlers (if `rest` is installed), reapplies admin **W** rights, updates `install_script_version` in options.
- **Clean reinstall:** **partner_modules.php** → Uninstall `as.onecstock` → Install again. Options stored in `b_option` for the module may be cleared on uninstall depending on core behaviour; re-check **Settings → Modules → as.onecstock** and `ONEC_STOCK_IMPORT_*` in `config.php`.
- **Emergency install:** [`install/tools/force_install.php`](install/tools/force_install.php) once as admin, then delete that file on production.

## Development

- **Where to change logic:** `include/stock_import_engine.php` (import), `lib/` (D7 helpers, `Installer`), `options.php` (admin form), entry points in `public/` and project file `local/tools/as_onecstock_import.php`.
- **Do not duplicate** HTTP response logic: extend [`include/http_import_response.php`](include/http_import_response.php) or the engine only.
- **Smoke test:** `php -l` on edited files; POST to `/local/tools/as_onecstock_import.php` with a tiny `items` array and valid key.

## Scaling and operations

- Tune **limits** via module settings or `ONEC_STOCK_IMPORT_MAX_ITEMS`, `ONEC_STOCK_IMPORT_MAX_BODY_BYTES`, `ONEC_STOCK_IMPORT_BATCH_SIZE` in `config.php`.
- **Heavy load:** prefer fewer large batches within limits over many tiny requests; ensure PHP-FPM timeouts and memory fit worst-case batch; optional queue in front of the endpoint (1C → message broker → worker → HTTP) keeps the public URL simple.
- **Observability:** log files under `upload/logs/` when logging is enabled in the engine; monitor HTTP 413/401 rates from the reverse proxy.

## Troubleshooting

### Why `module_admin.php` never lists this module (by design)

`ModuleManager::getModulesFromDisk()` filters folders by whether the module ID contains a **dot**:

- **`/bitrix/admin/module_admin.php`** calls `getModulesFromDisk(true, false)` — **only modules whose folder name has no dot** (e.g. `main`, `iblock`).
- **`/bitrix/admin/partner_modules.php`** calls `getModulesFromDisk(true, true, false)` — **only modules whose folder name contains a dot** (partner-style ID), e.g. `as.onecstock`.

So **`as.onecstock` will not appear on `module_admin.php`**. This is not a bug. Install only from **`partner_modules.php`** (or rename the module to an ID without a dot if you need the other screen — breaking change).

### “Install does nothing” on `partner_modules.php` (silent)

Core file `bitrix/modules/main/admin/partner_modules.php` only runs install when **all** of these hold: `install=Y` in the request, user can **`edit_other_settings`**, and **`check_bitrix_sessid()`** succeeds. If the session string is wrong or expired, **the install block is skipped with no error message** (page just renders the list again).

**Fix:**

1. Open **`partner_modules.php`** fresh, log in as admin.
2. Click **Install** from the **action menu in the table** (do not reuse an old bookmarked URL with `sessid=`).
3. After a successful install the browser should redirect to a URL containing **`result=OK`** and **`mod=as.onecstock`**.

**Emergency install (if the button only reloads the page):** while logged in as admin, open once in the browser:

`/local/modules/as.onecstock/install/tools/force_install.php`

It runs the same `DoInstall()` as the official installer and then redirects to `partner_modules.php?result=OK`. **Delete `install/tools/force_install.php` on production after use** (security).

If it still does nothing:

4. Confirm **`PARTNER_NAME`** / **`PARTNER_URI`** in `install/index.php` (included in this repo).
5. If **`CModule::CreateModuleObject('as.onecstock')`** fails (broken `install/index.php`), the core also skips install **silently** — check **PHP error log** and run `php -l` on `install/index.php`.
6. Check **`b_module`** (below) and clear **cache**.

### Module already installed or stuck state

Check the database (table **`b_module`**):

```sql
SELECT MODULE_ID, VERSION, INSTALLED, DATE_ACTIVE
FROM b_module
WHERE MODULE_ID IN ('as.onecstock', 'mk27.onecstock');
```

- If **`as.onecstock`** exists with **`INSTALLED = 'Y'`**, the module is already registered — use **Uninstall** if you need a clean reinstall, or open module settings.
- Remove obsolete rows for old IDs (e.g. `mk27.onecstock`) after migration to avoid confusion.

If installation fails mid-way, this module’s installer attempts to **roll back** (legacy REST cleanup and `unRegisterModule`) when an exception is thrown after `registerModule`.

### PHP errors

Check the site PHP/webserver error log when clicking **Install**.

## Author

**Andrej Spinej** — GitHub: [@Father1993](https://github.com/Father1993).  
Module namespace: `As\Onecstock`.

## License

MIT — see [LICENSE](LICENSE).

---

**Русский (кратко):**

- **Установка:** только **`/bitrix/admin/partner_modules.php`** (не `module_admin.php`). **Импорт:** **POST** → **`/local/tools/as_onecstock_import.php`**, JSON как в разделе «Тело запроса» в [`ADEV/stocks-import-from-1c-testing.md`](../../../ADEV/stocks-import-from-1c-testing.md).
- **Переустановка:** в админке **Удалить** модуль → **Установить** снова; либо просто обновить файлы модуля — при смене версии в `install/version.php` выполнится синхронизация (снятие старых REST-обработчиков, права админов). Аварийно: **`force_install.php`** один раз, потом удалить с прода.
- **Разработка:** правки в `local/modules/as.onecstock/` (`stock_import_engine.php`, `lib/`, `http_import_response.php`); не дублировать ответ JSON в трёх входах. Проект целиком: корневой [`README.md`](../../../README.md).
- **Масштабирование:** лимиты в настройках модуля и `ONEC_STOCK_IMPORT_*`; при росте нагрузки — ресурсы PHP, батчи, при необходимости очередь перед endpoint.

Репозиторий модуля: [github.com/Father1993/bitrix-as-onecstock](https://github.com/Father1993/bitrix-as-onecstock).
