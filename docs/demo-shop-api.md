# Спецификация HTTP API демонстрационного мини-магазина

Настоящий документ описывает структуру, маршруты, модели данных и воспроизводимые сценарии HTTP API демонстрационного мини-магазина.
Документ полностью актуален для этапов **T01–T14** (сессии, витрина каталога, черновик заказа, оформление со снимками цен и outbox, складской учет остатков, асинхронный резерв, сверка и перевод в подтверждённый/отклонённый статус, отмена заказа покупателем, локальный ящик уведомлений и сквозная административная диагностика).

---

## 1. Общие соглашения и форматы

### 1.1. Базовый адрес и версии
Все эндпоинты API имеют префикс `/api/v1`.

### 1.2. Формат данных
* **Заголовки запроса**:
  * `Content-Type: application/json` — обязателен для всех запросов с телом (`POST`, `PUT`, `PATCH`).
  * `Accept: application/json` — формат ответа.
* **Формат ответа**: JSON (`application/json; charset=utf-8`).
* **Временные метки**: строка в формате ISO 8601 (RFC 3339) в часовом поясе UTC (например, `2026-10-19T05:15:30+00:00` или `2026-10-19T05:15:30.123456Z`).
* **Денежные суммы**: целое строго положительное число копеек (`price`, `priceAmount`) вместе с кодом валюты (`RUB`). Пример: `799000` = 7 990,00 ₽.
* **Идентификаторы**:
  * Заказы: `UUIDv4` в каноническом строковом виде в нижнем регистре (например, `d3b07384-d113-48b0-a3b2-9366113b2e5a`).
  * Товары: строковые согласованные идентификаторы (например, `prod-keyboard`, `prod-mouse`, `prod-monitor`).
  * Пользователи: строковые идентификаторы аккаунтов (`buyer1`, `buyer2`, `admin`).
  * Уведомления: `UUIDv4` или `ULID` идентификатор записи уведомления.

### 1.3. Аутентификация и управление сессией
* Аутентификация сессионная на основе cookies Symfony Security (`PHPSESSID`).
* Клиент сохраняет cookie в cookie jar (флаги curl `-c cookies.txt -b cookies.txt`).
* Cookie сессии защищены атрибутами `HttpOnly`, `SameSite=Lax` (и `Secure` при HTTPS).
* Демонстрационные пользователи:
  * `buyer1` / `password` (роль `ROLE_BUYER`);
  * `buyer2` / `password` (роль `ROLE_BUYER`);
  * `admin` / `password` (роль `ROLE_ADMIN`).

### 1.4. CSRF-защита
* Все мутирующие HTTP-запросы (`POST`, `PUT`, `PATCH`, `DELETE`) под `/api/v1` требуют передачи заголовка:
  ```http
  X-CSRF-Token: <токен>
  ```
* Токен получается анонимным либо аутентифицированным запросом `GET /api/v1/auth/session`.
* При успешном входе или выходе идентификатор сессии обновляется, поэтому токен необходимо запросить повторно.
* Отсутствие или недействительность токена приводит к ответу `HTTP 403 Forbidden`.

### 1.5. Идемпотентность
* Заголовок `Idempotency-Key` поддерживается при создании, модификации, оформлении и отмене заказов:
  ```http
  Idempotency-Key: <уникальный_ключ_клиента>
  ```
* Повторный запрос с тем же ключом и тем же телом запроса возвращает сохранённый результат без создания дубликата.
* Повторный запрос с тем же ключом, но с другим телом возвращает `HTTP 409 Conflict` (`idempotency_conflict`).

### 1.6. Асинхронная обработка и паттерн Polling (BR-10, A-02, A-06)
* При сабмите (`POST /api/v1/orders/{id}/submission`) или отмене (`POST /api/v1/orders/{id}/cancellation`) сервер возвращает код `HTTP 202 Accepted` со статусом `pending_stock` или `cancelling`.
* Код `202 Accepted` означает принятие команды в обработку, а не окончательное подтверждение.
* Клиент выполняет опрос состояния заказов через `GET /api/v1/orders/{id}` с ограниченным числом попыток и интервалом ожидания (polling).
* После завершения фоновой обработки резерва и периодической сверки заказ переходит в один из терминальных статусов:
  * `confirmed` — склад подтвердил резерв, сумма и снимки цен зафиксированы, запущен таймер удержания резерва (`expiresAt`);
  * `rejected` — склад отклонил резерв (например, `rejectionReason: "insufficient_stock"`), зафиксирована причина отказа;
  * `cancelled` — заказ отменён покупателем или тайм-аутом, складские остатки освобождены.

### 1.7. Формат ошибок
При ошибках API возвращает JSON с полями `text` и `error`:
```json
{
  "text": "Понятное описание причины ошибки",
  "error": "символьный_код_ошибки"
}
```

Стандартные HTTP-коды:
| HTTP-код | Назначение |
| --- | --- |
| `400 Bad Request` | Некорректный синтаксис JSON. |
| `401 Unauthorized` | Запрос требует аутентификации, сессия отсутствует. |
| `403 Forbidden` | Недостаточно прав роли либо отсутствует/недействителен `X-CSRF-Token`. |
| `404 Not Found` | Товар не найден, либо заказ не существует / принадлежит другому покупателю. |
| `409 Conflict` | Конфликт идемпотентности (`idempotency_conflict`) или недопустимый статус заказа. |
| `415 Unsupported Media Type` | Неверный заголовок `Content-Type` (требуется `application/json`). |
| `422 Unprocessable Entity` | Нарушение валидации полей (отрицательное количество, пустой список и т.д.). |

---

## 2. Справочник маршрутов API

### 2.1. Аутентификация и сессия (`Shared`)

#### `GET /api/v1/auth/session`
Получение состояния текущей сессии и CSRF-токена. Доступен анонимно.

* **Ответ `200 OK` (анонимный)**:
  ```json
  {
    "authenticated": false,
    "user": null,
    "roles": [],
    "csrfToken": "csrf_token_string_..."
  }
  ```
* **Ответ `200 OK` (авторизованный)**:
  ```json
  {
    "authenticated": true,
    "user": "buyer1",
    "roles": ["ROLE_BUYER"],
    "csrfToken": "auth_csrf_token_string_..."
  }
  ```

#### `POST /api/v1/auth/login`
Вход в систему по демонстрационным учётным данным. Требует `X-CSRF-Token`.

* **Тело запроса**:
  ```json
  {
    "username": "buyer1",
    "password": "password"
  }
  ```
* **Ответ `200 OK`**:
  ```json
  {
    "ok": true,
    "user": "buyer1",
    "roles": ["ROLE_BUYER"]
  }
  ```
* **Ошибки**: `401 Unauthorized` при неверных учетных данных, `403 Forbidden` при отсутствии CSRF.

#### `POST /api/v1/auth/logout`
Завершение сессии. Требует `X-CSRF-Token`.

* **Ответ `200 OK`**:
  ```json
  {
    "ok": true
  }
  ```

---

### 2.2. Каталог товаров (`Catalog`)

#### `GET /api/v1/products`
Постраничный список опубликованных товаров на витрине. Доступен анонимно.

* **Query-параметры**:
  * `page` (int, по умолчанию `1`, min: 1).
  * `limit` (int, по умолчанию `10`, min: 1, max: 50).
* **Ответ `200 OK`**:
  ```json
  {
    "items": [
      {
        "id": "prod-keyboard",
        "name": "Механическая клавиатура",
        "price": 799000,
        "currency": "RUB",
        "published": true
      },
      {
        "id": "prod-mouse",
        "name": "Беспроводная мышь",
        "price": 349000,
        "currency": "RUB",
        "published": true
      }
    ],
    "total": 3,
    "page": 1,
    "limit": 10
  }
  ```
* **Ошибки**: `422 Unprocessable Entity` при некорректных `page`/`limit`.

#### `GET /api/v1/products/{id}`
Получение карточки опубликованного товара. Доступен анонимно.

* **Ответ `200 OK`**:
  ```json
  {
    "id": "prod-keyboard",
    "name": "Механическая клавиатура",
    "price": 799000,
    "currency": "RUB",
    "published": true
  }
  ```
* **Ошибки**: `404 Not Found` (`product_not_found`), если товар не найден или снят с публикации.

#### `PATCH /api/v1/admin/products/{id}`
Обновление цены и/или признака публикации товара. Требует `ROLE_ADMIN` и `X-CSRF-Token`.

* **Тело запроса**:
  ```json
  {
    "price": 849000,
    "published": true
  }
  ```
* **Ответ `200 OK`**:
  ```json
  {
    "id": "prod-keyboard",
    "name": "Механическая клавиатура",
    "price": 849000,
    "currency": "RUB",
    "published": true
  }
  ```
* **Ошибки**: `401 Unauthorized`, `403 Forbidden` для покупателя, `404 Not Found` если товар не найден, `422 Unprocessable Entity` при `price <= 0`.

---

### 2.3. Заказы покупателей (`Ordering`)

#### `POST /api/v1/orders`
Создание нового либо получение существующего активного черновика заказа текущего покупателя.
Требует `ROLE_BUYER`, `X-CSRF-Token`. Поддерживает `Idempotency-Key`.

* **Ответ `200 OK`** (или `201 Created` при создании):
  ```json
  {
    "id": "8f2da67c-9ef4-4f8e-b83e-976451e22839",
    "buyerId": "buyer1",
    "status": "draft",
    "items": [],
    "createdAt": "2026-10-19T05:20:00+00:00",
    "updatedAt": "2026-10-19T05:20:00+00:00"
  }
  ```
* **Инвариант BR-16**: у покупателя не может быть более одного активного черновика (`draft`). Если черновик уже существует, эндпоинт возвращает его.

#### `PUT /api/v1/orders/{id}/items`
Полная замена позиций в черновике заказа. Требует `ROLE_BUYER` и `X-CSRF-Token`.

* **Тело запроса**:
  ```json
  {
    "items": [
      {
        "productId": "prod-keyboard",
        "quantity": 1
      },
      {
        "productId": "prod-mouse",
        "quantity": 2
      }
    ]
  }
  ```
* **Ответ `200 OK`**:
  ```json
  {
    "id": "8f2da67c-9ef4-4f8e-b83e-976451e22839",
    "buyerId": "buyer1",
    "status": "draft",
    "items": [
      {
        "productId": "prod-keyboard",
        "quantity": 1
      },
      {
        "productId": "prod-mouse",
        "quantity": 2
      }
    ],
    "createdAt": "2026-10-19T05:20:00+00:00",
    "updatedAt": "2026-10-19T05:21:00+00:00"
  }
  ```
* **Ошибки**:
  * `404 Not Found` (`order_not_found`) — заказ не найден или принадлежит другому покупателю (BR-09, A-11);
  * `409 Conflict` (`order_not_in_draft_state`) — заказ уже переведен в оформление и не может быть изменен;
  * `422 Unprocessable Entity` (`invalid_order_item_quantity`) — количество позиции `<= 0`;
  * `422 Unprocessable Entity` (`empty_order_items`) — передан пустой список позиций `[]`.

#### `POST /api/v1/orders/{id}/submission`
Перевод черновика заказа в оформление (`pending_stock`).
Фиксирует неизменяемые снимки цен и наименований товаров из `Catalog`, рассчитывает сумму заказа (`totalAmount`), сохраняет транзакционное исходящее событие `OrderSubmittedEvent` в outbox.
Требует `ROLE_BUYER`, `X-CSRF-Token`. Поддерживает `Idempotency-Key`.

* **Тело запроса**: пустое или `{}`
* **Ответ `202 Accepted`**:
  ```json
  {
    "id": "8f2da67c-9ef4-4f8e-b83e-976451e22839",
    "buyerId": "buyer1",
    "status": "pending_stock",
    "items": [
      {
        "productId": "prod-keyboard",
        "quantity": 1,
        "productName": "Механическая клавиатура",
        "priceAmount": 799000,
        "currency": "RUB"
      },
      {
        "productId": "prod-mouse",
        "quantity": 2,
        "productName": "Беспроводная мышь",
        "priceAmount": 349000,
        "currency": "RUB"
      }
    ],
    "totalAmount": 1497000,
    "currency": "RUB",
    "createdAt": "2026-10-19T05:20:00+00:00",
    "updatedAt": "2026-10-19T05:22:00+00:00"
  }
  ```
* **Инварианты**:
  * **BR-03, BR-15, A-04 (Неизменяемость снимков)**: цены и наименования фиксируются в момент сабмита. Последующие изменения цен в каталоге не меняют сумму заказа.
  * **BR-16 (Освобождение слота черновика)**: при переходе в `pending_stock` покупатель может начать новый черновик через `POST /api/v1/orders`.
  * **Идемпотентность**: повторный запрос с тем же `Idempotency-Key` и тем же телом возвращает сохраненный ответ `202 Accepted` без повторной публикации событий.
* **Ошибки**:
  * `404 Not Found` (`order_not_found`) — чужой или несуществующий заказ;
  * `409 Conflict` (`order_not_in_draft_state`) — заказ уже был переведен из `draft`;
  * `422 Unprocessable Entity` (`empty_order_items`) — в заказе отсутствуют позиции;
  * `422 Unprocessable Entity` (`product_not_available`) — товар отсутствует в каталоге или снят с публикации.

#### `POST /api/v1/orders/{id}/cancellation`
Запрос на отмену заказа покупателем. Переводит заказ в статус `cancelling`, сохраняет исходящее событие `OrderCancelledEvent` в outbox для асинхронного освобождения остатков на складе.
Требует `ROLE_BUYER`, `X-CSRF-Token`. Поддерживает `Idempotency-Key`.

* **Тело запроса**:
  ```json
  {
    "reason": "user_request"
  }
  ```
  *(поле `reason` опционально)*
* **Ответ `202 Accepted`**:
  ```json
  {
    "id": "8f2da67c-9ef4-4f8e-b83e-976451e22839",
    "buyerId": "buyer1",
    "status": "cancelling",
    "items": [
      {
        "productId": "prod-keyboard",
        "quantity": 1,
        "productName": "Механическая клавиатура",
        "priceAmount": 799000,
        "currency": "RUB"
      }
    ],
    "totalAmount": 799000,
    "currency": "RUB",
    "createdAt": "2026-10-19T05:20:00+00:00",
    "updatedAt": "2026-10-19T05:25:00+00:00"
  }
  ```
* **Инварианты**:
  * Заказ может быть отменен из статуса `pending_stock` или `confirmed`.
  * Повторный запрос с тем же `Idempotency-Key` возвращает сохранённый `202 Accepted`.
  * После освобождения резерва складом сверка переводит заказ в статус `cancelled`.
* **Ошибки**:
  * `404 Not Found` (`order_not_found`) — чужой заказ;
  * `409 Conflict` (`invalid_order_status_for_cancellation`) — попытка отмены черновика `draft` или уже закрытого заказа (`cancelled`, `rejected`).

#### `GET /api/v1/orders/{id}`
Детальная информация о заказе текущего покупателя. Используется для опроса конечного статуса (polling) после `202 Accepted`. Требует `ROLE_BUYER`.

* **Ответ `200 OK` (подтверждённый заказ `confirmed`)**:
  ```json
  {
    "id": "8f2da67c-9ef4-4f8e-b83e-976451e22839",
    "buyerId": "buyer1",
    "status": "confirmed",
    "items": [
      {
        "productId": "prod-keyboard",
        "quantity": 1,
        "productName": "Механическая клавиатура",
        "priceAmount": 799000,
        "currency": "RUB"
      },
      {
        "productId": "prod-mouse",
        "quantity": 2,
        "productName": "Беспроводная мышь",
        "priceAmount": 349000,
        "currency": "RUB"
      }
    ],
    "totalAmount": 1497000,
    "currency": "RUB",
    "rejectionReason": null,
    "createdAt": "2026-10-19T05:20:00+00:00",
    "updatedAt": "2026-10-19T05:22:10+00:00"
  }
  ```
* **Ответ `200 OK` (отклоненный заказ `rejected`)**:
  ```json
  {
    "id": "8f2da67c-9ef4-4f8e-b83e-976451e22839",
    "buyerId": "buyer1",
    "status": "rejected",
    "items": [
      {
        "productId": "prod-monitor",
        "quantity": 10,
        "productName": "Игровой монитор 27\"",
        "priceAmount": 2499000,
        "currency": "RUB"
      }
    ],
    "totalAmount": 24990000,
    "currency": "RUB",
    "rejectionReason": "insufficient_stock",
    "createdAt": "2026-10-19T05:20:00+00:00",
    "updatedAt": "2026-10-19T05:22:10+00:00"
  }
  ```
* **Ответ `200 OK` (отмененный заказ `cancelled`)**:
  ```json
  {
    "id": "8f2da67c-9ef4-4f8e-b83e-976451e22839",
    "buyerId": "buyer1",
    "status": "cancelled",
    "items": [
      {
        "productId": "prod-keyboard",
        "quantity": 1,
        "productName": "Механическая клавиатура",
        "priceAmount": 799000,
        "currency": "RUB"
      }
    ],
    "totalAmount": 799000,
    "currency": "RUB",
    "rejectionReason": null,
    "createdAt": "2026-10-19T05:20:00+00:00",
    "updatedAt": "2026-10-19T05:26:00+00:00"
  }
  ```
* **Ошибки**: `404 Not Found` (`order_not_found`) при запросе чужого заказа (BR-09, A-11).

#### `GET /api/v1/orders`
Постраничный список заказов текущего авторизованного покупателя. Требует `ROLE_BUYER`.

* **Query-параметры**:
  * `page` (int, по умолчанию `1`, min: 1).
  * `limit` (int, по умолчанию `10`, min: 1, max: 50).
* **Ответ `200 OK`**:
  ```json
  {
    "items": [
      {
        "id": "8f2da67c-9ef4-4f8e-b83e-976451e22839",
        "buyerId": "buyer1",
        "status": "confirmed",
        "items": [
          {
            "productId": "prod-keyboard",
            "quantity": 1,
            "productName": "Механическая клавиатура",
            "priceAmount": 799000,
            "currency": "RUB"
          }
        ],
        "totalAmount": 799000,
        "currency": "RUB",
        "rejectionReason": null,
        "createdAt": "2026-10-19T05:20:00+00:00",
        "updatedAt": "2026-10-19T05:22:10+00:00"
      }
    ],
    "total": 1,
    "page": 1,
    "limit": 10
  }
  ```

---

### 2.4. Складские остатки (`Inventory`)

#### `PATCH /api/v1/admin/stock/{productId}`
Обновление физического складского остатка (`onHand`) для товара. Требует `ROLE_ADMIN` и `X-CSRF-Token`.

* **Параметры пути**:
  * `productId` (string, обязателен) — идентификатор товара (`prod-keyboard`, `prod-mouse`, `prod-monitor`).
* **Тело запроса**:
  ```json
  {
    "onHand": 50
  }
  ```
* **Ответ `200 OK`**:
  ```json
  {
    "productId": "prod-keyboard",
    "onHand": 50,
    "reserved": 0,
    "available": 50,
    "updatedAt": "2026-10-19T10:30:00+00:00"
  }
  ```
* **Инварианты BR-06, A-12**:
  * Физический остаток не может быть отрицательным (`onHand >= 0`).
  * Физический остаток не может быть опущен ниже зарезервированного количества (`onHand >= reserved`).
  * Доступный остаток вычисляется динамически: `available = max(0, onHand - reserved)`.
* **Ошибки**:
  * `401 Unauthorized` — запрос без авторизации;
  * `403 Forbidden` — запрос от лица покупателя (`ROLE_BUYER`) либо недействительный/отсутствующий `X-CSRF-Token`;
  * `404 Not Found` (`stock_position_not_found`) — складская позиция товара не найдена;
  * `422 Unprocessable Entity` (`stock_on_hand_below_reserved`) — попытка снизить `onHand` ниже текущего `reserved`;
  * `422 Unprocessable Entity` — нарушение валидации тела (отрицательный `onHand` или `null`).

---

### 2.5. Уведомления (`Notifications`)

#### `GET /api/v1/admin/notifications`
Просмотр журнала локального ящика уведомлений покупателей и администратора. Требует `ROLE_ADMIN`.

* **Query-параметры**:
  * `page` (int, по умолчанию `1`, min: 1).
  * `limit` (int, по умолчанию `10`, min: 1, max: 50).
* **Ответ `200 OK`**:
  ```json
  {
    "items": [
      {
        "id": "0192a5b2-7c89-7000-8000-000000000001",
        "orderId": "8f2da67c-9ef4-4f8e-b83e-976451e22839",
        "type": "order_confirmed",
        "buyerId": "buyer1",
        "status": "sent",
        "attempts": 1,
        "payload": {
          "totalAmount": 1497000,
          "currency": "RUB"
        },
        "errorMessage": null,
        "createdAt": "2026-10-19T05:22:10+00:00",
        "updatedAt": "2026-10-19T05:22:11+00:00",
        "sentAt": "2026-10-19T05:22:11+00:00"
      }
    ],
    "total": 1,
    "page": 1,
    "limit": 10
  }
  ```
* **Ошибки**: `401 Unauthorized`, `403 Forbidden` для покупателя (`ROLE_BUYER`).

---

### 2.6. Административная сквозная диагностика заказа (`Ordering`)

#### `GET /api/v1/admin/diagnostics/orders/{id}`
Получение полного сквозного снимка состояния заказа, складской операции и истории уведомлений по идентификатору заказа.
Объединяет данные `Ordering`, синхронно опрашивая `Inventory` через `InventoryContractInterface` и `Notifications` через `NotificationsContractInterface`.
Требует `ROLE_ADMIN`.

* **Параметры пути**:
  * `id` (UUIDv4) — идентификатор заказа.
* **Ответ `200 OK`**:
  ```json
  {
    "order": {
      "id": "8f2da67c-9ef4-4f8e-b83e-976451e22839",
      "buyerId": "buyer1",
      "status": "confirmed",
      "items": [
        {
          "productId": "prod-keyboard",
          "quantity": 1,
          "productName": "Механическая клавиатура",
          "priceAmount": 799000
        },
        {
          "productId": "prod-mouse",
          "quantity": 2,
          "productName": "Беспроводная мышь",
          "priceAmount": 349000
        }
      ],
      "totalAmount": 1497000,
      "currency": "RUB",
      "rejectionReason": null,
      "createdAt": "2026-10-19T05:20:00.000000Z",
      "updatedAt": "2026-10-19T05:22:10.000000Z"
    },
    "inventory": {
      "status": "reserved",
      "rejectionReason": null
    },
    "notifications": [
      {
        "id": "0192a5b2-7c89-7000-8000-000000000001",
        "type": "order_confirmed",
        "status": "sent",
        "attempts": 1,
        "errorMessage": null,
        "createdAt": "2026-10-19T05:22:10+00:00",
        "updatedAt": "2026-10-19T05:22:11+00:00",
        "sentAt": "2026-10-19T05:22:11+00:00"
      }
    ]
  }
  ```
* **Ошибки**:
  * `401 Unauthorized` — отсутствие авторизации;
  * `403 Forbidden` — обращение от лица покупателя (`ROLE_BUYER`);
  * `404 Not Found` (`order_not_found`) — заказ с указанным ID не найден.

---

## 3. Сквозные пошаговые сценарии (CLI / curl)

Для воспроизведения сценариев требуются `curl` и утилита `jq`.

```bash
BASE_URL="http://localhost:80"
COOKIE_BUYER1="/tmp/buyer1_cookies.txt"
COOKIE_BUYER2="/tmp/buyer2_cookies.txt"
COOKIE_ADMIN="/tmp/admin_cookies.txt"
rm -f "$COOKIE_BUYER1" "$COOKIE_BUYER2" "$COOKIE_ADMIN"
```

---

### Сценарий 1. Полный цикл успешной покупки («покупка»)

Сценарий демонстрирует путь:
**Просмотр витрины → Вход покупателя → Создание черновика → Замена позиций → Оформление заказа (202) → Опрос статуса до confirmed → Административная проверка диагностики и ящика уведомлений**.

#### 1.1. Анонимный просмотр витрины
```bash
curl -s -X GET "$BASE_URL/api/v1/products?page=1&limit=10" | jq .
```
*Ожидаемый ответ*: HTTP 200, список опубликованных товаров (`prod-keyboard`, `prod-mouse`, `prod-monitor`).

#### 1.2. Получение сессии и CSRF-токена
```bash
CSRF_TOKEN=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  "$BASE_URL/api/v1/auth/session" | jq -r .csrfToken)
```

#### 1.3. Вход покупателя buyer1
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $CSRF_TOKEN" \
  -d '{"username": "buyer1", "password": "password"}' | jq .
```
*Ожидаемый ответ*: HTTP 200, `{"ok": true, "user": "buyer1", "roles": ["ROLE_BUYER"]}`.

#### 1.4. Обновление токена после входа
```bash
AUTH_CSRF=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  "$BASE_URL/api/v1/auth/session" | jq -r .csrfToken)
```

#### 1.5. Создание активного черновика заказа
```bash
ORDER_ID=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X POST "$BASE_URL/api/v1/orders" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $AUTH_CSRF" | jq -r .id)

echo "Создан черновик: $ORDER_ID"
```
*Ожидаемый ответ*: HTTP 200/201, `status: "draft"`, `items: []`.

#### 1.6. Наполнение корзины товарами
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X PUT "$BASE_URL/api/v1/orders/$ORDER_ID/items" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $AUTH_CSRF" \
  -d '{
    "items": [
      {"productId": "prod-keyboard", "quantity": 1},
      {"productId": "prod-mouse", "quantity": 2}
    ]
  }' | jq .
```
*Ожидаемый ответ*: HTTP 200, статус `draft`, в `items` 2 позиции.

#### 1.7. Оформление заказа (Submission)
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X POST "$BASE_URL/api/v1/orders/$ORDER_ID/submission" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $AUTH_CSRF" \
  -H "Idempotency-Key: submit-purchase-1" | jq .
```
*Ожидаемый ответ*: **HTTP 202 Accepted**, `status: "pending_stock"`, `totalAmount: 1497000`, `currency: "RUB"`, зафиксированы снимки цен и наименований.

#### 1.8. Опрос состояния заказа (Polling)
Клиент выполняет опрос `GET /api/v1/orders/{id}` с ограниченным интервалом:
```bash
# До выполнения фоновой сверки
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  "$BASE_URL/api/v1/orders/$ORDER_ID" | jq .status
# Результат: "pending_stock" (промежуточное состояние)

# После фоновой обработки очереди и периодической сверки (Scheduler)
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  "$BASE_URL/api/v1/orders/$ORDER_ID" | jq .
```
*Ожидаемый итоговый ответ*: **HTTP 200 OK**, `status: "confirmed"`, `totalAmount: 1497000`, `rejectionReason: null`.

#### 1.9. Проверка через административную диагностику
```bash
# Получение сессии админа
ADMIN_CSRF=$(curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
  "$BASE_URL/api/v1/auth/session" | jq -r .csrfToken)

# Вход администратора
curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
  -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $ADMIN_CSRF" \
  -d '{"username": "admin", "password": "password"}' > /dev/null

# Сквозная диагностика заказа
curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
  "$BASE_URL/api/v1/admin/diagnostics/orders/$ORDER_ID" | jq .
```
*Ожидаемый ответ*:
```json
{
  "order": {
    "id": "...",
    "status": "confirmed",
    "totalAmount": 1497000,
    "rejectionReason": null
  },
  "inventory": {
    "status": "reserved",
    "rejectionReason": null
  },
  "notifications": [
    {
      "type": "order_confirmed",
      "status": "sent",
      "attempts": 1
    }
  ]
}
```

---

### Сценарий 2. Нехватка товара на складе («нехватка» — бизнес-отказ)

Сценарий демонстрирует путь:
**Заказ количества, превышающего складской остаток → Принятие в обработку (202) → Отказ склада в резерве → Сверка заказа в rejected с причиной → Опрос клиентом и диагностика**.

#### 2.1. Создание черновика с превышением остатка
```bash
REJECT_ORDER_ID=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X POST "$BASE_URL/api/v1/orders" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $AUTH_CSRF" | jq -r .id)

# Запрос 100 мониторов при физическом наличии 5
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X PUT "$BASE_URL/api/v1/orders/$REJECT_ORDER_ID/items" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $AUTH_CSRF" \
  -d '{
    "items": [
      {"productId": "prod-monitor", "quantity": 100}
    ]
  }' > /dev/null
```

#### 2.2. Сабмит заказа
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X POST "$BASE_URL/api/v1/orders/$REJECT_ORDER_ID/submission" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $AUTH_CSRF" | jq .status
```
*Ожидаемый ответ*: **HTTP 202 Accepted**, `status: "pending_stock"`.

#### 2.3. Опрос после фоновой обработки отказа склада
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  "$BASE_URL/api/v1/orders/$REJECT_ORDER_ID" | jq .
```
*Ожидаемый ответ*: **HTTP 200 OK**, `status: "rejected"`, `rejectionReason: "insufficient_stock"`.

#### 2.4. Административная диагностика бизнес-отказа
```bash
curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
  "$BASE_URL/api/v1/admin/diagnostics/orders/$REJECT_ORDER_ID" | jq .
```
*Ожидаемый ответ*:
```json
{
  "order": {
    "status": "rejected",
    "rejectionReason": "insufficient_stock"
  },
  "inventory": {
    "status": "rejected",
    "rejectionReason": "insufficient_stock"
  },
  "notifications": [
    {
      "type": "order_rejected",
      "status": "sent"
    }
  ]
}
```

---

### Сценарий 3. Отмена заказа покупателем («отмена»)

Сценарий демонстрирует путь:
**Подтверждённый заказ → Запрос отмены (202 cancelling) → Асинхронное освобождение остатков на складе → Сверка заказа в cancelled → Подтверждение в диагностике**.

#### 3.1. Запрос отмены подтвержденного заказа
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X POST "$BASE_URL/api/v1/orders/$ORDER_ID/cancellation" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $AUTH_CSRF" \
  -H "Idempotency-Key: cancel-key-1" \
  -d '{"reason": "user_cancelled"}' | jq .
```
*Ожидаемый ответ*: **HTTP 202 Accepted**, `status: "cancelling"`.

#### 3.2. Опрос статуса после фонового освобождения остатков
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  "$BASE_URL/api/v1/orders/$ORDER_ID" | jq .
```
*Ожидаемый ответ*: **HTTP 200 OK**, `status: "cancelled"`.

#### 3.3. Проверка через административную диагностику
```bash
curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
  "$BASE_URL/api/v1/admin/diagnostics/orders/$ORDER_ID" | jq .
```
*Ожидаемый ответ*:
```json
{
  "order": {
    "status": "cancelled"
  },
  "inventory": {
    "status": "released"
  },
  "notifications": [
    {
      "type": "order_cancelled",
      "status": "sent"
    }
  ]
}
```

---

### Сценарий 4. Административные операции и управление остатками

#### 4.1. Изменение цены товара в каталоге
```bash
ADMIN_AUTH_CSRF=$(curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
  "$BASE_URL/api/v1/auth/session" | jq -r .csrfToken)

curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
  -X PATCH "$BASE_URL/api/v1/admin/products/prod-keyboard" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $ADMIN_AUTH_CSRF" \
  -d '{"price": 899000, "published": true}' | jq .
```
*Ожидаемый ответ*: HTTP 200, `price: 899000`.

#### 4.2. Корректировка физических остатков склада
```bash
curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
  -X PATCH "$BASE_URL/api/v1/admin/stock/prod-keyboard" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $ADMIN_AUTH_CSRF" \
  -d '{"onHand": 50}' | jq .
```
*Ожидаемый ответ*: HTTP 200, `onHand: 50`, `available: 50`.

#### 4.3. Просмотр ящика уведомлений
```bash
curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
  -X GET "$BASE_URL/api/v1/admin/notifications?page=1&limit=5" | jq .
```
*Ожидаемый ответ*: HTTP 200, массив отправленных уведомлений `items`, метаданные `total`, `page`, `limit`.

---

### Сценарий 5. Безопасность, изоляция данных и идемпотентность

#### 5.1. Защита от неавторизованного доступа (401)
```bash
curl -s -i -X GET "$BASE_URL/api/v1/orders" | grep "HTTP/"
# Результат: HTTP/1.1 401 Unauthorized
```

#### 5.2. Разграничение ролей (403)
Покупатель пытается вызвать административный эндпоинт:
```bash
curl -s -i -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X GET "$BASE_URL/api/v1/admin/notifications" | grep "HTTP/"
# Результат: HTTP/1.1 403 Forbidden
```

#### 5.3. Защита от CSRF-атак (403)
Мутирующий запрос без передачи заголовка `X-CSRF-Token`:
```bash
curl -s -i -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X POST "$BASE_URL/api/v1/orders" \
  -H "Content-Type: application/json" | grep "HTTP/"
# Результат: HTTP/1.1 403 Forbidden
```

#### 5.4. Изоляция данных покупателей (404 для чужого заказа)
Второй покупатель (`buyer2`) пытается прочесть заказ первого покупателя (`$ORDER_ID`):
```bash
# Получение сессии buyer2
CSRF_BUYER2=$(curl -s -c "$COOKIE_BUYER2" -b "$COOKIE_BUYER2" \
  "$BASE_URL/api/v1/auth/session" | jq -r .csrfToken)

# Вход buyer2
curl -s -c "$COOKIE_BUYER2" -b "$COOKIE_BUYER2" \
  -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $CSRF_BUYER2" \
  -d '{"username": "buyer2", "password": "password"}' > /dev/null

# Попытка доступа к заказу buyer1
curl -s -i -c "$COOKIE_BUYER2" -b "$COOKIE_BUYER2" \
  -X GET "$BASE_URL/api/v1/orders/$ORDER_ID" | grep "HTTP/"
# Результат: HTTP/1.1 404 Not Found (order_not_found)
```

#### 5.5. Идемпотентность и разрешение конфликтов (202 / 409)
* Повтор запроса с тем же `Idempotency-Key` и тем же телом: возвращает идентичный ответ **HTTP 202 Accepted** без дублирования эффектов.
* Повтор запроса с тем же `Idempotency-Key`, но с измененным телом: возвращает **HTTP 409 Conflict** (`idempotency_conflict`).

---

## 4. Состояние реализации API

Все запланированные маршруты, инварианты и интеграции этапов **T01–T14** полностью реализованы:

1. **T01–T03**: Сессионная аутентификация, CSRF-защита, каталог товаров витрины, черновик заказа и изоляция между покупателями.
2. **T04**: Первый сквозной сценарий покупки и спецификация API.
3. **T05–T06**: Оформление заказа со снимками цен и наименований, transactional outbox, физический учет остатков в `Inventory`.
4. **T07–T08**: Атомарный складской резерв, независимый idempotent consumer в `Inventory`, защита от повторных и задержанных сообщений.
5. **T09**: Периодическая сверка (`ReconcileStockCommand` / Scheduler), опрос статуса склада через контракт, перевод заказа в `confirmed` либо `rejected` (`insufficient_stock`).
6. **T10**: Отмена заказа покупателем (`POST /orders/{id}/cancellation`), асинхронное освобождение складских остатков и финализация отмены в `cancelled`.
7. **T11–T12**: Локальный ящик уведомлений (`Notifications`), подписка на события заказа, административный просмотр журнала уведомлений (`GET /admin/notifications`).
8. **T13**: Сквозная административная диагностика заказа (`GET /admin/diagnostics/orders/{id}`), объединяющая статус заказа, результат склада и историю уведомлений.
9. **T14**: Полные сквозные функциональные HTTP-тесты (`Tests\Functional\CompleteScenariosTest`) и завершение документации API.
