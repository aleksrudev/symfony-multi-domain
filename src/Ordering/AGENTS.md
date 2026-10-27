# Инструкция модуля Ordering

## Назначение и язык

Контекст `Ordering` владеет:
- Корзиной и черновиком заказа покупателя (`draft`);
- Позициями заказа (`OrderItem`) и их количеством;
- Состоянием оформления заказа, жизненным циклом и отменой;
- Гарантией владения заказом (покупатель имеет доступ только к собственным заказам).

Контекст `Ordering` НЕ владеет:
- Карточкой товара, каталожной ценой и публикацией (владеет `Catalog`);
- Складскими позициями, остатками и резервированием (владеет `Inventory`);
- Заданиями уведомлений и их доставкой (владеет `Notifications`).

Термины:
- `Order` — агрегат заказа/корзины;
- `OrderItem` — позиция заказа с привязкой к товару и количеством;
- `Draft` — активный черновик заказа (корзина) покупателя.

## Модель и инварианты

Агрегат `Order` (`id: OrderId`, `buyerId: BuyerId`, `status: OrderStatus`, `items: OrderItemCollection`, `totalAmount: ?int`, `currency: ?Currency`, `createdAt: DateTimeImmutable`, `updatedAt: DateTimeImmutable`).
- `OrderId` — `final readonly` value object в `Domain/ValueObject/OrderId`, наследует `App\Shared\Domain\ValueObject\UuidId` (UUIDv4 в формате RFC 4122 в нижнем регистре с дефисами, генерация по умолчанию без аргументов).
- `BuyerId` — `final readonly` value object в `Domain/ValueObject/BuyerId`, оборачивает строковый идентификатор покупателя. Использование скалярной строки обусловлено получением идентификатора из внешней аутентификации/сессии.
- `ProductId` — `final readonly` value object в `Domain/ValueObject/ProductId`, оборачивает строковый идентификатор товара в позиции заказа. Использование скалярной строки обусловлено согласованными строковыми идентификаторами каталога без внешних ключей.
- `ProductSnapshot` — `final readonly` value object в `Domain/ValueObject/ProductSnapshot` (`productId: ProductId`, `name: string`, `priceAmount: int`, `currency: Currency`), неизменяемый снимок данных товара на момент оформления заказа; коллекция `ProductSnapshotCollection` в `Domain/Collection/ProductSnapshotCollection`. Метод `findByProductId(ProductId $productId)` типизирован доменным ID.
- `OrderStatus` — backed enum в `Domain/Enum/OrderStatus` со значениями:
  - `case DRAFT = 'draft';`
  - `case PENDING_STOCK = 'pending_stock';`
  - `case CONFIRMED = 'confirmed';`
  - `case REJECTED = 'rejected';`
  - `case CANCELLING = 'cancelling';`
  - `case CANCELLED = 'cancelled';`
- `Currency` — backed enum в `Domain/Enum/Currency` со значениями:
  - `case RUB = 'RUB';`
- `IdempotencyOperation` — backed enum в `Domain/Enum/IdempotencyOperation` со значениями:
  - `case CREATE_DRAFT = 'create_draft';`
  - `case SUBMIT_ORDER = 'submit_order';`
  - `case CANCEL_ORDER = 'cancel_order';`
- Позиция `OrderItem` (`orderId: OrderId`, `productId: ProductId`, `quantity: int`, `productName: ?string`, `priceAmount: ?int`, `currency: ?Currency`). Снимки заполняются при переводе в `pending_stock` через метод `attachSnapshot(string $productName, int $priceAmount, Currency $currency)`.
- Инвариант одного черновика (BR-16): у покупателя одновременно может быть не более одного активного черновика (`status === 'draft'`). Это гарантируется доменной проверкой при создании и частичным уникальным индексом PostgreSQL `ON ordering.orders (buyer_id) WHERE status = 'draft'`. Переход заказа в `pending_stock` освобождает этот слот, позволяя создать новый черновик.
- Инвариант количества (BR-02): количество каждой позиции — строго положительное целое число (`quantity > 0`). При `<= 0` выбрасывается `InvalidOrderItemQuantityException`.
- Инвариант модификации: состав заказа можно изменять только в состоянии `draft` (`$order->replaceItems(...)`). При попытке изменить заказ в ином состоянии выбрасывается `OrderNotModifiableException`. При замене позиций передаваемый список не может быть пустым (`EmptyOrderItemsException`).
- Инвариант неизменяемости снимков цен (BR-03, BR-15, A-04): метод `$order->submit(ProductSnapshotCollection $snapshots)` валидирует статус `draft`, наличие позиций, соответствие снимка каждой позиции, прикрепляет снимки к `OrderItem` и фиксирует общую сумму заказа в копейках `totalAmount` и валюту `currency`. Последующие изменения цен в `Catalog` не влияют на оформленный заказ.
- Инвариант владения (BR-09, A-13): покупатель видит и изменяет только собственные заказы. Запрос чужого заказа выбрасывает `OrderNotFoundException` (HTTP 404).

Доменные Action:
- `CreateDraftAction` (`Domain/Action/CreateDraftAction`): проверяет наличие активного черновика через `OrderDataProviderInterface::findDraftByBuyerId()`. Если черновик найден — возвращает его. Если нет — создаёт `Order::createDraft()`, сохраняет через `OrderRepositoryInterface` и возвращает новый черновик.
- `ReplaceOrderItemsAction` (`Domain/Action/ReplaceOrderItemsAction`): загружает заказ через `OrderDataProviderInterface::findByIdAndBuyerId()`, вызывает доменный метод `$order->replaceItems()`, сохраняет через `OrderRepositoryInterface`.
- `SubmitOrderAction` (`Domain/Action/SubmitOrderAction`): принимает `Order $order` и `ProductSnapshotCollection $snapshots`, вызывает доменный метод `$order->submit($snapshots)`, сохраняет заказ через `OrderRepositoryInterface`, создаёт и публикует доменное событие `OrderSubmittedEvent` через `EventBusInterface`.
- `ConfirmOrderAction` (`Domain/Action/ConfirmOrderAction`): принимает `Order $order`, вызывает `$order->confirm()`, сохраняет заказ через `OrderRepositoryInterface`.
- `RejectOrderAction` (`Domain/Action/RejectOrderAction`): принимает `Order $order` и `string $reason`, вызывает `$order->reject($reason)`, сохраняет заказ через `OrderRepositoryInterface`.
- `CancelOrderAction` (`Domain/Action/CancelOrderAction`): принимает `Order $order`, вызывает `$order->cancel()`, сохраняет заказ через `OrderRepositoryInterface`, создаёт и публикует доменное событие `OrderCancellingEvent` через `EventBusInterface`.
- `FinalizeOrderCancellationAction` (`Domain/Action/FinalizeOrderCancellationAction`): принимает `Order $order`, вызывает `$order->finalizeCancellation()`, сохраняет заказ через `OrderRepositoryInterface`.

Доменные события:
- `OrderSubmittedEvent` (`Domain/Event/OrderSubmittedEvent`): реализует `App\Shared\Domain\Event\EventInterface`. Содержит ссылку на агрегат `Order $order` и момент операции `DateTimeImmutable $eventAt`. Публикуется из `SubmitOrderAction` после вызова `OrderRepositoryInterface::save()`.
- `OrderCancellingEvent` (`Domain/Event/OrderCancellingEvent`): реализует `App\Shared\Domain\Event\EventInterface`. Содержит ссылку на агрегат `Order $order` и момент операции `DateTimeImmutable $eventAt`. Публикуется из `CancelOrderAction` после вызова `OrderRepositoryInterface::save()`.
- `OrderConfirmedEvent` (`Domain/Event/OrderConfirmedEvent`): реализует `App\Shared\Domain\Event\EventInterface`. Содержит ссылку на `Order $order` и `DateTimeImmutable $eventAt`. Публикуется из `ConfirmOrderAction`.
- `OrderRejectedEvent` (`Domain/Event/OrderRejectedEvent`): реализует `App\Shared\Domain\Event\EventInterface`. Содержит ссылку на `Order $order`, причину отказа `string $reason` и `DateTimeImmutable $eventAt`. Публикуется из `RejectOrderAction`.
- `OrderCancellationFinalizedEvent` (`Domain/Event/OrderCancellationFinalizedEvent`): реализует `App\Shared\Domain\Event\EventInterface`. Содержит ссылку на `Order $order` и `DateTimeImmutable $eventAt`. Публикуется из `FinalizeOrderCancellationAction`.

Application сценарии:
- `CreateDraftCommand` / `CreateDraftHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): тонкий оркестратор создания/получения черновика. Обеспечивает соблюдение `Idempotency-Key` (повтор с тем же ключом и хэшем тела возвращает тот же заказ; повтор с тем же ключом и другим телом вызывает `IdempotencyConflictException`). Команда возвращает `void`.
- `ReplaceOrderItemsCommand` / `ReplaceOrderItemsHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): принимает `list<ReplaceOrderItemInput>`, маппит в `OrderItemCollection` и вызывает `ReplaceOrderItemsAction`. Команда возвращает `void`.
- `SubmitOrderCommand` / `SubmitOrderHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): оркестратор оформления заказа. Проверяет `Idempotency-Key` (операция `submit_order`; при совпадении ключа немедленно завершает выполнение без повторной публикации события), загружает заказ покупателя, запрашивает через прикладной провайдер `ProductOfferProviderInterface` актуальные сведения о ценах и наименованиях товаров (`ProductOffer`), проверяет доступность и публикацию каждого товара (выбрасывает `ProductNotAvailableException`), формирует `ProductSnapshotCollection`, выполняет `SubmitOrderAction` и фиксирует ответ в таблице идемпотентности. Не публикует исходящие сообщения напрямую; команда возвращает `void`.
- `ConfirmOrderCommand` / `ConfirmOrderHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): оркестратор подтверждения заказа после подтверждения резерва складом. Загружает заказ с пессимистичной блокировкой и вызывает `ConfirmOrderAction`.
- `RejectOrderCommand` / `RejectOrderHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): оркестратор отказа по заказу при невозможности резерва складом. Загружает заказ с пессимистичной блокировкой и вызывает `RejectOrderAction`.
- `CancelOrderCommand` / `CancelOrderHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): оркестратор перевода заказа в статус `cancelling`. Поддерживает `Idempotency-Key` (операция `cancel_order`). Загружает заказ с пессимистичной блокировкой, проверяет покупателя (если передан) и вызывает `CancelOrderAction`.
- `FinalizeOrderCancellationCommand` / `FinalizeOrderCancellationHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): оркестратор завершения отмены заказа при подтверждении освобождения складом (`released`, `rejected`, `not_found`). Загружает заказ с пессимистичной блокировкой и вызывает `FinalizeOrderCancellationAction`.
- `ReconcileStockCommand` / `ReconcileStockHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): периодический опрос заказов по времени `next_check_at <= now()`. Для `confirmed` при превышении тайм-аута (15 мин) и для `pending_stock` при превышении тайм-аута (5 мин) инициирует отмену через `CancelOrderCommand`. Для `pending_stock` опрашивает склад: при `reserved` вызывает `ConfirmOrderCommand`, при `rejected` вызывает `RejectOrderCommand`. Для `cancelling` опрашивает склад: при `released`, `rejected` или `not_found` вызывает `FinalizeOrderCancellationCommand`.
- `GetDraftQuery` / `GetDraftHandler` (`#[AsMessageHandler(bus: 'query.bus')]`): загружает активный черновик покупателя и возвращает модель `GetDraftQueryResult` с `list<OrderItemQueryResult>`.
- `GetOrderQuery` / `GetOrderHandler` (`#[AsMessageHandler(bus: 'query.bus')]`): загружает заказ через `OrderDataProviderInterface::findByIdAndBuyerId()` и формирует `GetOrderQueryResult` с `list<OrderItemQueryResult>` и `rejectionReason`.
- `GetOrdersQuery` / `GetOrdersHandler` (`#[AsMessageHandler(bus: 'query.bus')]`): запрашивает пагинированную выборку через `OrderDataProviderInterface::findByBuyerIdPaginated()` и формирует `GetOrdersQueryResult` (`items: list<GetOrderQueryResult>`).
- `GetOrderDiagnosticsQuery` / `GetOrderDiagnosticsHandler` (`#[AsMessageHandler(bus: 'query.bus')]`): загружает заказ через `OrderDataProviderInterface::findById()`, опрашивает склад через `OrderReservationStatusProviderInterface` и историю уведомлений через `OrderNotificationStatusProviderInterface`, объединяя переходы заказа, результат складской операции и статус уведомлений в `GetOrderDiagnosticsQueryResult`.

Прикладные провайдеры Application:
- `ProductOfferProviderInterface` (`Application/Command/SubmitOrder/Provider/ProductOfferProviderInterface`): интерфейс получения актуальных предложений каталога. Возвращает модель результата сценария `ProductOffer` (`Application/Command/SubmitOrder/Provider/ProductOffer`). Реализуется в `Infrastructure/Integration/Catalog/CatalogOfferProvider` с `#[AsAlias]`.
- `ReservationResultProviderInterface` (`Application/Command/ReconcileStock/Provider/ReservationResultProviderInterface`): интерфейс запроса результата складского резерва. Возвращает модель `ReservationResult`. Реализуется в `Infrastructure/Integration/Inventory/InventoryReservationResultProvider` с `#[AsAlias]`.
- `OrderReservationStatusProviderInterface` (`Application/Query/GetOrderDiagnostics/Provider/OrderReservationStatusProviderInterface`): интерфейс запроса складского статуса для диагностики. Возвращает `OrderReservationStatus`. Реализуется в `Infrastructure/Integration/Inventory/InventoryReservationStatusProvider` с `#[AsAlias]`.
- `OrderNotificationStatusProviderInterface` (`Application/Query/GetOrderDiagnostics/Provider/OrderNotificationStatusProviderInterface`): интерфейс запроса истории уведомлений для диагностики. Возвращает `list<OrderNotificationStatus>`. Реализуется в `Infrastructure/Integration/Notifications/NotificationsStatusProvider` с `#[AsAlias]`.

## Публичный API и зависимости

Экспортируемые публичные события:
- `App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent`: реализует Shared `IntegrationEventInterface`. Содержит скалярные метаданные (`eventId`, `eventAt` в UTC RFC 3339, `type = 'ordering.order_submitted'`, `source = 'Ordering'`, `schemaVersion = 1`) и payload (`orderId`, `buyerId`, `items: list<SubmittedOrderItem>`, `totalAmount`, `currency`).
- `App\Ordering\Presentation\Contracts\Event\OrderCancelledEvent`: реализует Shared `IntegrationEventInterface`. Содержит скалярные метаданные (`eventId`, `eventAt` в UTC RFC 3339, `type = 'ordering.order_cancelled'`, `source = 'Ordering'`, `schemaVersion = 1`) и payload (`orderId`, `buyerId`).
- `App\Ordering\Presentation\Contracts\Event\OrderConfirmedEvent`: реализует Shared `IntegrationEventInterface`. Содержит скалярные метаданные (`type = 'ordering.order_confirmed'`) и payload (`orderId`, `buyerId`, `totalAmount`, `currency`).
- `App\Ordering\Presentation\Contracts\Event\OrderRejectedEvent`: реализует Shared `IntegrationEventInterface`. Содержит скалярные метаданные (`type = 'ordering.order_rejected'`) и payload (`orderId`, `buyerId`, `reason`).
- `App\Ordering\Presentation\Contracts\Event\OrderCancellationFinalizedEvent`: реализует Shared `IntegrationEventInterface`. Содержит скалярные метаданные (`type = 'ordering.order_cancellation_finalized'`) и payload (`orderId`, `buyerId`).
- Вложенный DTO: `App\Ordering\Presentation\Contracts\Dto\SubmittedOrderItem` (`productId`, `quantity`, `productName`, `priceAmount`).

Публикация в Outbox:
- `App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderSubmittedEventListener`: помечен `#[AsEventListener(event: DomainOrderSubmittedEvent::class)]`. Проверяет полноту данных оформленного заказа без скрывающих ошибки fallback-значений, передаёт публичное событие в `IntegrationEventDispatcherInterface::dispatch()` внутри транзакции команды.
- `App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderCancelledEventListener`: помечен `#[AsEventListener(event: OrderCancellingEvent::class)]`. Передаёт публичное событие `OrderCancelledEvent` в `IntegrationEventDispatcherInterface::dispatch()` внутри транзакции команды.
- `App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderConfirmedEventListener`: помечен `#[AsEventListener(event: DomainOrderConfirmedEvent::class)]`. Передаёт публичное событие `OrderConfirmedEvent` в `IntegrationEventDispatcherInterface::dispatch()` внутри транзакции команды.
- `App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderRejectedEventListener`: помечен `#[AsEventListener(event: DomainOrderRejectedEvent::class)]`. Передаёт публичное событие `OrderRejectedEvent` в `IntegrationEventDispatcherInterface::dispatch()` внутри транзакции команды.
- `App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderCancellationFinalizedEventListener`: помечен `#[AsEventListener(event: DomainOrderCancellationFinalizedEvent::class)]`. Передаёт публичное событие `OrderCancellationFinalizedEvent` в `IntegrationEventDispatcherInterface::dispatch()` внутри транзакции команды.

Зависимости от других бизнес-модулей:
- Модуль зависит от `Catalog`, `Inventory` и `Notifications` синхронно (`src/Ordering/config/dependencies.php`: `['sync' => ['Catalog', 'Inventory', 'Notifications'], 'events' => []]`).
- Адаптер `CatalogOfferProvider` в `Infrastructure/Integration/Catalog/CatalogOfferProvider.php` помечен `#[AsAlias(ProductOfferProviderInterface::class)]` и вызывает контракт `CatalogContractInterface::getOffer()` поставщика `Catalog`.
- Для `Inventory` зависимость используется синхронно при вызове `InventoryContractInterface::getReservationResult()`.
- Для `Notifications` адаптер `NotificationsStatusProvider` в `Infrastructure/Integration/Notifications/` синхронно запрашивает историю уведомлений через `NotificationsContractInterface::getNotificationsForOrder()` для административной диагностики.

### Интеграция межмодульных событий и T08–T13

Ordering управляет оформлением, публикацией интеграционного факта `OrderSubmittedEvent` через outbox, а в T09 — периодической сверкой (`ReconcileStockCommand`).
Публикация интеграционных событий передаётся через `IntegrationEventDispatcherInterface`, сохраняется в таблице `shared.outbox_messages`, перекладывается relay в `event.bus` (async) и асинхронно обрабатывается подписчиками (`Inventory` и `Notifications`).
Целевые асинхронные команды и отложенные адресные вызовы чужих API через Outbox-сообщения отсутствуют.
Граф зависимостей:
- `Ordering`: sync: [Catalog, Inventory, Notifications], events: []
- `Inventory`: sync: [], events: [Ordering]
- `Notifications`: sync: [], events: [Ordering]

В T09 порт чтения результата резерва и собственная модель находятся в
`Application/Command/ReconcileStock/Provider/`; адаптер вызывает
`InventoryContractInterface::getReservationResult()`. Scheduler только инициирует
собственный сценарий через `ScheduleContributorInterface`, переходы и проверки текущего статуса остаются в Domain Ordering.
В T13 `GetOrderDiagnosticsController` читает результаты склада и ящика уведомлений через Application-порты и контракты `Inventory` и `Notifications`.

### HTTP API

HTTP API контроллеры одного действия:
- Актор `Admin` (`Presentation/Http/Controller/Api/Admin/`):
  - `GetOrderDiagnosticsController::__invoke(string $id)`: `GET /api/v1/admin/diagnostics/orders/{id}`, имя `ordering_admin_diagnostics_orders_item`. Требует `ROLE_ADMIN`. Возвращает HTTP 200 со структурой `order`, `inventory`, `notifications` или HTTP 404 (`order_not_found`).
- Актор `Buyer` (`Presentation/Http/Controller/Api/Buyer/`), все помечены `#[IsGranted('ROLE_BUYER')]`:
  - `CreateOrderController::__invoke()`: `POST /api/v1/orders`, имя `ordering_orders_create`. Требует `ROLE_BUYER`, заголовок `X-CSRF-Token` и опциональный `Idempotency-Key`. Возвращает HTTP 200.
  - `ReplaceOrderItemsController::__invoke(string $id, ReplaceOrderItemsRequest $request)`: `PUT /api/v1/orders/{id}/items`, имя `ordering_orders_replace_items`. Требует `ROLE_BUYER`, `X-CSRF-Token`. Маппинг через `#[MapRequestPayload(acceptFormat: 'json')]`. Возвращает HTTP 200.
  - `SubmitOrderController::__invoke(string $id)`: `POST /api/v1/orders/{id}/submission`, имя `ordering_orders_submit`. Требует `ROLE_BUYER`, `X-CSRF-Token` и опциональный `Idempotency-Key`. Возвращает HTTP 202.
  - `CancelOrderController::__invoke(string $id)`: `POST /api/v1/orders/{id}/cancellation`, имя `ordering_orders_cancel`. Требует `ROLE_BUYER`, `X-CSRF-Token` и опциональный `Idempotency-Key`. Возвращает HTTP 202.
  - `GetOrderController::__invoke(string $id)`: `GET /api/v1/orders/{id}`, имя `ordering_orders_item`. Требует `ROLE_BUYER`. Возвращает HTTP 200 или 404.
  - `GetOrdersController::__invoke(GetOrdersRequest $request)`: `GET /api/v1/orders`, имя `ordering_orders_list`. Требует `ROLE_BUYER`. Маппинг через `#[MapQueryString(validationFailedStatusCode: 422)]`. Возвращает HTTP 200.

HTTP Request DTO (`Presentation/Http/Request/`):
- `ReplaceOrderItemsRequest`:
  - `items: list<OrderItemInput>`, `#[Assert\NotBlank]`, `#[Assert\Count(min: 1)]`, `#[Assert\Valid]`.
- `OrderItemInput`:
  - `productId: string`, `#[Assert\NotBlank]`;
  - `quantity: int`, `#[Assert\Positive]`.
- `GetOrdersRequest`:
  - `page: int = 1`, `#[Assert\Positive]`;
  - `limit: int = 10`, `#[Assert\Range(min: 1, max: 50)]`.

## Хранение, сообщения и конфигурация

- Собственная схема PostgreSQL: `ordering`.
- Таблицы:
  - `ordering.orders`: `id`, `buyer_id`, `status`, `total_amount`, `currency`, `created_at`, `updated_at`.
  - `ordering.order_items`: `order_id`, `product_id`, `quantity`, `product_name`, `price_amount`, `currency` с внешним ключом на `orders.id` (`ON DELETE CASCADE`).
  - `ordering.idempotency_keys`: `key`, `buyer_id`, `operation`, `request_hash`, `response_payload`, `created_at`.
- DBAL-тип `order_id` (`OrderIdType`) сопоставлен с `OrderId`.
- Репозитории:
  - `OrderRepository` (`Infrastructure/Persistence/Doctrine/Repository/OrderRepository.php`) реализует `OrderRepositoryInterface` с `#[AsAlias]`.
  - `IdempotencyKeyRepository` (`Infrastructure/Persistence/Doctrine/Repository/IdempotencyKeyRepository.php`) реализует `IdempotencyKeyRepositoryInterface` с `#[AsAlias]`.
- DataProvider:
  - `OrderDataProvider` (`Infrastructure/Persistence/Doctrine/DataProvider/OrderDataProvider.php`) реализует `OrderDataProviderInterface` с `#[AsAlias]`. Стабильная сортировка при пагинации по `createdAt DESC, id ASC`.
  - `IdempotencyKeyDataProvider` (`Infrastructure/Persistence/Doctrine/DataProvider/IdempotencyKeyDataProvider.php`) реализует `IdempotencyKeyDataProviderInterface` с `#[AsAlias]`.
- Доменные исключения маппятся в HTTP-статусы через `OrderingErrorProvider`.
- Конфигурация DI: узкие `resource` по слоям, все сервисы и алиасы по умолчанию private.

## Проверки и изменения

- Расположение тестов:
  - Unit: `tests/Unit/Ordering/`
  - Integration: `tests/Integration/Ordering/`
  - Functional: `tests/Functional/Ordering/`
- Команда запуска тестов модуля:
  ```console
  docker compose exec php vendor/bin/phpunit tests/Unit/Ordering tests/Integration/Ordering tests/Functional/Ordering
  ```
