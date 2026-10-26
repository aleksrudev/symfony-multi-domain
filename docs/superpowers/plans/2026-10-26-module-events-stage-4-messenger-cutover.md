# Этап 4. Композиция IntegrationEvent, механизмов надёжности и Messenger

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Связать независимые Outbox и Idempotent Consumer с интеграционными событиями через адаптеры и удалить старую доставку.

**Architecture:** IntegrationEventDispatcher валидирует событие и вызывает нейтральный OutboxWriter. Relay передаёт object через OutboxPublisherInterface; Messenger-адаптер, собранный с event.bus, отправляет его в async. Подписчик преобразует метаданные события в MessageIdentity и вызывает Idempotent Consumer.

**Tech Stack:** Symfony 8 Messenger/DI, Doctrine transport, компоненты этапов 2–3, Symfony Console, PHPUnit.

**Spec:** [Разделы 3–7](../specs/2026-10-24-module-events-design.md); [общие ограничения](2026-10-26-module-events-implementation.md).

## Global Constraints

Этапы 2 и 3 должны пройти независимые проверки. Это единственный этап,
который собирает механизмы вокруг IntegrationEvent и знает имя event.bus.
Подключение нового пути и удаление старого выполняются одним изменением.
Миграции и действия с окружением выполняются с согласованием по AGENTS.md.

## Review Focus

- Проверка eventAt/type/schemaVersion относится к Dispatcher, а не Outbox — задача 1.
- Relay и Writer не получают Envelope и MessageBusInterface — задача 1.
- namespace/message_id выводятся из события только в адаптере подписчика — задача 2.
- Receipt, бизнесовый эффект и следующее исходящее событие атомарны — задача 2.
- Старые worker, Scheduler и fixtures не сохраняют InboxBus/OutboxBus скрытой зависимостью — задача 3.

## Задача 1. Адаптеры публикации и конфигурация

**Files:**

- Create: `src/Shared/Infrastructure/Messaging/IntegrationEventDispatcherInterface.php`.
- Create: `src/Shared/Infrastructure/Messaging/OutboxIntegrationEventDispatcher.php`.
- Create: `src/Shared/Infrastructure/Messaging/Messenger/MessengerOutboxPublisher.php`.
- Create/Test: `tests/Unit/Infrastructure/MessengerOutboxPublisherTest.php`.
- Modify: `config/packages/messenger.yaml`, `src/Shared/config/services.yaml`.
- Create: `tests/Fixtures/TransactionalMessagingKernel.php` — kernel полной цепочки, отдельно от OutboxKernel и IdempotentConsumerKernel.
- Modify: `tests/Fixtures/Modules/Alpha/Infrastructure/Messaging/PublishProbeEventListener.php`.
- Modify/Test: `tests/Integration/IntegrationEventDeliveryTest.php`.

**Interfaces:**

- `IntegrationEventDispatcherInterface::dispatch(IntegrationEventInterface $event): void`.
- `OutboxIntegrationEventDispatcher` внедряет `OutboxWriterInterface::append(object $message): void`; private AsAlias.
- `MessengerOutboxPublisher` реализует `OutboxPublisherInterface::publish(object $message): void` и внедряет `MessageBusInterface $messageBus`.
- Только DI-конфигурация текущего publisher связывает `$messageBus` с `event.bus`. Имя шины не хранится в Outbox и не зашито в общем relay или контракте publisher.
- `event.bus`: стандартные middleware, validation, doctrine_ping_connection; allow_no_handlers = true, allow_no_senders = false; общей doctrine_transaction нет.
- Routing: `App\Shared\Presentation\Contracts\Event\IntegrationEventInterface: async`. Doctrine async/failed используют default connection, retry 3 / 1000 / 3 / 30000.

- [ ] Добавить unit-тест publisher: переданный обычный object попадает в внедрённый MessageBusInterface. Смена внедрённого test bus не требует изменения Outbox-кода; publisher не проверяет IntegrationEventInterface.
- [ ] Переписать начало integration-теста: после source-команды есть бизнесовая строка и один снимок Outbox, async пуста, подписчики не вызваны; вне транзакции Dispatcher отказывает.
- [ ] Добавить тесты Dispatcher для пустых/слишком длинных ID/source/type, schemaVersion = 0 и некорректного UTC-времени. Неверный публичный DTO не записывается; проверка не переносится в общий Writer.
- [ ] Выполнить целевые тесты и подтвердить FAIL на старом пути.
- [ ] Реализовать Dispatcher с валидацией и вызовом writer.append($event); listener формирует публичный DTO внутри транзакции владельца. Envelope, BusNameStamp и транспортные обёртки издателю не нужны.
- [ ] Реализовать MessengerOutboxPublisher: вызвать dispatch переданного объекта на внедрённой шине; преобразование в Envelope и дальнейшая сериализация остаются внутренними деталями Messenger.
- [ ] Подключить private aliases и узкие resources; MessageIdentity и прочие DTO исключить из service discovery. В resource Messenger задать необходимый bind `$messageBus: '@event.bus'`, без дублирования AsAlias и YAML alias.
- [ ] Добавить event.bus/routing, удалить inbox.bus/outbox.bus и старый outbox transport.
- [ ] Добавить PostgreSQL-тест: при этом Messenger-адаптере INSERT в Doctrine async и удаление Outbox атомарны на default connection. Отказ после dispatch до удаления откатывает обе записи.
- [ ] Выполнить unit/integration-тесты и `make console CMD="debug:messenger --env=test"`. Проверить private wiring и отсутствие DTO-сервисов.

## Задача 2. Адаптер подписчика и транзакционная композиция

**Files:**

- Create: `tests/Fixtures/Modules/Beta/Infrastructure/Integration/Alpha/RecordProbeConsumer.php` и `FailProbeConsumer.php`.
- Create: `tests/Fixtures/Modules/Beta/Application/Command/RecordProbe/RecordProbeCommand.php` и `RecordProbeHandler.php`.
- Create: `tests/Fixtures/Modules/Beta/Application/Command/FailProbe/FailProbeCommand.php` и `FailProbeHandler.php`.
- Create/Test: `tests/Integration/IdempotentConsumerOutboxTest.php`.
- Modify: `tests/Fixtures/TransactionalMessagingKernel.php`, `tests/Integration/IntegrationEventDeliveryTest.php`.

**Interfaces:**

- Адаптер принимает `ProbePublishedEvent`, создаёт `new MessageIdentity(namespace: $event->source, id: $event->eventId)` и передаёт её в `consume($consumerId, $identity, $reaction)`.
- Callback вызывает собственную Command через CommandBusInterface; Consumer не получает ни событие, ни шину команд.
- Два стандартных `AsMessageHandler(bus: 'event.bus')`: стабильные IDs `beta.record-probe` и `beta.fail-probe`.
- Общие retry/failed относятся к событию; consumerId определяет независимый эффект, не отдельную доставку.

- [ ] Написать worker-тест A-success/B-failure: A остаётся committed, B откатывает receipt и эффект, после восстановления B обе реакции завершены.
- [ ] Добавить `testReceiptBusinessChangeAndNewOutboxEventCommitTogether` и rollback-вариант. Следующее событие формирует инфраструктурный listener; Application не обращается к Outbox.
- [ ] Добавить `testNoHandlersIsValid` и `testHandlerAddedBeforeConsumptionReceivesQueuedEvent`. Выбор handlers происходит при доставке, архив/replay не создаётся.
- [ ] Реализовать adapters: извлечь identity из события и обернуть собственный сценарий в Consumer. Не создавать Inbox DTO и не переносить eventAt/type/schemaVersion в processed_messages.
- [ ] Проверить shared default connection: внутренняя транзакция command.bus не фиксирует receipt раньше внешнего commit Consumer.
- [ ] Проверить обычный retry и свежий Envelope без HandledStamp. Закрытый EM восстанавливает штатный reset worker/doctrine_ping_connection, а не Consumer.
- [ ] Выполнить `make composer CMD="exec -- phpunit tests/Integration/IntegrationEventDeliveryTest.php tests/Integration/IdempotentConsumerOutboxTest.php"`. Ожидается PASS.

## Задача 3. Удаление старого пути и запуск процессов

**Files:**

- Delete: `src/Shared/Application/Bus/Inbox/`, `src/Shared/Application/Bus/Outbox/`.
- Delete: `src/Shared/Infrastructure/Bus/Symfony/InboxMessageBus.php`, `OutboxMessageBus.php` в том же каталоге.
- Delete: `src/Shared/Infrastructure/Messaging/IntegrationEvent/IntegrationEventMessage.php` и `src/Shared/Infrastructure/Messaging/IntegrationEvent/IntegrationEventHandler.php`.
- Delete: `tests/Fixtures/Modules/Beta/Application/Message/`, `EnqueueRecordingProbeListener.php` и `EnqueueFailingProbeListener.php` в `Beta/Infrastructure/Integration/Alpha/`.
- Delete: `tests/Fixtures/Modules/Alpha/Application/ProbeInbox.php`, `ProbeOutbox.php`.
- Delete/Replace: `tests/Integration/OutboxBusTest.php`, `PostgresOutboxTest.php`; их гарантии покрывают новые тесты этапов 2–3.
- Delete: `tests/Fixtures/IntegrationEventsKernel.php`, `PostgresIntegrationEventsKernel.php`; потребителей перевести на TransactionalMessagingKernel.
- Modify: `tests/Fixtures/Modules/Alpha/Application/ProbeHandler.php`, `ProbeCommand.php` в том же каталоге, `tests/Fixtures/Modules/Alpha/config/services.yaml`, `tests/Fixtures/Modules/Alpha/Infrastructure/ProbeSchedule.php`, `tests/Integration/ModuleKernelTest.php`.
- Modify: `tests/Integration/PostgresIntegrationEventTest.php` — убрать импорты старого пути до полной приёмки этапа 5.
- Modify: `Makefile`, `compose.yaml`; сверить и при необходимости изменить `compose.override.yaml` и `compose.prod.yaml`.

**Interfaces:**

- `make worker` по-прежнему выполняет `messenger:consume async`.
- `make outbox-worker` запускает `app:outbox:relay` с лимитами из этапа 2.
- Compose-сервис outbox-worker запускает ту же relay-команду; async-worker и scheduler сохраняют роли.

- [ ] Удалить старые API, сообщения и регистрацию; из общего ProbeHandler убрать outbox/inbox-методы и доступ к инфраструктурному Outbox из Application.
- [ ] Для проверки публикации использовать PublishProbeEventCommand и инфраструктурный listener. Generic ProbeCommand оставить только для синхронных тестов wiring/validation/rollback.
- [ ] Заменить Scheduler-fixture на локальный ProbeCommand в Envelope с `BusNameStamp('command.bus')` без RedispatchMessage в async. Сохранить проверку добавления расписания отдельно от событийной доставки.
- [ ] Переписать ModuleKernelTest: сохранить проверки module wiring, HTTP, Command/Query, rollback, расписания и отсутствия fixture-модулей в приложении. Старые Inbox-тесты заменить новыми event-тестами, а не просто удалить покрытие.
- [ ] Обновить команды Make/Compose; проверить `docker compose config --quiet` без запуска новых процессов и вывода раскрытой конфигурации.
- [ ] Выполнить поиск старых импортов/регистрации в `src/`, `tests/`, `config/` и Compose. Допустимы только явные отрицательные assertions, не рабочие ссылки.
- [ ] Выполнить `make lint`, `make test`, `make architecture` и lint контейнеров test/prod. Все проверки проходят на новой схеме.

## Готовность этапа

Новый путь подключён целиком, старого рабочего пути нет. Инфраструктурные адаптеры
связывают нейтральные механизмы со штатными Messenger handlers.
Независимые тесты OutboxKernel/IdempotentConsumerKernel продолжают проходить. Осталась [полная приёмка](2026-10-26-module-events-stage-5-acceptance-and-operations.md).
