# Инструкция модуля Notifications

## Назначение и язык

Контекст `Notifications` владеет:
- Локальным ящиком уведомлений покупателей и администратора;
- Состоянием доставки уведомлений (`pending`, `sent`, `failed`), попытками и текстом ошибок;
- Гарантией идемпотентного приёма уведомления (одно уведомление на пару `orderId + type`);
- Имитацией доставки в локальный ящик и управляемой симуляцией сбоев в тестовом окружении;
- Административным API просмотра локального ящика (`GET /api/v1/admin/notifications`).

Контекст `Notifications` НЕ владеет:
- Жизненным циклом, переходами и составом заказов (владеет `Ordering`);
- Складскими остатками и резервированием (владеет `Inventory`);
- Каталогом и карточками товаров (владеет `Catalog`).

Термины:
- `Notification` — сущность уведомления в локальном ящике;
- `DeliveryChannel` — канал доставки уведомления в локальный ящик;
- `Pending` — уведомление зарегистрировано и ожидает отправки;
- `Sent` — уведомление успешно доставлено в локальный ящик;
- `Failed` — доставка не удалась после исчерпания лимита попыток.

## Модель и инварианты

Агрегат `Notification` (`id: NotificationId`, `orderId: string`, `type: NotificationType`, `buyerId: string`, `status: NotificationStatus`, `attempts: int`, `payload: array`, `errorMessage: ?string`, `createdAt: DateTimeImmutable`, `updatedAt: DateTimeImmutable`, `sentAt: ?DateTimeImmutable`):
- `NotificationId` — `final readonly` value object в `Domain/ValueObject/NotificationId`, наследует `App\Shared\Domain\ValueObject\UuidId` (UUIDv4 в формате RFC 4122 в нижнем регистре с дефисами).
- `NotificationType` — backed enum в `Domain/Enum/NotificationType` (`ORDER_CONFIRMED = 'order_confirmed'`, `ORDER_REJECTED = 'order_rejected'`, `ORDER_CANCELLED = 'order_cancelled'`).
- `NotificationStatus` — backed enum в `Domain/Enum/NotificationStatus` (`PENDING = 'pending'`, `SENT = 'sent'`, `FAILED = 'failed'`).
- Инвариант единичного уведомления (BR-14): на одну пару `orderId + type` создаётся строго одно уведомление. Это защищается доменным действием `CreateNotificationAction` и уникальным индексом `uniq_notifications_order_type` в схеме `notifications`. Повторный приём того же задания возвращает уже созданную запись без дублирования.
- Инвариант независимости заказа от сбоя доставки (BR-13, A-10): ошибка доставки уведомления фиксируется в `errorMessage` и `status = 'failed'` после исчерпания попыток (`maxAttempts = 3`), не влияя на заказ и склад.

Доменные Action:
- `CreateNotificationAction` (`Domain/Action/CreateNotificationAction`): проверяет наличие уведомления по `orderId` и `type`. Если найдено — возвращает существующее. Если нет — создаёт `Notification::createPending(...)` и сохраняет через `NotificationRepositoryInterface`.
- `MarkNotificationSentAction` (`Domain/Action/MarkNotificationSentAction`): переводит статус в `SENT`, фиксирует `sentAt` и сохраняет через `NotificationRepositoryInterface`.
- `RecordNotificationFailureAction` (`Domain/Action/RecordNotificationFailureAction`): инкрементирует `attempts`, при превышении `maxAttempts` переводит в `FAILED`, сохраняет `errorMessage`.

Application сценарии:
- `EnqueueNotificationCommand` / `EnqueueNotificationHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): принимает задачу на уведомление, вызывает `CreateNotificationAction` и отправляет команду `SendNotificationCommand`.
- `SendNotificationCommand` / `SendNotificationHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): загружает уведомление под пессимистической блокировкой, вызывает `NotificationDeliveryChannelInterface::send()`. При успехе вызывает `MarkNotificationSentAction`, при сбое — `RecordNotificationFailureAction`.
- `GetNotificationStatusQuery` / `GetNotificationStatusHandler` (`#[AsMessageHandler(bus: 'query.bus')]`): ищет статус уведомления по `orderId` и `type`, возвращает `NotificationStatusQueryResult`.
- `GetNotificationsQuery` / `GetNotificationsHandler` (`#[AsMessageHandler(bus: 'query.bus')]`): загружает пагинированный список уведомлений администратора, возвращает `GetNotificationsQueryResult`.

## Публичный API и зависимости

Экспортируемый контракт:
- `App\Notifications\Presentation\Contracts\NotificationsContractInterface`:
  - `enqueueNotification(EnqueueNotificationInput $input): void` — постановка уведомления в локальный ящик;
  - `getNotificationStatus(string $orderId, string $type): ?NotificationStatusDto` — запрос статуса уведомления;
  - `getNotificationsForOrder(string $orderId): list<NotificationStatusDto>` — список уведомлений по заказу.
- DTOs в `Presentation/Contracts/Dto/`:
  - `EnqueueNotificationInput` (`orderId: string`, `type: string`, `buyerId: string`, `payload: array<string, mixed> = []`);
  - `NotificationStatusDto` (`id`, `orderId`, `type`, `buyerId`, `status`, `attempts`, `payload`, `errorMessage`, `createdAt`, `updatedAt`, `sentAt`).
- Адаптер контракта:
  - `NotificationsContractAdapter` в `Presentation/Adapter/NotificationsContractAdapter.php` с `#[AsAlias(NotificationsContractInterface::class, public: true)]`.

Зависимости модуля:
- `Notifications/config/dependencies.php`: `['sync' => [], 'events' => ['Ordering']]`. Модуль подписывается на публичные интеграционные события `Ordering`.

Адаптеры подписчика (`Infrastructure/Integration/Ordering/`):
- `OrderConfirmedNotificationConsumer` (`#[AsMessageHandler(bus: 'event.bus')]`): слушает `OrderConfirmedEvent`, через `IdempotentConsumerInterface` (ID `notifications.order-confirmed`) отправляет `EnqueueNotificationCommand(type: 'order_confirmed')`.
- `OrderRejectedNotificationConsumer` (`#[AsMessageHandler(bus: 'event.bus')]`): слушает `OrderRejectedEvent`, через `IdempotentConsumerInterface` (ID `notifications.order-rejected`) отправляет `EnqueueNotificationCommand(type: 'order_rejected')`.
- `OrderCancellationFinalizedNotificationConsumer` (`#[AsMessageHandler(bus: 'event.bus')]`): слушает `OrderCancellationFinalizedEvent`, через `IdempotentConsumerInterface` (ID `notifications.order-cancellation-finalized`) отправляет `EnqueueNotificationCommand(type: 'order_cancelled')`.

Канал доставки:
- `NotificationDeliveryChannelInterface` в `Infrastructure/Delivery/NotificationDeliveryChannelInterface.php`.
- Реализация `LocalMailboxDeliveryChannel` с `#[AsAlias]`. Поддерживает тестовый флаг `notifications.demo.fail_delivery` (в `config/services.yaml`) для эмуляции воспроизводимого технического сбоя.

### HTTP API

HTTP API контроллеры одного действия:
- Актор `Admin` (`Presentation/Http/Controller/Api/Admin/`):
  - `GetNotificationsController::__invoke(GetNotificationsRequest $request)`: `GET /api/v1/admin/notifications`, имя `notifications_admin_notifications_list`. Требует роль `ROLE_ADMIN`. Маппинг через `#[MapQueryString(validationFailedStatusCode: 422)]`. Возвращает HTTP 200 с пагинацией (`items`, `page`, `limit`, `total`).

HTTP Request DTO:
- `GetNotificationsRequest` (`page: int = 1`, `limit: int = 10` с валидацией `#[Assert\Positive]` и `#[Assert\Range(min: 1, max: 50)]`).

## Хранение, сообщения и конфигурация

- Собственная схема PostgreSQL: `notifications`.
- Таблица: `notifications.notifications`:
  - `id` (UUID, PK)
  - `order_id` (VARCHAR(36))
  - `type` (VARCHAR(50))
  - `buyer_id` (VARCHAR(100))
  - `status` (VARCHAR(30))
  - `attempts` (INT)
  - `payload` (JSON)
  - `error_message` (TEXT)
  - `created_at` (TIMESTAMP(6))
  - `updated_at` (TIMESTAMP(6))
  - `sent_at` (TIMESTAMP(6))
  - Уникальный индекс: `uniq_notifications_order_type (order_id, type)`.
- DBAL-тип: `notification_id` (`NotificationIdType`).
- Репозиторий: `NotificationRepository` (`NotificationRepositoryInterface`).
- DataProvider: `NotificationDataProvider` (`NotificationDataProviderInterface`).
- Коллекция: `NotificationCollection` (`AbstractCollection<Notification>`).
- Ошибки: `NotificationsErrorProvider` (`DomainErrorProviderInterface`).

## Проверки и изменения

- Тесты модуля:
  - Unit: `tests/Unit/Notifications/`
  - Integration: `tests/Integration/Notifications/`
  - Functional: `tests/Functional/Notifications/`
- Запуск тестов модуля:
  ```console
  docker compose exec php vendor/bin/phpunit tests/Unit/Notifications tests/Integration/Notifications tests/Functional/Notifications
  ```

