# Спецификация HTTP API демонстрационного мини-магазина

Настоящий документ описывает структуру, маршруты, модели данных и воспроизводимые сценарии HTTP API демонстрационного мини-магазина.
Документ актуален для этапа **T04** (задачи T01–T03: сессии, витрина каталога и черновик заказа).

---

## 1. Общие соглашения и форматы

### 1.1. Базовый адрес и версии
Все эндпоинты API имеют префикс `/api/v1`.

### 1.2. Формат данных
* **Заголовки запроса**:
  * `Content-Type: application/json` — обязателен для всех запросов с телом (`POST`, `PUT`, `PATCH`).
  * `Accept: application/json` — формат ответа.
* **Формат ответа**: JSON (`application/json; charset=utf-8`).
* **Временные метки**: строка в формате ISO 8601 (RFC 3339) в часовом поясе UTC (например, `2026-10-19T05:15:30+00:00`).
* **Денежные суммы**: целое строго положительное число копеек (`price`, `priceAmount`) вместе с кодом валюты (`RUB`). Пример: `799000` = 7 990,00 ₽.
* **Идентификаторы**:
  * Заказы: `UUIDv4` в каноническом строковом виде в нижнем регистре (например, `d3b07384-d113-48b0-a3b2-9366113b2e5a`).
  * Товары: строковые согласованные идентификаторы (например, `prod-keyboard`, `prod-mouse`).
  * Пользователи: строковые идентификаторы аккаунтов (например, `buyer1`, `buyer2`, `admin`).

### 1.3. Аутентификация и управление сессией
* Аутентификация сессионная на основе cookies Symfony Security (`PHPSESSID`).
* Клиент сохраняет cookie в cookie jar (например, флаги curl `-c cookies.txt -b cookies.txt`).
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
* Заголовок `Idempotency-Key` поддерживается при создании и модификации заказов:
  ```http
  Idempotency-Key: <уникальный_ключ_клиента>
  ```
* Повторный запрос с тем же ключом и тем же телом запроса возвращает сохранённый результат без создания дубликата.
* Повторный запрос с тем же ключом, но с другим телом возвращает `HTTP 409 Conflict` (`idempotency_conflict`).

### 1.6. Формат ошибок
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
| `403 Forbidden` | Недостаточно прав роли либо отсутствует/неверен `X-CSRF-Token`. |
| `404 Not Found` | Товар не найден, либо заказ не существует / принадлежит другому покупателю. |
| `409 Conflict` | Конфликт идемпотентности или попытка изменения заказа не в статусе `draft`. |
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
    "roles": [
      "ROLE_BUYER"
    ],
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
    "authenticated": true,
    "user": "buyer1",
    "roles": [
      "ROLE_BUYER"
    ]
  }
  ```
* **Ошибки**: `401 Unauthorized` при неверных учетных данных, `403 Forbidden` при отсутствии CSRF.

#### `POST /api/v1/auth/logout`
Завершение сессии. Требует `X-CSRF-Token`.

* **Ответ `200 OK`**:
  ```json
  {
    "authenticated": false
  }
  ```

---

### 2.2. Каталог товаров (`Catalog`)

#### `GET /api/v1/products`
Постраничный список опубликованных товаров на витрине. Доступен анонимно.

* **Query-параметры**:
  * `page` (int, опционально, по умолчанию `1`, min: 1).
  * `limit` (int, опционально, по умолчанию `10`, min: 1, max: 50).
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
* **Ошибки**: `422 Unprocessable Entity` при `page < 1` или `limit > 50`.

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
* **Ошибки**: `404 Not Found` (`product_not_found`), если товар не существует или снят с публикации.

#### `PATCH /api/v1/admin/products/{id}`
Обновление цены и/или признака публикации товара. Требует `ROLE_ADMIN` и `X-CSRF-Token`.

* **Тело запроса**:
  ```json
  {
    "price": 849000,
    "published": true
  }
  ```
  *(поля опциональны: отсутствующее поле сохраняет текущее значение)*
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
* **Ошибки**: `403 Forbidden` для покупателя, `404 Not Found` если товар не найден, `422 Unprocessable Entity` при `price <= 0`.

---

### 2.3. Корзина и черновик заказа (`Ordering`)

#### `POST /api/v1/orders`
Создание нового либо получение существующего активного черновика заказа текущего покупателя.
Требует `ROLE_BUYER`, `X-CSRF-Token`. Поддерживает `Idempotency-Key`.

* **Заголовки**:
  * `X-CSRF-Token: <токен>`
  * `Idempotency-Key: <опциональный_ключ>`
* **Ответ `200 OK`** (или `201 Created` при первом создании):
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
* **Инвариант BR-16**: у покупателя не может быть более одного черновика (`draft`). Если черновик уже существует, эндпоинт возвращает его.

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
  * `404 Not Found` (`order_not_found`) — заказ не найден или принадлежит другому пользователю.
  * `409 Conflict` (`order_not_in_draft_state`) — заказ уже переведен в оформление и не может быть изменен.
  * `422 Unprocessable Entity` (`invalid_order_item_quantity`) — количество позиции `<= 0`.
  * `422 Unprocessable Entity` (`empty_order_items`) — передан пустой список позиций `[]`.

#### `GET /api/v1/orders/{id}`
Детальная информация о заказе текущего покупателя. Требует `ROLE_BUYER`.

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
        "status": "draft",
        "items": [
          {
            "productId": "prod-keyboard",
            "quantity": 1
          }
        ],
        "createdAt": "2026-10-19T05:20:00+00:00",
        "updatedAt": "2026-10-19T05:21:00+00:00"
      }
    ],
    "total": 1,
    "page": 1,
    "limit": 10
  }
  ```

---

## 3. Сквозной пошаговый сценарий (CLI / curl)

Сценарий демонстрирует полный путь:
**Анонимная витрина → Получение сессии → Логин покупателя → Создание черновика → Наполнение корзины → Чтение заказа → Проверка изоляции под вторым покупателем**.

Для работы требуется установленный `curl` и утилита `jq`.

```bash
BASE_URL="http://localhost:80"
COOKIE_BUYER1="/tmp/buyer1_cookies.txt"
COOKIE_BUYER2="/tmp/buyer2_cookies.txt"
rm -f "$COOKIE_BUYER1" "$COOKIE_BUYER2"
```

### Шаг 1. Анонимный просмотр витрины
```bash
curl -s -X GET "$BASE_URL/api/v1/products?page=1&limit=10" | jq .
```
*Ожидаемый ответ*: HTTP 200, список товаров (`prod-keyboard`, `prod-mouse`, `prod-monitor`), скрытые товары отсутствуют.

---

### Шаг 2. Инициализация сессии и получение CSRF-токена
```bash
CSRF_TOKEN=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  "$BASE_URL/api/v1/auth/session" | jq -r .csrfToken)

echo "CSRF Token: $CSRF_TOKEN"
```
*Ожидаемый ответ*: HTTP 200, `authenticated: false`, непустой `csrfToken`.

---

### Шаг 3. Вход покупателя (buyer1)
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X POST "$BASE_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $CSRF_TOKEN" \
  -d '{"username": "buyer1", "password": "password"}' | jq .
```
*Ожидаемый ответ*: HTTP 200, `authenticated: true`, `user: "buyer1"`.

---

### Шаг 4. Обновление CSRF-токена после логина
```bash
AUTH_CSRF_TOKEN=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  "$BASE_URL/api/v1/auth/session" | jq -r .csrfToken)
```

---

### Шаг 5. Создание черновика заказа
```bash
ORDER_RESPONSE=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X POST "$BASE_URL/api/v1/orders" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $AUTH_CSRF_TOKEN" \
  -H "Idempotency-Key: draft-step-key-1")

echo "$ORDER_RESPONSE" | jq .
ORDER_ID=$(echo "$ORDER_RESPONSE" | jq -r .id)
echo "Создан черновик заказа: $ORDER_ID"
```
*Ожидаемый ответ*: HTTP 200/201, статус `draft`, `items: []`.

---

### Шаг 6. Наполнение корзины товарами
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X PUT "$BASE_URL/api/v1/orders/$ORDER_ID/items" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $AUTH_CSRF_TOKEN" \
  -d '{
    "items": [
      {"productId": "prod-keyboard", "quantity": 1},
      {"productId": "prod-mouse", "quantity": 2}
    ]
  }' | jq .
```
*Ожидаемый ответ*: HTTP 200, статус `draft`, в `items` 2 позиции.

---

### Шаг 7. Просмотр своего черновика
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  "$BASE_URL/api/v1/orders/$ORDER_ID" | jq .
```
*Ожидаемый ответ*: HTTP 200, актуальный состав позиций заказа.

---

### Шаг 8. Проверка изоляции данных вторым покупателем (buyer2)
Второй покупатель входит в отдельную сессию:
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

# Попытка чтения заказа buyer1
curl -s -c "$COOKIE_BUYER2" -b "$COOKIE_BUYER2" \
  -i "$BASE_URL/api/v1/orders/$ORDER_ID"
```
*Ожидаемый ответ*: **HTTP 404 Not Found** (`order_not_found`). Данные покупателя `buyer1` надёжно изолированы.

---

### Шаг 9. Выход из системы
```bash
curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
  -X POST "$BASE_URL/api/v1/auth/logout" \
  -H "X-CSRF-Token: $AUTH_CSRF_TOKEN" | jq .
```
*Ожидаемый ответ*: HTTP 200, `authenticated: false`.

---

## 4. Карта развития API в последующих задачах

В следующих задачах декомпозиции в данный документ будут добавлены:
* **T05**: `POST /api/v1/orders/{id}/submission` (HTTP 202, перевод в `pending_stock`, расчет суммы в копейках и outbox-сообщение).
* **T06–T08**: `PATCH /api/v1/admin/stock/{productId}` (управление складскими остатками `Inventory`).
* **T09–T10**: `POST /api/v1/orders/{id}/cancellation` (отмена заказа и освобождение остатков), отображение причины отказа склада `rejected`.
* **T11–T12**: `GET /api/v1/admin/notifications` (постраничный просмотр локального ящика уведомлений).
* **T13**: `GET /api/v1/admin/diagnostics/orders/{id}` (объединенная картина переходов заказа, склада и уведомлений).
* **T14**: Полное обновление документации со всеми завершенными сценариями.

