# Инструкция модуля Inventory

## Назначение и язык

Контекст `Inventory` владеет:
- Учётом складских остатков товаров (`onHand`);
- Учётом зарезервированного количества товаров (`reserved`);
- Расчётом доступного для заказа остатка (`available = max(0, onHand - reserved)`);
- Атомарным резервированием всех позиций заказа (`Reserve`) и освобождением резерва (`Release`);
- Защитной записью отмены при получении `Release` до `Reserve`;
- Администраторским изменением физических остатков (`PATCH /api/v1/admin/stock/{productId}`);
- Демо-фикстурами складских остатков для товаров.

Контекст `Inventory` НЕ владеет:
- Карточкой товара, наименованиями, ценами и публикацией на витрине (владеет `Catalog`);
- Корзинами покупателей, созданием и переходами статусов заказов (владеет `Ordering`);
- Оплатой и доставкой.

Термины:
- `StockPosition` — складская позиция конкретного товара;
- `onHand` — физический остаток товара на складе (фактическое наличие);
- `reserved` — зарезервированное количество товара под заказы;
- `available` — доступное для оформления количество (`max(0, onHand - reserved)`);
- `Reservation` — результат операции резервирования по заказу (`orderId`, `status: pending|reserved|rejected|released`, позиции `items`, `rejectionReason`).

## Модель и инварианты

Агрегат `StockPosition` (`productId: ProductId`, `onHand: int`, `reserved: int`, `createdAt: DateTimeImmutable`, `updatedAt: DateTimeImmutable`):
- `ProductId` — `final readonly` value object в `Domain/ValueObject/ProductId`, оборачивает непустую строку, реализует `equals(self $other): bool`. Использование скалярной строки обосновано едиными строковыми идентификаторами товаров (`prod-keyboard`, `prod-mouse` и др.) без межмодульных внешних ключей между таблицами каталога, склада и заказов.
- Инвариант неотрицательности остатков (BR-06, A-12): `onHand >= 0` и `reserved >= 0`. Попытка задать отрицательное значение отклоняется `InvalidArgumentException`.
- Инвариант баланса (BR-06, A-12): физический остаток никогда не может опускаться ниже зарезервированного количества (`onHand >= reserved`). Попытка уменьшить `onHand` ниже `reserved` вызывает доменное исключение `StockOnHandBelowReservedException`.
- Инвариант доступности: `available = max(0, onHand - reserved)`. Доступное количество никогда не бывает отрицательным.
- Операции `reserve(int $quantity)` и `release(int $quantity)` изменяют `reserved` с контролем инвариантов.
- Защита на уровне PostgreSQL: таблица `inventory.stock_positions` снабжена CHECK-ограничениями:
  - `CONSTRAINT check_stock_on_hand_gte_zero CHECK (on_hand >= 0)`
  - `CONSTRAINT check_stock_reserved_gte_zero CHECK (reserved >= 0)`
  - `CONSTRAINT check_stock_on_hand_gte_reserved CHECK (on_hand >= reserved)`

Агрегат `Reservation` (`orderId: OrderId`, `status: ReservationStatus`, `items: ReservationItemCollection`, `rejectionReason: ?ReservationRejectionReason`, `createdAt`, `updatedAt`):
- `OrderId` — `final readonly` value object в `Domain/ValueObject/OrderId`, наследует `App\Shared\Domain\ValueObject\UuidId`.
- Инвариант атомарности (BR-05, A-02): операция резервирует либо все позиции заказа, либо ни одной. Частичное резервирование запрещено.
- Инвариант порядка блокировок (BR-06, BR-07, deadlock-free): позиции блокируются пессимистически (`LockMode::PESSIMISTIC_WRITE`) строго по возрастанию `productId`.
- Инвариант идемпотентности (BR-14, A-09): повтор `Reserve` с тем же составом возвращает прежний результат; повтор с другим составом вызывает `ReservationConflictException`.
- Инвариант защитной отмены (BR-10, BR-11, A-07): `Release`, пришедший до `Reserve`, создаёт запись `released` по `orderId`. Поздний `Reserve` видит `released` и не удерживает товар.

Доменные Action:
- `ChangeStockOnHandAction` (`Domain/Action/ChangeStockOnHandAction`): загружает позицию через `StockPositionDataProviderInterface::findByProductId` с пессимистической блокировкой на запись (`lock: true`), изменяет `onHand` через `StockPosition::changeOnHand($newOnHand)`, сохраняет через `StockPositionRepositoryInterface::save($position)`.
- `ReserveStockAction` (`Domain/Action/ReserveStockAction`): проверяет существующую резервацию, блокирует позиции по возрастанию `productId`, проверяет доступность всех позиций, атомарно резервирует остаток (`RESERVED`) либо фиксирует отказ (`REJECTED`).
- `ReleaseStockAction` (`Domain/Action/ReleaseStockAction`): идемпотентно освобождает зарезервированные позиции или создаёт защитную запись `RELEASED` при отсутствии резервации.

Application сценарии:
- В Application бизнес-модуля разрешены только `Command/` и `Query/`:
  - `ChangeStockOnHandCommand` / `ChangeStockOnHandHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): оркестратор изменения остатка.
  - `ReserveStockCommand` / `ReserveStockHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): оркестратор резервирования остатков по заказу.
  - `ReleaseStockCommand` / `ReleaseStockHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): оркестратор освобождения остатков по заказу.
  - `GetStockPositionQuery` / `GetStockPositionHandler` (`#[AsMessageHandler(bus: 'query.bus')]`): запрашивает позицию и преобразует её в модель `GetStockPositionQueryResult`.
  - `GetReservationQuery` / `GetReservationHandler` (`#[AsMessageHandler(bus: 'query.bus')]`): запрашивает результат резервации по заказу и преобразует его в `GetReservationQueryResult`.
- Каталоги `Application/Message`, `Application/EventListener` и `Application/Service` запрещены.

Сообщения Inbox (`Infrastructure/Messaging/Inbox/<Operation>/`):
- `ReserveStock`: `ReserveStockInboxMessage` + `ReserveStockInboxItem` + `ReserveStockInboxHandler` (`#[AsMessageHandler(bus: 'inbox.bus')]`): транслирует входящее сообщение в `ReserveStockCommand` на `command.bus` в отдельной транзакции воркера.
- `ReleaseStock`: `ReleaseStockInboxMessage` + `ReleaseStockInboxHandler` (`#[AsMessageHandler(bus: 'inbox.bus')]`): транслирует входящее сообщение в `ReleaseStockCommand` на `command.bus` в отдельной транзакции воркера.

## Публичный API и зависимости

- Модуль зависит от `Ordering` (объявлен в `config/dependencies.php`).
- Адаптер `OrderSubmittedEventListener` в `Infrastructure/Integration/Ordering/OrderSubmittedEventListener.php` помечен `#[AsEventListener(event: OrderSubmittedEvent::class)]` и транслирует публичное событие оформления заказа владельца `Ordering` во внутреннее сообщение `ReserveStockInboxMessage` шины `inbox.bus` без синхронных изменений в таблицах склада.
- Экспортируемый контракт `InventoryContractInterface` (`Presentation/Contracts/InventoryContractInterface.php`):
  - `requestReservation(ReservationRequest $request): void` — долговечно принимает запрос в очередь `inbox.bus`, возвращает void без блокировок и синхронной модификации остатков;
  - `requestRelease(ReleaseRequest $request): void` — принимает запрос освобождения в очередь `inbox.bus`;
  - `getReservationResult(string $orderId): ReservationResult` — запрашивает результат через `query.bus`, возвращает статус (`not_found`, `pending`, `reserved`, `rejected`, `released`), причину отказа и список позиций.
- Адаптер контракта: `InventoryContractAdapter` в `Presentation/Adapter/InventoryContractAdapter.php` с `#[AsAlias]`.
- Конфигурация DI: узкие `resource` в `services.yaml` по слоям, все сервисы и алиасы по умолчанию private.

HTTP API контроллеры одного действия:
- Актор `Admin` (`Presentation/Http/Controller/Api/Admin/`):
  - `UpdateStockOnHandController::__invoke(string $productId, UpdateStockOnHandRequest $request)`: `PATCH /api/v1/admin/stock/{productId}` (format: `json`), имя маршрута `inventory_admin_stock_update`, доступ `ROLE_ADMIN` (защищён `security.yaml` access_control и проверкой `X-CSRF-Token`). Маппинг через `#[MapRequestPayload(acceptFormat: 'json')]`.

HTTP Request DTO и валидация (`Presentation/Http/Request/`):
- `UpdateStockOnHandRequest`:
  - `onHand: ?int = null`, ограничения `#[Assert\NotNull]`, `#[Assert\PositiveOrZero]` (неотрицательное целое число).
  - Преобразуется в `ChangeStockOnHandCommand(productId: $productId, onHand: $request->onHand)`.

Семантика PATCH:
- Поле `onHand` является обязательным. Отсутствие поля или передача `null` отклоняются валидатором Symfony с кодом HTTP 422.
- Отрицательные значения `onHand` отклоняются валидацией с кодом HTTP 422.
- Попытка установить `onHand` ниже зарезервированного количества возвращает HTTP 422 с телом `{"error": "stock_on_hand_below_reserved", ...}` через `InventoryErrorProvider`.
- Несуществующий `productId` возвращает HTTP 404 с телом `{"error": "stock_position_not_found", ...}` через `InventoryErrorProvider`.
- Некорректный синтаксис JSON возвращает HTTP 400.
- Неподдерживаемый `Content-Type` возвращает HTTP 415.
- Отсутствующий или невалидный заголовок `X-CSRF-Token` возвращает HTTP 403 (`invalid_csrf_token`).
- Неавторизованный запрос возвращает HTTP 401, запрос без роли администратора — HTTP 403.

## Хранение, сообщения и конфигурация

- Собственная схема PostgreSQL: `inventory`.
- Таблицы: `inventory.stock_positions` с первичным ключом `product_id`, `inventory.reservations` с первичным ключом `order_id` и `inventory.reservation_items`.
- Маппинги зарегистрированы через DBAL-типы `inventory_product_id` (`ProductIdType`) и `inventory_order_id` (`OrderIdType`).
- Репозитории:
  - `StockPositionRepositoryInterface` в `Domain/Repository/` содержит `save(StockPosition $position): void` и `remove(StockPosition $position): void`.
  - `ReservationRepositoryInterface` в `Domain/Repository/` содержит `save(Reservation $reservation): void` и `remove(Reservation $reservation): void`.
- DataProviders:
  - `StockPositionDataProviderInterface` в `Domain/DataProvider/`: `findByProductId(ProductId $productId, bool $lock = false): StockPosition` и `findAll(): StockPositionCollection`.
  - `ReservationDataProviderInterface` в `Domain/DataProvider/`: `findByOrderId(OrderId $orderId, bool $lock = false): Reservation`.
- Коллекции:
  - `StockPositionCollection` в `Domain/Collection/StockPositionCollection` (`StockPosition::class`).
  - `ReservationCollection` в `Domain/Collection/ReservationCollection` (`Reservation::class`).
  - `ReservationItemCollection` в `Domain/Collection/ReservationItemCollection` (`ReservationItem::class`).
- Фикстуры: `InventoryFixtures` в `Infrastructure/Persistence/Doctrine/Fixtures/InventoryFixtures` помечен `#[When('dev')]` и `#[When('test')]`, наполняет склад начальными остатками для демо-товаров (`prod-keyboard`: 10, `prod-mouse`: 20, `prod-monitor`: 5, `prod-headphones`: 0).
- Доменные исключения маппятся в HTTP-статусы через `InventoryErrorProvider`.

## Проверки и изменения

- Тесты модуля:
  - Unit: `tests/Unit/Inventory/`
  - Integration: `tests/Integration/Inventory/`
  - Functional: `tests/Functional/Inventory/`
- Запуск тестов модуля:
  ```console
  docker compose exec php vendor/bin/phpunit tests/Unit/Inventory tests/Integration/Inventory tests/Functional/Inventory
  ```
