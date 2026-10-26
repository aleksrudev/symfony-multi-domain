# Этап 5. Приёмка сбоев, эксплуатация и реестр событий

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Подтвердить независимость механизмов и надёжность их композиции для IntegrationEvent на PostgreSQL; зафиксировать эксплуатацию.

**Architecture:** Сначала проверяются нейтральные механизмы отдельно, затем реальные source-транзакции, relay с Messenger-адаптером и worker. Реестр описывает фактические публичные события; эксплуатационные инструкции различают неотправленный Outbox и failed уже доставленного события.

**Tech Stack:** Symfony 8, PostgreSQL 16, Messenger Worker, PHPUnit, Symfony Process, существующие make-проверки.

**Spec:** [Разделы 5–10](../specs/2026-10-24-module-events-design.md); [общие ограничения](2026-10-26-module-events-implementation.md).

## Global Constraints

Выполняется после этапа 4. Проверки используют только выделенную test-БД,
согласованно подготовленную через `make test-db-prepare`. Тесты не применяют
миграции самостоятельно; DAMA rollback отключается там, где тестируется
реальный commit между соединениями. Бизнес-модули и внешние интеграции
для демонстрации не создаются.

## Review Focus

- Commit реакции до ACK и аварийное завершение worker — задача 1.
- Частичный успех нескольких подписчиков и ORM-ошибка у B — задача 1.
- Исчерпанные повторы на каждой из двух границ — задача 2.
- Реальная сериализация и повтор без служебных штампов — задачи 1–2.
- Документы описывают работающий канал и не выдают fixtures за события приложения — задача 3.

## Задача 1. Приёмочные PostgreSQL-сценарии

**Files:**

- Rewrite/Test: `tests/Integration/PostgresIntegrationEventTest.php`.
- Modify: `tests/Fixtures/TransactionalMessagingKernel.php`.
- Create: `tests/Fixtures/Messaging/ConsumerProcess.php` и `RelayProcess.php` — entrypoints отдельных процессов для конкурентных/аварийных проверок.

**Interfaces:**

- Consumes: `CommandBusInterface`, `IntegrationEventDispatcherInterface`, нейтральный `OutboxRelay::relayOne()`, MessengerOutboxPublisher и обычный `Worker` на async.
- Produces: проверка всей цепочки source → Outbox → event.bus → async → Idempotent Consumer → Command.
- Процессные helpers завершаются по явному барьеру/таймауту; не зависят от sleep как доказательства наступления нужной фазы.

- [ ] Добавить `testUncommittedSourceIsInvisibleToRelay` и `testSnapshotFailureRollsBackSource` с отдельным observer connection.
- [ ] Добавить `testCrashAfterConsumerCommitBeforeAckDoesNotRepeatEffect`: завершить отдельный worker после commit до ACK; повторно получить исходное сообщение и подтвердить один эффект и один receipt.
- [ ] Добавить `testConcurrentWorkersCommitOneReaction` с двумя процессами и одинаковой идентичностью события; уникальность receipt должна определять результат.
- [ ] Добавить `testFailedOrmSubscriberDoesNotUndoSuccessfulSubscriber` и вариант с упавшим первым handler: проверить работу после штатного worker reset, отсутствие потерянной реакции и утечки UnitOfWork.
- [ ] Добавить `testRelayFailureAndRestartPreserveEventIdentity` и `testConsumerPublishesNextFactAtomically`. Все ожидания привязать к конкретным IDs, а не общему числу сообщений общей test-БД.
- [ ] Выполнить `make composer CMD="exec -- phpunit tests/Integration/PostgresIntegrationEventTest.php"`; приёмка требует реально выполненных PostgreSQL-тестов, SKIP не считается доказательством.
- [ ] Исправлять обнаруженные дефекты в компонентах этапов 2–4 с повтором только релевантных проверок, затем повторить приёмочную группу.

## Задача 2. Исчерпанные повторы и восстановление

**Files:**

- Modify/Test: `tests/Integration/OutboxCommandsTest.php`, `tests/Integration/IntegrationEventDeliveryTest.php`.
- Modify: `tests/Fixtures/TransactionalMessagingKernel.php` — нулевые задержки только в worker-тестах, значения production-конфигурации проверять отдельно.

**Interfaces:**

- Незавершённый relay: failed-запись `shared.outbox_messages` восстанавливается `app:outbox:retry <id>`. Для будущего внешнего publisher это не доказательство отсутствия удалённого эффекта.
- После передачи в async: стандартный Messenger `failed` и `messenger:failed:retry`.
- Успешный receipt переживает оба варианта повторной доставки.

- [ ] Проверить четыре неуспешные попытки relay: запись становится failed и не крутится бесконечно, async пуста, snapshot сохранён.
- [ ] В OutboxCommandsTest восстановить нейтральный DTO командой retry без event-полей. В IntegrationEventDeliveryTest отдельно проверить, что с текущим Messenger-адаптером в async приходит прежний eventId/eventAt и вложенные DTO.
- [ ] Проверить worker с A-success/B-failure: после трёх повторов событие находится в failed, A зафиксирован ровно один раз, receipt B отсутствует.
- [ ] Исправить B и восстановить событие штатным Messenger failed retry; B применяется один раз, A не повторяется, failed освобождается.
- [ ] Дополнительно повторить событие в свежем Envelope без HandledStamp. Успех не должен зависеть от сохранения служебной истории Messenger.
- [ ] Проверить логирование без payload и отсутствие автоочистки failed/processed. Порядок событий/внешние HTTP-эффекты не объявлять exactly-once.

## Задача 3. Реестр и эксплуатационные инструкции

**Files:**

- Create: `docs/ai/events.md`.
- Modify: `docs/ai/map.md`, `AGENTS.md`, `src/Shared/AGENTS.md`.
- Modify: `docs/ai/rules/integration-events.md`, `transactional-messaging.md`, `checks.md` в том же каталоге.
- Modify: `docs/ai/templates/module-AGENTS.md`.

**Interfaces:**

- Consumes: формат карточек из раздела 9 спецификации и фактическое состояние main.
- Produces: один реестр событий и инструкции публикации, подписки, запуска и восстановления.

- [ ] Создать events.md с явной записью «Публичных межмодульных событий и подписчиков пока нет» и правилами ведения/ссылкой на формат. Не добавлять карточки Alpha/Beta и проектируемых демо-событий.
- [ ] Добавить ссылки на реестр в карту, root AGENTS и шаблон модульной инструкции; требовать его обновления вместе с добавлением/изменением/удалением публикации или подписки.
- [ ] Обновить фактическое описание Shared: Application содержит Command/Query; нейтральные Outbox/Consumer находятся в Infrastructure; отдельно перечислить событийные и Messenger-адаптеры, реальные пути и команды.
- [ ] Описать `make worker`, `make outbox-worker` и восстановление каждой границы. Для диагностики Outbox показывать ID/attempts/failed_at без печати body; метаданные конкретного события не являются колонками общей таблицы.
- [ ] Зафиксировать общие retry/failed события, выбор handlers на доставке, отсутствие исторического replay, отсутствие гарантий FIFO, стабильность consumerId и запрет удаления receipt без отдельной политики.
- [ ] Проверить, что инструкции больше не требуют дополнительных Inbox DTO, inbox.bus/outbox.bus, отдельного fanout registry или публикации через native EventDispatcher.

## Задача 4. Финальная проверка и отчёт

**Files:**

- Review: все изменённые исходники, конфигурация, обе независимые миграции и документы этапов 1–5.
- Modify: `docs/ai/map.md` только при расхождении с фактическим кодом.

**Interfaces:**

- Produces: воспроизводимые результаты обязательных проверок и список реально оставшихся ограничений.

- [ ] Повторить независимые тесты OutboxKernel с PlainMessage/test publisher и IdempotentConsumerKernel с MessageIdentity/callback. Они не используют IntegrationEvent и не требуют event.bus.
- [ ] Проверить импорты/сигнатуры файлов Outbox и IdempotentConsumer: отсутствуют IntegrationEventInterface, Symfony Messenger, Envelope, event.bus и command.bus. Конкретные события и маршрутизация остаются в адаптерах этапа 4.
- [ ] Выполнить `make lint`, `make test` и `make architecture`.
- [ ] Выполнить `make console CMD="lint:container --env=test"` и `make console CMD="lint:container --env=prod"`.
- [ ] Выполнить `make console CMD="doctrine:schema:validate --skip-sync --env=test"` и `docker compose config --quiet`.
- [ ] Выполнить `git diff --check`, проверить относительные ссылки/fences Markdown и отсутствие старых рабочих импортов/aliases.
- [ ] Сверить карту с `src/Kernel.php` и фактическими модулями: main содержит Shared, Alpha/Beta остаются fixtures.
- [ ] Записать результаты тестов, число реально выполненных/пропущенных PostgreSQL-проверок и ограничения окружения. Не объявлять завершение при SKIP обязательной приёмки.

## Готовность этапа

Проверены source rollback, relay rollback/recovery, consumer concurrency,
commit-before-ACK, частичный успех handlers и восстановление failed.
Документы описывают реализованный путь; заменённые API отсутствуют.
Автоматический коммит и запуск/изменение внешнего окружения не выполняются.
