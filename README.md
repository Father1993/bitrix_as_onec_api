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

1. Copy this folder to:

   `local/modules/as.onecstock/`

   Or clone into that path:

   ```bash
   git clone https://github.com/Father1993/bitrix-as-onecstock.git local/modules/as.onecstock
   ```

2. In the admin panel: **Settings → Product settings → Modules** → find **AS: 1C stock import** (`as.onecstock`) → **Install**.

3. Create or update a REST inbound webhook and grant scope **`asintegration`**, then call method **`as.stock.import`** (POST).

4. Site-specific secrets (e.g. `ONEC_STOCK_IMPORT_ACCESS_KEY`) should live in your `local/php_interface/include/config.php` or environment policy — **do not commit production keys**.

## Author

**Andrej Spinej** — GitHub: [@Father1993](https://github.com/Father1993).  
Module namespace: `As\Onecstock`.

## License

MIT — see [LICENSE](LICENSE).

---

**Русский:** модуль приёма остатков каталога для обмена с 1С через REST (`as.stock.import`) и настройки в админке Битрикс. Исходный код: [github.com/Father1993/bitrix-as-onecstock](https://github.com/Father1993/bitrix-as-onecstock). Установка: `local/modules/as.onecstock`, затем **Настройки продукта → Модули**.
