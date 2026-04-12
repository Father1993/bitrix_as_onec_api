# Postman: проверка JSON API `as.onec_api`

Краткая шпаргалка для ручной проверки эндпоинтов [`JsonApiKernel`](../lib/http/jsonapikernel.php). Полное описание контрактов и curl — в [`ADEV/stocks-import-from-1c-testing.md`](../../../ADEV/stocks-import-from-1c-testing.md).

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

---

## Коды ответов (кратко)

| Код | Типичная причина |
|-----|------------------|
| 200 | Успех |
| 400 | Пустое тело, неверный JSON, нет обязательных полей |
| 401 | Нет/неверный ключ (GET или POST) |
| 404 | Неизвестный `path` или товар по `xml_id` не найден |
| 413 | Тело или число элементов превышает лимиты модуля |
| 500 | Не подключены `catalog` / `iblock` или внутренняя ошибка |

После импорта в теле ответа может быть поле `api_version`.

---

## Примечание про авторизацию POST

Достаточно **одного** из: заголовок `X-Stock-Import-Key`, поле `access_key` в корне JSON-объекта, либо пара `login` / `password` в корне JSON (как в документации модуля). Корневой JSON-массив без обёртки не может нести `access_key` на верхнем уровне — используйте заголовок или обёртку `{"access_key":"...","items":[...]}`.
