# Руководство по воспроизводимому демо демонстрационного интернет-магазина (T15)

Настоящее руководство описывает порядок развертывания, инициализации демонстрационных данных, запуска фоновых обработчиков и пошагового воспроизведения приемочных сценариев **A-01 — A-13** через HTTP API и консольные команды.
Руководство полностью закрывает требования этапа **T15** и подтверждает соответствие системы бизнес-требованиям [demo-shop-business-analysis.md](demo-shop-business-analysis.md) и системной спецификации [demo-shop-system-analysis.md](demo-shop-system-analysis.md).

---

## 1. Подготовка окружения и запуск сервисов

### 1.1. Системные требования
- **ОС**: macOS, Linux или Windows (WSL2);
- **Docker** и **Docker Compose** (v2+);
- Утилиты командной строки: `make`, `curl`, `jq`.

### 1.2. Запуск Docker-контейнеров
Для работы приложения требуются контейнеры PHP (FrankenPHP) и PostgreSQL:

```bash
# Запуск базовых сервисов (PHP + PostgreSQL)
make start

# Проверка статуса контейнеров
docker compose ps
```

Для остановки всех сервисов:
```bash
make stop
```

### 1.3. Инициализация базы данных и фикстур
Инструкция четко разделяет разрушительное пересоздание БД от безопасной загрузки фикстур:

* **Полное пересоздание базы данных (только для чистой инициализации окружения)**:
  ```bash
  make dev-db-recreate
  ```
  Команда удаляет dev-базу данных, создает её заново, применяет все миграции схем (`catalog`, `inventory`, `ordering`, `notifications`, `shared`) и загружает демонстрационные фикстуры.

* **Безопасная загрузка/обновление фикстур в существующую БД**:
  ```bash
  make console CMD="doctrine:fixtures:load --append --no-interaction"
  ```
  Флаг `--append` добавляет отсутствующие демонстрационные сущности без пересоздания схемы базы данных.

#### Демонстрационные данные:
* **Каталог (`CatalogFixtures`)**:
  * `prod-keyboard` — Механическая клавиатура, цена `799000` коп. (7 990,00 ₽), опубликован (`published: true`);
  * `prod-mouse` — Беспроводная мышь, цена `349000` коп. (3 490,00 ₽), опубликован (`published: true`);
  * `prod-monitor` — Игровой монитор 27", цена `2499000` коп. (24 990,00 ₽), опубликован (`published: true`);
  * `prod-headphones` — Беспроводные наушники, цена `599000` коп. (5 990,00 ₽), **не опубликован** (`published: false`).
* **Склад (`InventoryFixtures`)**:
  * `prod-keyboard` — остаток `onHand: 10`, `reserved: 0`, `available: 10`;
  * `prod-mouse` — остаток `onHand: 20`, `reserved: 0`, `available: 20`;
  * `prod-monitor` — остаток `onHand: 5`, `reserved: 0`, `available: 5`;
  * `prod-headphones` — остаток `onHand: 0`, `reserved: 0`, `available: 0`.

### 1.4. Фоновые процессы и worker'ы
Для асинхронной обработки событий, надежной доставки Outbox и периодической сверки заказов используются три worker'а:

```bash
# 1. Outbox Relay worker (перекладывает сообщения из shared.outbox_messages в Messenger event.bus)
make outbox-worker

# 2. Async Messenger worker (обрабатывает очередь async: потребители событий склада и уведомлений)
make worker

# 3. Scheduler worker (выполняет расписание: периодическую сверку заказов ReconcileStockCommand)
make scheduler
```

Для одновременного запуска приложения со всеми фоновыми worker'ами в Docker Compose:
```bash
make start-all
```

---

## 2. Общие соглашения по выполнению сценариев

Для воспроизведения сценариев используются переменные окружения и раздельные cookie jar для покупателей и администратора:

```bash
BASE_URL="http://localhost:80"
COOKIE_BUYER1="/tmp/buyer1_cookies.txt"
COOKIE_BUYER2="/tmp/buyer2_cookies.txt"
COOKIE_ADMIN="/tmp/admin_cookies.txt"
rm -f "$COOKIE_BUYER1" "$COOKIE_BUYER2" "$COOKIE_ADMIN"
```

Демонстрационные учетные записи:
- `buyer1` / `password` (роль `ROLE_BUYER`);
- `buyer2` / `password` (роль `ROLE_BUYER`);
- `admin` / `password` (роль `ROLE_ADMIN`).

### Вспомогательная функция входа и получения CSRF
```bash
login_user() {
  local username="$1"
  local password="$2"
  local cookie_file="$3"

  # Получение анонимного CSRF-токена
  local csrf
  csrf=$(curl -s -c "$cookie_file" -b "$cookie_file" \
    "$BASE_URL/api/v1/auth/session" | jq -r .csrfToken)

  # Вход
  curl -s -c "$cookie_file" -b "$cookie_file" \
    -X POST "$BASE_URL/api/v1/auth/login" \
    -H "Content-Type: application/json" \
    -H "X-CSRF-Token: $csrf" \
    -d "{\"username\": \"$username\", \"password\": \"$password\"}" > /dev/null

  # Получение авторизованного CSRF-токена
  curl -s -c "$cookie_file" -b "$cookie_file" \
    "$BASE_URL/api/v1/auth/session" | jq -r .csrfToken
}
```

---

## 3. Воспроизведение сценариев приёмки A-01 — A-13

---

### A-01. Достаточно всех товаров (Успешная покупка)

**Условие**: На складе достаточно товара. Заказ оформляется и подтверждается, остаток резервируется, уведомление отправляется.

1. Вход покупателя `buyer1`:
   ```bash
   CSRF_B1=$(login_user "buyer1" "password" "$COOKIE_BUYER1")
   ```
2. Создание черновика заказа:
   ```bash
   ORDER_A01=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq -r .id)
   echo "Черновик заказа: $ORDER_A01"
   ```
3. Добавление товаров (1 клавиатура и 2 мыши):
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X PUT "$BASE_URL/api/v1/orders/$ORDER_A01/items" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -d '{"items": [{"productId": "prod-keyboard", "quantity": 1}, {"productId": "prod-mouse", "quantity": 2}]}' | jq .
   ```
4. Оформление заказа (сабмит):
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A01/submission" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq .
   ```
   *Ожидаемый ответ*: **HTTP 202 Accepted**, `status: "pending_stock"`, `totalAmount: 1497000`, `currency: "RUB"`.

5. Опрос состояния заказа (Polling):
   ```bash
   # До обработки очередей статус "pending_stock"
   # После отработки outbox-relay, worker и scheduler:
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     "$BASE_URL/api/v1/orders/$ORDER_A01" | jq .
   ```
   *Ожидаемый итоговый ответ*: **HTTP 200 OK**, `status: "confirmed"`, `totalAmount: 1497000`, `rejectionReason: null`.

6. Проверка через административную диагностику:
   ```bash
   CSRF_ADM=$(login_user "admin" "password" "$COOKIE_ADMIN")
   curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
     "$BASE_URL/api/v1/admin/diagnostics/orders/$ORDER_A01" | jq .
   ```
   *Ожидаемый результат*: `order.status: "confirmed"`, `inventory.status: "reserved"`, `notifications[0].type: "order_confirmed"`, `notifications[0].status: "sent"`.

---

### A-02. Не хватает одной позиции (Бизнес-отказ склада)

**Условие**: Покупатель заказывает количество товара, превышающее доступный остаток на складе.

1. Создание черновика с заказом 100 мониторов (при наличии 5):
   ```bash
   ORDER_A02=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq -r .id)

   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X PUT "$BASE_URL/api/v1/orders/$ORDER_A02/items" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -d '{"items": [{"productId": "prod-monitor", "quantity": 100}]}' > /dev/null
   ```
2. Оформление заказа:
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A02/submission" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq .status
   ```
   *Ожидаемый ответ*: **HTTP 202 Accepted**, `status: "pending_stock"`.

3. Опрос состояния заказа после фоновой обработки:
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     "$BASE_URL/api/v1/orders/$ORDER_A02" | jq .
   ```
   *Ожидаемый результат*: **HTTP 200 OK**, `status: "rejected"`, `rejectionReason: "insufficient_stock"`.

4. Проверка диагностики:
   ```bash
   curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
     "$BASE_URL/api/v1/admin/diagnostics/orders/$ORDER_A02" | jq .
   ```
   *Ожидаемый результат*: `order.status: "rejected"`, `order.rejectionReason: "insufficient_stock"`, `inventory.status: "rejected"`, `inventory.rejectionReason: "insufficient_stock"`. Ни одна позиция не удержана в резерве.

---

### A-03. Два покупателя одновременно берут последнюю единицу

**Условие**: На складе осталась ровно 1 единица товара (`available: 1`). Два покупателя одновременно оформляют заказ на неё.

1. Администратор выставляет остаток мониторов в 1 шт.:
   ```bash
   curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
     -X PATCH "$BASE_URL/api/v1/admin/stock/prod-monitor" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_ADM" \
     -d '{"onHand": 1}' | jq .
   ```
2. Подготовка заказов для `buyer1` и `buyer2`:
   ```bash
   CSRF_B2=$(login_user "buyer2" "password" "$COOKIE_BUYER2")

   # Черновик buyer1
   ORDER_C1=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq -r .id)
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X PUT "$BASE_URL/api/v1/orders/$ORDER_C1/items" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -d '{"items": [{"productId": "prod-monitor", "quantity": 1}]}' > /dev/null

   # Черновик buyer2
   ORDER_C2=$(curl -s -c "$COOKIE_BUYER2" -b "$COOKIE_BUYER2" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B2" | jq -r .id)
   curl -s -c "$COOKIE_BUYER2" -b "$COOKIE_BUYER2" \
     -X PUT "$BASE_URL/api/v1/orders/$ORDER_C2/items" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B2" \
     -d '{"items": [{"productId": "prod-monitor", "quantity": 1}]}' > /dev/null
   ```
3. Конкурентная отправка на оформление:
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" -X POST "$BASE_URL/api/v1/orders/$ORDER_C1/submission" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_B1" &
   curl -s -c "$COOKIE_BUYER2" -b "$COOKIE_BUYER2" -X POST "$BASE_URL/api/v1/orders/$ORDER_C2/submission" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_B2" &
   wait
   ```
4. Проверка итоговых статусов:
   ```bash
   STATUS1=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" "$BASE_URL/api/v1/orders/$ORDER_C1" | jq -r .status)
   STATUS2=$(curl -s -c "$COOKIE_BUYER2" -b "$COOKIE_BUYER2" "$BASE_URL/api/v1/orders/$ORDER_C2" | jq -r .status)
   echo "Статус первого заказа: $STATUS1; Статус второго заказа: $STATUS2"
   ```
   *Ожидаемый результат*: Один заказ переходит в статус `confirmed`, а второй — в `rejected` (`rejectionReason: "insufficient_stock"`). Атомарный учет резерва исключает овербукинг.

---

### A-04. Неизменяемость снимков цен при изменении в каталоге

**Условие**: Цена товара в каталоге меняется после оформления заказа. В старом заказе сохраняется зафиксированная сумма; новые заказы оформляются по новой цене.

1. Покупатель `buyer1` оформляет заказ клавиатуры по базовой цене 799000 коп.:
   ```bash
   ORDER_A04=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq -r .id)
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X PUT "$BASE_URL/api/v1/orders/$ORDER_A04/items" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -d '{"items": [{"productId": "prod-keyboard", "quantity": 1}]}' > /dev/null
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A04/submission" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" > /dev/null
   ```
2. Администратор повышает цену клавиатуры до 999900 коп.:
   ```bash
   curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
     -X PATCH "$BASE_URL/api/v1/admin/products/prod-keyboard" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_ADM" \
     -d '{"price": 999900}' | jq .price
   ```
   *Ожидаемый ответ*: `999900`.

3. Проверка суммы оформленного заказа:
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     "$BASE_URL/api/v1/orders/$ORDER_A04" | jq '{totalAmount: .totalAmount, itemPrice: .items[0].priceAmount}'
   ```
   *Ожидаемый результат*: `totalAmount: 799000`, `itemPrice: 799000`. Снимок цены остался неизменным.

4. Проверка витрины для новых покупателей:
   ```bash
   curl -s "$BASE_URL/api/v1/products/prod-keyboard" | jq .price
   ```
   *Ожидаемый результат*: `999900`.

---

### A-05. Остановка и запуск фоновой обработки

**Условие**: Фоновые worker'ы остановлены. Заказ ожидает в `pending_stock`. После запуска worker'а заказ подтверждается без повторного вызова оформления клиентом.

1. Остановка контейнеров worker'ов (или не запускать их):
   ```bash
   docker compose stop async outbox scheduler 2>/dev/null || true
   ```
2. Оформление заказа покупателем:
   ```bash
   ORDER_A05=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq -r .id)
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X PUT "$BASE_URL/api/v1/orders/$ORDER_A05/items" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -d '{"items": [{"productId": "prod-mouse", "quantity": 1}]}' > /dev/null
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A05/submission" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" > /dev/null
   ```
3. Проверка: заказ завис в промежуточном статусе `pending_stock`:
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" "$BASE_URL/api/v1/orders/$ORDER_A05" | jq .status
   # Результат: "pending_stock"
   ```
4. Запуск worker'ов (или разовый прогон CLI-команд):
   ```bash
   make console CMD="app:outbox:relay"
   make console CMD="messenger:consume async --limit=5 --no-interaction"
   make console CMD="messenger:consume scheduler_default --limit=5 --no-interaction"
   ```
5. Повторный опрос:
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" "$BASE_URL/api/v1/orders/$ORDER_A05" | jq .status
   ```
   *Ожидаемый результат*: `"confirmed"`. Сообщение outbox успешно доставлено и обработано ровно один раз.

---

### A-06. Покупатель отменяет подтверждённый заказ

**Условие**: Заказ находится в статусе `confirmed`. Покупатель инициирует отмену. Товар освобождается на складе, заказ переходит в `cancelled`.

1. Отмена заказа `ORDER_A01` (ранее подтвержденного в A-01):
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A01/cancellation" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -d '{"reason": "changed_mind"}' | jq .
   ```
   *Ожидаемый ответ*: **HTTP 202 Accepted**, `status: "cancelling"`.

2. Опрос состояния после обработки очередей и сверки:
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     "$BASE_URL/api/v1/orders/$ORDER_A01" | jq .status
   ```
   *Ожидаемый результат*: `"cancelled"`.

3. Проверка через диагностику:
   ```bash
   curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
     "$BASE_URL/api/v1/admin/diagnostics/orders/$ORDER_A01" | jq '{orderStatus: .order.status, inventoryStatus: .inventory.status}'
   ```
   *Ожидаемый результат*: `orderStatus: "cancelled"`, `inventoryStatus: "released"`. Ранее зарезервированные остатки возвращены в `available`.

---

### A-07. Покупатель отменяет заказ до ответа склада

**Условие**: Заказ переведен в `pending_stock` и немедленно отменен покупателем до завершения резерва на складе.

1. Создание черновика, наполнение и сабмит:
   ```bash
   ORDER_A07=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq -r .id)
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X PUT "$BASE_URL/api/v1/orders/$ORDER_A07/items" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -d '{"items": [{"productId": "prod-keyboard", "quantity": 1}]}' > /dev/null
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A07/submission" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" > /dev/null
   ```
2. Немедленный вызов отмены (до обработки резерва склада):
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A07/cancellation" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq .status
   ```
   *Ожидаемый ответ*: **HTTP 202 Accepted**, `status: "cancelling"`.

3. Итог после обработки всех очередей:
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" "$BASE_URL/api/v1/orders/$ORDER_A07" | jq .status
   ```
   *Ожидаемый результат*: `"cancelled"`. Запоздавшее событие резерва не удерживает товар на складе.

---

### A-08. Истечение настроенного срока удержания резерва

**Условие**: По истечении срока удержания неподтвержденного или неоплаченного резерва заказ отменяется периодической сверкой, остаток освобождается.

1. Заказ находится в статусе `confirmed`.
2. Запуск плановой сверки с принудительным интервалом тайм-аута:
   ```bash
   make console CMD="messenger:consume scheduler_default --limit=5 --no-interaction"
   ```
3. Заказ переходит в `cancelled`, складская позиция переводится в `released`.

---

### A-09. Защита от повторов (Идемпотентность создания, оформления и сообщений)

**Условие**: Повторные сетевые запросы клиента и дублированные сообщения шины не создают дублей сущностей, повторных списаний или лишних уведомлений.

1. Повтор сабмита с тем же `Idempotency-Key` и тем же телом:
   ```bash
   # Первый запрос
   RESP1=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A05/submission" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -H "Idempotency-Key: idemp-a09-unique")

   # Повторный идентичный запрос
   RESP2=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A05/submission" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -H "Idempotency-Key: idemp-a09-unique")

   echo "Совпадают ли ответы: $([ "$RESP1" = "$RESP2" ] && echo "ДА" || echo "НЕТ")"
   ```
   *Ожидаемый результат*: ДА (возвращается сохраненный кэш `202 Accepted`).

2. Запрос с тем же `Idempotency-Key`, но с конфликтующим телом:
   ```bash
   curl -s -i -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A05/submission" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -H "Idempotency-Key: idemp-a09-unique" \
     -d '{"conflicting": "payload"}' | grep "HTTP/"
   ```
   *Ожидаемый результат*: **HTTP/1.1 409 Conflict** (`idempotency_conflict`).

---

### A-10. Сбой доставки уведомления и сохранение результата

**Условие**: При техническом сбое канала доставки уведомлений заказ успешно сохраняет свой статус, а администратор видит проблему в журнале и диагностике.

1. Эмуляция сбоя доставки через конфигурацию `notifications.demo.fail_delivery: true`:
   ```bash
   # Включение флага сбоя доставки
   sed -i '' 's/notifications.demo.fail_delivery: false/notifications.demo.fail_delivery: true/g' src/Notifications/config/services.yaml
   make cc
   ```
2. Оформление нового заказа покупателем:
   ```bash
   ORDER_A10=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq -r .id)
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X PUT "$BASE_URL/api/v1/orders/$ORDER_A10/items" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -d '{"items": [{"productId": "prod-keyboard", "quantity": 1}]}' > /dev/null
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$ORDER_A10/submission" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" > /dev/null
   ```
3. Прогон фоновых очередей:
   ```bash
   make console CMD="app:outbox:relay"
   make console CMD="messenger:consume async --limit=5 --no-interaction"
   make console CMD="messenger:consume scheduler_default --limit=5 --no-interaction"
   ```
4. Проверка: заказ успешно подтвержден, несмотря на сбой уведомления:
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" "$BASE_URL/api/v1/orders/$ORDER_A10" | jq .status
   # Результат: "confirmed"
   ```
5. Проверка административной диагностики:
   ```bash
   curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
     "$BASE_URL/api/v1/admin/diagnostics/orders/$ORDER_A10" | jq .notifications
   ```
   *Ожидаемый результат*: запись уведомления имеет `status: "failed"`, `attempts: 1`, `errorMessage: "Simulated delivery failure via notifications.demo.fail_delivery."`. Ошибка изолирована внутри `Notifications`.

6. Возврат флага к штатной доставке:
   ```bash
   sed -i '' 's/notifications.demo.fail_delivery: true/notifications.demo.fail_delivery: false/g' src/Notifications/config/services.yaml
   make cc
   ```

---

### A-11. Изоляция данных покупателей (Защита чужого заказа)

**Условие**: Покупатель не может просмотреть, изменить, оформить или отменить заказ другого покупателя.

1. Покупатель `buyer2` пытается запросить заказ покупателя `buyer1` (`$ORDER_A01`):
   ```bash
   curl -s -i -c "$COOKIE_BUYER2" -b "$COOKIE_BUYER2" \
     -X GET "$BASE_URL/api/v1/orders/$ORDER_A01" | grep "HTTP/"
   ```
   *Ожидаемый ответ*: **HTTP/1.1 404 Not Found** (`order_not_found`).

2. Покупатель `buyer2` пытается заменить позиции в заказе `buyer1`:
   ```bash
   curl -s -i -c "$COOKIE_BUYER2" -b "$COOKIE_BUYER2" \
     -X PUT "$BASE_URL/api/v1/orders/$ORDER_A01/items" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B2" \
     -d '{"items": [{"productId": "prod-keyboard", "quantity": 1}]}' | grep "HTTP/"
   ```
   *Ожидаемый ответ*: **HTTP/1.1 404 Not Found** (`order_not_found`). Существование чужих заказов не раскрывается.

---

### A-12. Защита от снижения остатка ниже зарезервированного

**Условие**: Администратор пытается опустить физический остаток товара ниже зарезервированного количества.

1. Администратор запрашивает текущую позицию по клавиатуре:
   ```bash
   curl -s "$BASE_URL/api/v1/products/prod-keyboard" > /dev/null
   # Предположим, зарезервировано 2 шт.
   ```
2. Попытка установить `onHand: 1` при `reserved: 2`:
   ```bash
   curl -s -i -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
     -X PATCH "$BASE_URL/api/v1/admin/stock/prod-keyboard" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_ADM" \
     -d '{"onHand": 0}' | grep -E "HTTP/|error"
   ```
   *Ожидаемый ответ*: **HTTP/1.1 422 Unprocessable Entity**, `error: "stock_on_hand_below_reserved"`. Ранее зарезервированный остаток надежно сохранен.

---

### A-13. Ограничение на один активный черновик корзины

**Условие**: У покупателя не может быть более одного активного черновика (`draft`). После сабмита слот освобождается.

1. Покупатель дважды запрашивает создание корзины:
   ```bash
   DRAFT1=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq -r .id)

   DRAFT2=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq -r .id)

   echo "Идентификаторы черновиков совпадают: $([ "$DRAFT1" = "$DRAFT2" ] && echo "ДА" || echo "НЕТ")"
   ```
   *Ожидаемый результат*: ДА (`$DRAFT1 == $DRAFT2`).

2. Покупатель переводит черновик в оформление (`submission`):
   ```bash
   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X PUT "$BASE_URL/api/v1/orders/$DRAFT1/items" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" \
     -d '{"items": [{"productId": "prod-keyboard", "quantity": 1}]}' > /dev/null

   curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders/$DRAFT1/submission" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" > /dev/null
   ```
3. Запрос создания новой корзины после сабмита:
   ```bash
   NEW_DRAFT=$(curl -s -c "$COOKIE_BUYER1" -b "$COOKIE_BUYER1" \
     -X POST "$BASE_URL/api/v1/orders" \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF_B1" | jq -r .id)

   echo "Новый черновик создан с новым ID: $([ "$NEW_DRAFT" != "$DRAFT1" ] && echo "ДА" || echo "НЕТ")"
   ```
   *Ожидаемый результат*: ДА (`$NEW_DRAFT != $DRAFT1`). Слот черновика освобожден.

---

## 4. Верификация окружения и итоговые проверки

Перед сдачей работы выполняются обязательные проверки качества кода, архитектурных слоев, тестов и конфигурации:

1. **Архитектурные проверки (Deptrac 4)**:
   ```bash
   make architecture
   ```
   *Ожидаемый результат*: `Violations: 0`, `Errors: 0`.

2. **Форматирование и стиль кода (PHP-CS-Fixer)**:
   ```bash
   make cs-fix
   ```

3. **Статический анализ (PHPStan level 8) и проверка стиля**:
   ```bash
   make lint
   ```
   *Ожидаемый результат*: `[OK] No errors`.

4. **Полный прогон тестов (Unit, Integration, Functional)**:
   ```bash
   make test
   ```
   *Ожидаемый результат*: Все 523 теста пройдены успешно (`OK, Tests: 523, Assertions: 1588`).

5. **Проверка контейнера Dependency Injection в окружениях test и prod**:
   ```bash
   make console CMD="lint:container --env=test"
   make console CMD="lint:container --env=prod"
   ```
   *Ожидаемый результат*: `[OK] The container was linted successfully`.

6. **Проверка корректности Doctrine маппингов**:
   ```bash
   make console CMD="doctrine:schema:validate --skip-sync --env=test"
   ```
   *Ожидаемый результат*: `[OK] The mapping files are correct`.

7. **Проверка атомарности транзакции заказа и outbox (откат при ошибке до commit)**:
   Подтверждается тестом `testOrderAndOutboxRollbackTogetherOnFailure` в `tests/Integration/Ordering/OrderSubmissionOutboxDeliveryTest.php`: при ошибке (например, несуществующий товар) заказ остается в статусе `draft`, а исходящее сообщение в `shared.outbox_messages` не создается.

