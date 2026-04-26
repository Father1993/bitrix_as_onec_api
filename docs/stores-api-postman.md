# Склады: `GET/POST /v1/stores` (Postman, 1С-интеграция)

Те же `{{base_url}}` и `X-Stock-Import-Key`, что в [postman-testing.md](postman-testing.md). Наследует лимиты тела/числа строк из [StockImportOptions](../lib/StockImportOptions.php) (как импорт цен).

---

## GET `/v1/stores` — список или один склад

**Headers:** `X-Stock-Import-Key: {{access_key}}` (или `access_key` в query).

| Сценарий | Пример query |
|----------|----------------|
| Список активных складов (до 500; при ровно 500 смотрите `truncated`) | `path=/v1/stores` |
| Включая неактивные | `path=/v1/stores&include_inactive=1` |
| Один по ID | `path=/v1/stores&store_id=1` |
| Один по внешнему коду | `path=/v1/stores&store_xml_id=UUID` или `path=/v1/stores&code=SIMVOL` |

**Ответ 200:** `ok`, `stores[]` (объекты с `store_id`, `title`, `active`, `address`, `store_xml_id`, `store_code`, `sort`, флаги `issuing_center` / `shipping_center` и т.д.).

**404** — нет подходящей записи. **409** — при поиске по `store_xml_id` / `code` найдено больше одной строки (неоднозначно).

**Удаление** через API не предусмотрено.

---

## POST `/v1/stores` — пакетное создание / обновление

**Headers:** `Content-Type: application/json`, `X-Stock-Import-Key: {{access_key}}`.

**Тело:** объект с `items: [ {...}, ... ]` **или** корневой JSON-массив (тогда ключ только в заголовке).

**Логика строки (upsert):**

1. `store_id` > 0 — обновить существующий склад с этим ID.
2. Иначе ищем по `store_xml_id` / `xml_id` / `XML_ID`, затем по `store_code` / `code` — если одна запись, **обновление**.
3. Если не найдено, но внешний код передан — **создание** (обязательны осмысленные `title` / `address` или подставляются значения по умолчанию на стороне модуля: см. README).

**Передаваемые поля (по согласованию с админом; неизвестные ключи игнорируются):**  
`title`, `address`, `description`, `active`, `store_xml_id`, `code`, `sort`, `site_id`, `phone`, `email`, `schedule`, `issuing_center`, `shipping_center`, `gps_n`, `gps_s`, `location_id`, `image_id`.

**Каждая строка** должна позволить однозначно решить «кого менять/создавать»:

- либо `store_id` для правки;
- либо для поиска/создания — `store_xml_id` и/или `code` (как в импорте остатков).

**Ответ 200:** как у остальных импортов: `ok`, `total`, `updated`, `failed`, `errors[]`, при переполнении — `errors_truncated`.

**Пример (обновить по ID и создать по внешнему коду):**

```json
{
  "items": [
    {
      "store_id": 1,
      "title": "Основной — переименован",
      "active": true
    },
    {
      "store_xml_id": "11111111-1111-1111-1111-111111111111",
      "title": "Новый склад",
      "address": "г. Минск",
      "code": "MINSK_MAIN"
    }
  ]
}
```

---

## См. также

- Остатки и привязка `store_id` / `store_xml_id` в строках импорта: [1c-ostatki-api-rukovodstvo-postman.md](1c-ostatki-api-rukovodstvo-postman.md).  
- Общая шпаргалка эндпоинтов: [postman-testing.md](postman-testing.md).
