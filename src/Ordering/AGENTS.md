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
- `ProductSnapshot` — `final readonly` value object в `Domain/ValueObject/ProductSnapshot` (`productId: ProductId`, `productName: string`, `priceAmount: int`, `currency: Currency`), неизменяемый снимок данных товара на момент оформления заказа; коллекция `ProductSnapshotCollection` в `Domain/Collection/ProductSnapshotCollection`.
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
- Позиция `OrderItem` (`orderId: OrderId`, `productId: ProductId`, `quantity: int`, `productName: ?string`, `priceAmount: ?int`, `currency: ?Currency`). Снимки заполняются при переводе в `pending_stock` через метод `attachSnapshot(ProductSnapshot $snapshot)`.
- Инвариант одного черновика (BR-16): у покупателя одновременно может быть не более одного активного черновика (`status === 'draft'`). Это гарантируется доменной проверкой при создании и частичным уникальным индексом PostgreSQL `ON ordering.orders (buyer_id) WHERE status = 'draft'`. Переход заказа в `pending_stock` освобождает этот слот, позволяя создать новый черновик.
- Инвариант количества (BR-02): количество каждой позиции — строго положительное целое число (`quantity > 0`). При `<= 0` выбрасывается `InvalidOrderItemQuantityException`.
- Инвариант модификации: состав заказа можно изменять только в состоянии `draft` (`$order->replaceItems(...)`). При попытке изменить заказ в ином состоянии выбрасывается `OrderNotModifiableException`. При замене позиций передаваемый список не может быть пустым (`EmptyOrderItemsException`).
- Инвариант неизменяемости снимков цен (BR-03, BR-15, A-04): метод `$order->submit(ProductSnapshotCollection $snapshots)` валидирует статус `draft`, наличие позиций, соответствие снимка каждой позиции, прикрепляет снимки к `OrderItem` и фиксирует общую сумму заказа в копейках `totalAmount` и валюту `currency`. Последующие изменения цен в `Catalog` не влияют на оформленный заказ.
- Инвариант владения (BR-09, A-13): покупатель видит и изменяет только собственные заказы. Запрос чужого заказа выбрасывает `OrderNotFoundException` (HTTP 404).

Доменные Action:
- `CreateDraftAction` (`Domain/Action/CreateDraftAction`): проверяет наличие активного черновика через `OrderDataProviderInterface::findDraftByBuyerId()`. Если черновик найден — возвращает его. Если нет — создаёт `Order::createDraft()`, сохраняет через `OrderRepositoryInterface` и возвращает новый черновик.
- `ReplaceOrderItemsAction` (`Domain/Action/ReplaceOrderItemsAction`): загружает заказ через `OrderDataProviderInterface::findByIdAndBuyerId()`, вызывает доменный метод `$order->replaceItems()`, сохраняет через `OrderRepositoryInterface`.
- `SubmitOrderAction` (`Domain/Action/SubmitOrderAction`): принимает `Order $order` и `ProductSnapshotCollection $snapshots`, вызывает доменный метод `$order->submit($snapshots)`, сохраняет заказ через `OrderRepositoryInterface`, создаёт и публикует доменное событие `OrderSubmittedEvent` через `EventBusInterface`.

Доменные события:
- `OrderSubmittedEvent` (`Domain/Event/OrderSubmittedEvent`): реализует `App\Shared\Domain\Event\EventInterface`. Содержит ссылку на агрегат `Order $order` и момент операции `DateTimeImmutable $eventAt`. Публикуется из `SubmitOrderAction` после вызова `OrderRepositoryInterface::save()`.

Application сценарии:
- `CreateDraftCommand` / `CreateDraftHandler`: тонкий оркестратор создания/получения черновика. Обеспечивает соблюдение `Idempotency-Key` (повтор с тем же ключом и хэшем тела возвращает тот же заказ; повтор с тем же ключом и другим телом вызывает `IdempotencyConflictException`). Возвращает `CreateDraftResult`.
- `ReplaceOrderItemsCommand` / `ReplaceOrderItemsHandler`: маппит массив позиций в `OrderItemCollection` и вызывает `ReplaceOrderItemsAction`. Возвращает `ReplaceOrderItemsResult`.
- `SubmitOrderCommand` / `SubmitOrderHandler`: оркестратор оформления заказа. Проверяет `Idempotency-Key` (операция `submit_order`; при совпадении ключа немедленно завершает выполнение без повторной публикации события), загружает заказ покупателя, запрашивает через прикладной провайдер `ProductOfferProviderInterface` актуальные сведения о ценах и наименованиях товаров (`ProductOffer`), проверяет доступность и публикацию каждого товара (выбрасывает `ProductNotAvailableException`), формирует `ProductSnapshotCollection`, выполняет `SubmitOrderAction` и фиксирует ответ в таблице идемпотентности. Не публикует исходящие сообщения напрямую.
- `GetOrderQuery` / `GetOrderHandler`: загружает заказ через `OrderDataProviderInterface::findByIdAndBuyerId()` и формирует `GetOrderQueryResult`.
- `GetOrdersQuery` / `GetOrdersHandler`: запрашивает пагинированную выборку через `OrderDataProviderInterface::findByBuyerIdPaginated()` и формирует `GetOrdersQueryResult`.

Прикладные провайдеры, сообщения и реакции Application:
- `ProductOfferProviderInterface` (`Application/Command/SubmitOrder/Provider/ProductOfferProviderInterface`): интерфейс получения актуальных предложений каталога. Возвращает модель результата сценария `ProductOffer` (`Application/Command/SubmitOrder/Provider/ProductOffer`).
- `PublishOrderSubmittedMessageListener` (`Application/EventListener/PublishOrderSubmittedMessageListener`): синхронный listener `kernel.event_listener` на `OrderSubmittedEvent`. Извлекает сериализуемые данные из агрегата `$event->order`, собирает `OrderSubmittedOutboxMessage` и публикует его через `OutboxBusInterface` внутри транзакции команды до commit.
- `OrderSubmittedOutboxMessage` (`Application/Message/OrderSubmittedOutboxMessage`): исходящее сообщение шины outbox (`OutboxMessageInterface`), содержащее самостоятельные сериализуемые скалярные значения (`orderId`, `buyerId`, `items`, `totalAmount`, `currency`, `occurredAt`) без ссылок на агрегат или ORM-коллекции.

## Публичный API и зависимости

Экспортируемые контракты:
- Модуль `Ordering` пока не экспортирует публичных контрактов для других модулей.

Зависимости от других бизнес-модулей:
- Модуль зависит от `Catalog` (объявлен в `src/Ordering/config/dependencies.php`).
- Адаптер `CatalogOfferProvider` в `Infrastructure/Integration/Catalog/CatalogOfferProvider.php` реализует `ProductOfferProviderInterface`, вызывая контракт `CatalogContractInterface::getOffer()` поставщика `Catalog`.

HTTP API контроллеры одного действия:
- Актор `Buyer` (`Presentation/Http/Controller/Api/Buyer/`):
  - `CreateOrderController::__invoke()`: `POST /api/v1/orders`, имя `buyer_orders_create`. Требует `IS_AUTHENTICATED_FULLY` (`ROLE_BUYER`), заголовок `X-CSRF-Token` и опциональный `Idempotency-Key`. Возвращает HTTP 201/200.
  - `ReplaceOrderItemsController::__invoke(string $id, ReplaceOrderItemsRequest $request)`: `PUT /api/v1/orders/{id}/items`, имя `buyer_orders_replace_items`. Требует `ROLE_BUYER`, `X-CSRF-Token`. Маппинг через `#[MapRequestPayload(acceptFormat: 'json')]`. Возвращает HTTP 200.
  - `SubmitOrderController::__invoke(string $id)`: `POST /api/v1/orders/{id}/submission`, имя `buyer_orders_submit`. Требует `ROLE_BUYER`, `X-CSRF-Token` и опциональный `Idempotency-Key`. Возвращает HTTP 202.
  - `GetOrderController::__invoke(string $id)`: `GET /api/v1/orders/{id}`, имя `buyer_orders_item`. Требует `ROLE_BUYER`. Возвращает HTTP 200 или 404.
  - `GetOrdersController::__invoke(GetOrdersRequest $request)`: `GET /api/v1/orders`, имя `buyer_orders_list`. Требует `ROLE_BUYER`. Маппинг через `#[MapQueryString(validationFailedStatusCode: 422)]`. Возвращает HTTP 200.

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
- Частичный уникальный индекс: `ON ordering.orders (buyer_id) WHERE status = 'draft'`.
- Миграции: `Version20261019000000.php` (добавление колонок снимков цен и суммы заказа).
- Репозитории:
  - `OrderRepositoryInterface` в `Domain/Repository/` (методы `save` и `remove`). Реализация в `Infrastructure/Persistence/Doctrine/Repository/OrderRepository`.
  - `IdempotencyKeyRepositoryInterface` в `Domain/Repository/` (методы `save` и `remove`). Реализация в `Infrastructure/Persistence/Doctrine/Repository/IdempotencyKeyRepository`.
- DataProvider:
  - `OrderDataProviderInterface` в `Domain/DataProvider/` (методы `findById`, `findByIdAndBuyerId`, `findDraftByBuyerId`, `findByBuyerIdPaginated`). Реализация в `Infrastructure/Persistence/Doctrine/DataProvider/OrderDataProvider`.
  - `IdempotencyKeyDataProviderInterface` в `Domain/DataProvider/` (метод `findByKeyAndBuyerId`). Реализация в `Infrastructure/Persistence/Doctrine/DataProvider/IdempotencyKeyDataProvider`.
- Коллекции: `OrderItemCollection`, `OrderCollection`, `IdempotencyKeyCollection`, `ProductSnapshotCollection` наследуют `App\Shared\Domain\Collection\AbstractCollection`; `OrderPaginatedCollection` — пагинированная обертка.
- Доменные исключения маппятся через `OrderingErrorProvider`:
  - `OrderNotFoundException` -> 404 `order_not_found`
  - `OrderNotModifiableException` -> 409 `order_not_in_draft_state`
  - `IdempotencyConflictException` -> 409 `idempotency_conflict`
  - `InvalidOrderItemQuantityException` -> 422 `invalid_order_item_quantity`
  - `EmptyOrderItemsException` -> 422 `empty_order_items`
  - `ProductNotAvailableException` -> 422 `product_not_available`

