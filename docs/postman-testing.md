# Postman: проверка JSON API `as.onec_api`

Краткая шпаргалка для ручной проверки эндпоинтов [`JsonApiKernel`](../lib/http/jsonapikernel.php). Полное описание контрактов и curl — в [`ADEV/stocks-import-from-1c-testing.md`](../../../ADEV/stocks-import-from-1c-testing.md).

Если нужен отдельный понятный документ именно по складам для 1С-программиста, используйте [stocks-api-for-1c-postman.md](stocks-api-for-1c-postman.md).

## Переменные окружения Postman

| Variable | Пример | Назначение |
|----------|--------|------------|
| `base_url` | `https://your-site.ru/local/tools/as_onec_api.php` | Каноническая точка входа |
| `access_key` | значение `ONEC_STOCK_IMPORT_ACCESS_KEY` из `config.php` | Ключ для заголовка или тела |

**Заголовок по умолчанию (коллекция):** `X-Stock-Import-Key: {{access_key}}`  
Для GET допустимо передать ключ в query: `access_key={{access_key}}` (менее предпочтительно).

---

## 1. GET остатки — `/v1/stocks`

- **Method:** GET  
- **URL:** `{{base_url}}?path=/v1/stocks&xml_id=ВАШ_XML_ID`  
- **Headers:** `X-Stock-Import-Key: {{access_key}}`  

**Ожидание:** `200` и JSON с остатками; `400` без `xml_id`; `401` без ключа; `404` если товар не найден.

При включённом складском учёте (`inventory_management: true`) в ответе есть **`quantity_total`** (сумма по `stores`) и **`catalog_quantity`** (`ProductTable.QUANTITY`, как в карточке товара). Подробнее — [README.md](../README.md) (раздел GET `/v1/stocks`).

---

## 2. GET цены — `/v1/prices`

- **Method:** GET  
- **URL:** `{{base_url}}?path=/v1/prices&xml_id=ВАШ_XML_ID`  
- **Headers:** `X-Stock-Import-Key: {{access_key}}`  

---

## 3. GET товар — `/v1/products`

- **Method:** GET  
- **URL:** `{{base_url}}?path=/v1/products&xml_id=ВАШ_XML_ID`  
- **Headers:** `X-Stock-Import-Key: {{access_key}}`  

---

## 4. POST импорт остатков — `/v1/stocks/import`

- **Method:** POST  
- **URL:** `{{base_url}}?path=/v1/stocks/import`  
- **Headers:** `Content-Type: application/json; charset=UTF-8`, `X-Stock-Import-Key: {{access_key}}`  
- **Body (raw JSON), вариант массив:**

```json
[
  {
    "product_xml_id": "GUID-или-внешний-код",
    "amount": 1,
    "store_id": 1
  }
]
```

- **Вариант объект с `items` и ключом в теле:**

```json
{
  "access_key": "{{access_key}}",
  "items": [
    {
      "product_xml_id": "GUID-или-внешний-код",
      "amount": 1,
      "store_id": 1
    }
  ]
}
```

Тот же контракт подходит для `path=/v1/stocks` (POST).

**Что проверить в ответе:**

- `updated` / `failed` отражают итог по всем строкам `items`;
- `errors[]` содержит причины по битым позициям;
- при дубле `XML_ID` строка не импортируется и попадает в `errors[]`.

---

## 5. POST импорт цен — `/v1/prices`

- **Method:** POST  
- **URL:** `{{base_url}}?path=/v1/prices`  
- **Headers:** `Content-Type: application/json; charset=UTF-8`, `X-Stock-Import-Key: {{access_key}}`  
- **Body (raw JSON):**

```json
{
  "items": [
    {
      "product_xml_id": "GUID-товара",
      "catalog_group_id": 1,
      "price": 199.99,
      "currency": "RUB"
    }
  ]
}
```

`catalog_group_id` — ID типа цены в Битрикс (см. настройки каталога).

**Что проверить в ответе:**

- невалидные `catalog_group_id`, `price`, `product_id` не пропадают молча, а отражаются в `failed` / `errors`;
- если `XML_ID` неоднозначен, строка получает ошибку и не записывается.

---

## 6. GET заказы — `/v1/orders`

- **Method:** GET  
- **URL (один заказ):** `{{base_url}}?path=/v1/orders&order_xml_id=ВАШ_XML_ID_ЗАКАЗА`  
- **URL (список):** `{{base_url}}?path=/v1/orders&page=1&items_per_page=20`  
- **Headers:** `X-Stock-Import-Key: {{access_key}}`  

Опционально можно фильтровать по `status_id`, искать по `order_id`, а для списка использовать `order_xml_id_like`.

**Что проверить в ответе:**

- при поиске одного заказа приходит объект `order`;
- для списка приходят `orders[]` и `params.total_items` / `params.total_pages`;
- в заказе есть не только `status_id`, но и `properties`, `items`, `payments`, `shipments`;
- для самовывоза заполняется блок `pickup`.

---

## 7. POST статусы заказов — `/v1/orders/status`

- **Method:** POST  
- **URL:** `{{base_url}}?path=/v1/orders/status`  
- **Headers:** `Content-Type: application/json; charset=UTF-8`, `X-Stock-Import-Key: {{access_key}}`  
- **Body (raw JSON):**

```json
{
  "items": [
    {
      "order_xml_id": "ВАШ_XML_ID_ЗАКАЗА",
      "status_code_1c": "P",
      "event_at": "2026-04-21 10:30:00",
      "comment": "Оплачен в 1С"
    }
  ]
}
```

Опционально можно передавать `order_id` вместо `order_xml_id`, а также `paid`, `allow_delivery`, `deducted`.

**Что проверить в ответе:**

- `updated` / `failed` / `no_change` корректно отражают результат по строкам;
- `results[]` содержит успешные и `no_change`-строки с `order_id` / `order_xml_id`;
- если заказ не найден, ошибка попадает в `errors[]`;
- если статус уже совпадает, строка попадает в `no_change`;
- если `XML_ID` заказа неоднозначен, строка не обновляется;
- если синхронизация оплат/отгрузок выключена, попытка передать `paid` / `allow_delivery` / `deducted` даёт ошибку по строке.

---

## Коды ответов (кратко)

| Код | Типичная причина |
|-----|------------------|
| 200 | Запрос обработан; проверяйте `failed` / `errors`, если в пачке были проблемные строки |
| 400 | Пустое тело, неверный JSON, нет массива `items` / корневого массива |
| 401 | Нет/неверный ключ (GET или POST) |
| 404 | Неизвестный `path` или товар по `xml_id` не найден |
| 413 | Тело или число элементов превышает лимиты модуля |
| 500 | Не подключены `catalog` / `iblock` или внутренняя ошибка |

После импорта в теле ответа может быть поле `api_version`.

---

## Примечание про авторизацию POST

Достаточно **одного** из: заголовок `X-Stock-Import-Key`, поле `access_key` в корне JSON-объекта, либо пара `login` / `password` в корне JSON (как в документации модуля). Корневой JSON-массив без обёртки не может нести `access_key` на верхнем уровне — используйте заголовок или обёртку `{"access_key":"...","items":[...]}`.
