# Этап 5. Приёмка сбоев, эксплуатация и реестр событий

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

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
- Create: `tests/Fixtures/Messaging/Integration/consumer-process.php` и `relay-process.php` в том же каталоге — entrypoints отдельных процессов для конкурентных/аварийных проверок.

**Interfaces:**

- Consumes: `CommandBusInterface`, `IntegrationEventDispatcherInterface`, нейтральный `OutboxRelay::relayOne()`, MessengerOutboxPublisher и обычный `Worker` на async.
- Produces: проверка всей цепочки source → Outbox → event.bus → async → Idempotent Consumer → Command.
- Процессные helpers завершаются по явному барьеру/таймауту; не зависят от sleep как доказательства наступления нужной фазы.

- [x] Добавить `testUncommittedSourceIsInvisibleToRelay` и `testSnapshotFailureRollsBackSource` с отдельным observer connection.
- [x] Добавить `testCrashAfterConsumerCommitBeforeAckDoesNotRepeatEffect`: завершить отдельный worker после commit до ACK; повторно получить исходное сообщение и подтвердить один эффект и один receipt.
- [x] Добавить `testConcurrentWorkersCommitOneReaction` с двумя процессами и одинаковой идентичностью события; уникальность receipt должна определять результат.
- [x] Добавить `testFailedOrmSubscriberDoesNotUndoSuccessfulSubscriber` и вариант с упавшим первым handler: проверить работу после штатного worker reset, отсутствие потерянной реакции и утечки UnitOfWork.
- [x] Добавить `testRelayFailureAndRestartPreserveEventIdentity` и `testConsumerPublishesNextFactAtomically`. Все ожидания привязать к конкретным IDs, а не общему числу сообщений общей test-БД.
- [x] Выполнить `make composer CMD="exec -- phpunit tests/Integration/PostgresIntegrationEventTest.php"`; приёмка требует реально выполненных PostgreSQL-тестов, SKIP не считается доказательством.
- [x] Исправлять обнаруженные дефекты в компонентах этапов 2–4 с повтором только релевантных проверок, затем повторить приёмочную группу.

## Задача 2. Исчерпанные повторы и восстановление

**Files:**

- Modify/Test: `tests/Integration/OutboxCommandsTest.php`, `tests/Integration/IntegrationEventDeliveryTest.php`.
- Modify: `tests/Fixtures/TransactionalMessagingKernel.php` — нулевые задержки только в worker-тестах, значения production-конфигурации проверять отдельно.

**Interfaces:**

- Незавершённый relay: failed-запись `shared.outbox_messages` восстанавливается `app:outbox:retry <id>`. Для будущего внешнего publisher это не доказательство отсутствия удалённого эффекта.
- После передачи в async: стандартный Messenger `failed` и `messenger:failed:retry`.
- Успешный receipt переживает оба варианта повторной доставки.

- [x] Проверить четыре неуспешные попытки relay: запись становится failed и не крутится бесконечно, async пуста, snapshot сохранён.
- [x] В OutboxCommandsTest восстановить нейтральный DTO командой retry без event-полей. В IntegrationEventDeliveryTest отдельно проверить, что с текущим Messenger-адаптером в async приходит прежний eventId/eventAt и вложенные DTO.
- [x] Проверить worker с A-success/B-failure: после трёх повторов событие находится в failed, A зафиксирован ровно один раз, receipt B отсутствует.
- [x] Исправить B и восстановить событие штатным Messenger failed retry; B применяется один раз, A не повторяется, failed освобождается.
- [x] Дополнительно повторить событие в свежем Envelope без HandledStamp. Успех не должен зависеть от сохранения служебной истории Messenger.
- [x] Проверить логирование без payload и отсутствие автоочистки failed/processed. Порядок событий/внешние HTTP-эффекты не объявлять exactly-once.

## Задача 3. Реестр и эксплуатационные инструкции

**Files:**

- Create: `docs/ai/events.md`.
- Modify: `docs/ai/map.md`, `AGENTS.md`, `src/Shared/AGENTS.md`.
- Modify: `docs/ai/rules/integration-events.md`, `transactional-messaging.md`, `checks.md` в том же каталоге.
- Modify: `docs/ai/templates/module-AGENTS.md`.

**Interfaces:**

- Consumes: формат карточек из раздела 9 спецификации и фактическое состояние main.
- Produces: один реестр событий и инструкции публикации, подписки, запуска и восстановления.

- [x] Создать events.md с явной записью «Публичных межмодульных событий и подписчиков пока нет» и правилами ведения/ссылкой на формат. Не добавлять карточки Alpha/Beta и проектируемых демо-событий.
- [x] Добавить ссылки на реестр в карту, root AGENTS и шаблон модульной инструкции; требовать его обновления вместе с добавлением/изменением/удалением публикации или подписки.
- [x] Обновить фактическое описание Shared: Application содержит Command/Query; нейтральные Outbox/Consumer находятся в Infrastructure; отдельно перечислить событийные и Messenger-адаптеры, реальные пути и команды.
- [x] Описать `make worker`, `make outbox-worker` и восстановление каждой границы. Для диагностики Outbox показывать ID/attempts/failed_at без печати body; метаданные конкретного события не являются колонками общей таблицы.
- [x] Зафиксировать общие retry/failed события, выбор handlers на доставке, отсутствие исторического replay, отсутствие гарантий FIFO, стабильность consumerId и запрет удаления receipt без отдельной политики.
- [x] Проверить, что инструкции больше не требуют дополнительных Inbox DTO, inbox.bus/outbox.bus, отдельного fanout registry или публикации через native EventDispatcher.

## Задача 4. Финальная проверка и отчёт

**Files:**

- Review: все изменённые исходники, конфигурация, обе независимые миграции и документы этапов 1–5.
- Modify: `docs/ai/map.md` только при расхождении с фактическим кодом.

**Interfaces:**

- Produces: воспроизводимые результаты обязательных проверок и список реально оставшихся ограничений.

- [x] Повторить независимые тесты OutboxKernel с PlainMessage/test publisher и IdempotentConsumerKernel с MessageIdentity/callback. Они не используют IntegrationEvent и не требуют event.bus.
- [x] Проверить импорты/сигнатуры файлов Outbox и IdempotentConsumer: отсутствуют IntegrationEventInterface, Symfony Messenger, Envelope, event.bus и command.bus. Конкретные события и маршрутизация остаются в адаптерах этапа 4.
- [x] Выполнить `make lint`, `make test` и `make architecture`.
- [x] Выполнить `make console CMD="lint:container --env=test"` и `make console CMD="lint:container --env=prod"`.
- [x] Выполнить `make console CMD="doctrine:schema:validate --skip-sync --env=test"` и `docker compose config --quiet`.
- [x] Выполнить `git diff --check`, проверить относительные ссылки/fences Markdown и отсутствие старых рабочих импортов/aliases.
- [x] Сверить карту с `src/Kernel.php` и фактическими модулями: main содержит Shared, Alpha/Beta остаются fixtures.
- [x] Записать результаты тестов, число реально выполненных/пропущенных PostgreSQL-проверок и ограничения окружения. Не объявлять завершение при SKIP обязательной приёмки.

## Готовность этапа

Проверены source rollback, relay rollback/recovery, consumer concurrency,
commit-before-ACK, частичный успех handlers и восстановление failed.
Документы описывают реализованный путь; заменённые API отсутствуют.
Автоматический коммит и запуск/изменение внешнего окружения не выполняются.

## Результат реализации

Реализована приёмка без изменений production API и новых миграций. Процессные
барьеры фиксируют commit до ACK и send до commit; тесты завершают дочерние процессы
через SIGKILL и ждут закрытия PostgreSQL backend перед восстановлением.
При повторе после crash ускоряется только истечение lease мёртвого worker
через delivered_at; исходные snapshot/receipt/эффекты не меняются.

Конкурентные worker обрабатывают два сообщения с одной identity, наблюдаемая
блокировка transactionid подтверждает разрешение гонки уникальностью receipt.
ORM-ошибка проверена в обоих порядках handlers; стандартный reset восстанавливает
EM перед следующей доставкой. При ошибке первым последующие реакции могут
отказать в той же попытке, а после исправления восстанавливаются вместе с событием.

Добавлены реестр docs/ai/events.md и инструкции запуска, диагностики и двух
вариантов восстановления. Реестр пуст; Alpha/Beta остаются fixtures.
Production retry проверяется с обычным jitter ±10%; failed/receipt не очищаются
по возрасту. Тесты отдельно подтверждают сохранение receipt спустя 20 лет Clock.

make test: 227 тестов, 1001 assertions, без пропусков. PostgreSQL: 94 проверки
(45 Outbox, 24 Consumer, 25 Messaging), все выполнены. make lint/architecture,
контейнеры test/prod, metadata и обе Compose-конфигурации прошли.
Read-only команды диагностики из инструкции проверены на Messaging test-БД.

Независимое техническое ревью выполнено. Противоречий в механизмах,
схемах и гарантиях приёмки не найдено. Исправлена последняя устаревшая фраза
в AI-правилах контрактов модулей; runtime и тесты после проверок не менялись.
Этап прошёл ревью пользователя; коммит согласован. Все пять этапов завершены.
