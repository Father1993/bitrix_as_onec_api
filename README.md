# as.onecstock — 1C stock import for 1C-Bitrix

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![GitHub Repo](https://img.shields.io/badge/GitHub-Father1993%2Fbitrix--as--onecstock-181717?logo=github)](https://github.com/Father1993/bitrix-as-onecstock)

**English:** Drop-in module for **1C-Bitrix** (Bitrix Framework) that exposes a **REST webhook** and optional HTTP entry point to **import catalog stock** from **1C** (or any client posting JSON). Uses catalog / iblock APIs and supports store mapping (including list-property based warehouse codes).

| | |
|---|---|
| **Module ID** | `as.onecstock` |
| **REST method** | `as.stock.import` (POST) |
| **REST scope** | `asintegration` |
| **PHP** | 7.4+ (project-tested; align with your Bitrix version) |

## Links

- **Repository:** [github.com/Father1993/bitrix-as-onecstock](https://github.com/Father1993/bitrix-as-onecstock)
- **Clone:** `git clone https://github.com/Father1993/bitrix-as-onecstock.git`
- **Issues / ideas:** [github.com/Father1993/bitrix-as-onecstock/issues](https://github.com/Father1993/bitrix-as-onecstock/issues)

## Features

- REST method `as.stock.import` for inbound webhook integration (Bitrix24 / portal REST).
- Configurable limits via **Settings → Modules → as.onecstock** with fallback to `ONEC_STOCK_IMPORT_*` constants in `php_interface` (optional).
- Batch processing, body size limits, optional default store ID.
- Optional include: `public/http_stocks_import.php` after `prolog` for tests or a thin proxy.

## Requirements

- 1C-Bitrix with **Catalog** and **Iblock** modules.
- **REST** module installed if you use the webhook (recommended for production).

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

3. Create or update a REST inbound webhook and grant scope **`asintegration`**, then call method **`as.stock.import`** (POST).

4. Site-specific secrets (e.g. `ONEC_STOCK_IMPORT_ACCESS_KEY`) should live in your `local/php_interface/include/config.php` or environment policy — **do not commit production keys**.

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

If installation fails mid-way, this module’s installer attempts to **roll back** (unregister REST handlers and `unRegisterModule`) when an exception is thrown after `registerModule`.

### PHP errors

Check the site PHP/webserver error log when clicking **Install**.

## Author

**Andrej Spinej** — GitHub: [@Father1993](https://github.com/Father1993).  
Module namespace: `As\Onecstock`.

## License

MIT — see [LICENSE](LICENSE).

---

**Русский:** ID **`as.onecstock`** содержит **точку** — ядро показывает такой модуль **только** на **`partner_modules.php`**, а **`module_admin.php`** специально **отфильтровывает** все модули с точкой в имени папки — это **не ошибка**. Установка: кнопка **Установить** в списке на `partner_modules.php` (не старый URL с `sessid` — иначе установка **молча не выполнится**). REST: `as.stock.import`. Код: [github.com/Father1993/bitrix-as-onecstock](https://github.com/Father1993/bitrix-as-onecstock). Путь: `local/modules/as.onecstock`.
