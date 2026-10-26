# Этап 3. Переиспользуемый Idempotent Consumer

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Атомарно фиксировать эффект callback и отметку заданной идентичности сообщения, не зная его типа и транспорта.

**Architecture:** Consumer принимает consumerId, нейтральный MessageIdentity и callback. Реализация использует уникальность PostgreSQL и общую транзакцию отметки с локальными изменениями. IntegrationEvent, Messenger, Outbox и command.bus не входят в зависимости механизма.

**Tech Stack:** PHP >= 8.4.17, DBAL 4, PostgreSQL 16, Doctrine ORM 3, PSR Clock, PHPUnit.

**Spec:** [Раздел 6](../specs/2026-10-24-module-events-design.md); [общие ограничения](2026-10-26-module-events-implementation.md).

## Global Constraints

Этап зависит от правил этапа 1, но не от кода/схемы Outbox этапа 2.
Собственная миграция и её применение требуют согласования по AGENTS.md.
До этапа 4 компоненты подключаются только в отдельном test-kernel.
Inbox, общий MessageInterface и registry обработчиков не создаются.

## Review Focus

- Нейтральная identity обрабатывается без объекта события и Envelope — задача 1.
- Пространства идентификаторов и consumerId изолируют независимые обработки — задача 2.
- INSERT и эффект откатываются вместе, включая конкурирующие callback — задача 2.
- Ошибка не оставляет чужие изменения в UnitOfWork — задача 3.
- Метаданные IntegrationEvent извлекаются только адаптером этапа 4; у механизма нет такой зависимости — задача 3 и проверка границ этапа 5.

## Задача 1. Нейтральная идентичность и собственная схема

**Files:**

- Create: `src/Shared/Infrastructure/Messaging/IdempotentConsumer/MessageIdentity.php`.
- Create после согласования: `migrations/Version20261026000100.php`.
- Modify: `config/packages/doctrine.yaml` — исключение технической таблицы из ORM schema diff.
- Create: `tests/Fixtures/IdempotentConsumerKernel.php`.
- Create/Test: `tests/Unit/Infrastructure/MessageIdentityTest.php`.
- Create/Test: `tests/Integration/IdempotentConsumerSchemaTest.php`.

**Interfaces:**

- `final readonly class MessageIdentity`: `__construct(public string $namespace, public string $id)`.
- Оба значения непустые, до 190 символов; constructor отклоняет неверные значения через InvalidArgumentException. Namespace означает область уникальности любого протокола, а не обязательно source события.
- `shared.processed_messages`: NOT NULL `consumer_id VARCHAR(190)`, `namespace VARCHAR(190)`, `message_id VARCHAR(190)`, `processed_at TIMESTAMP(6) WITHOUT TIME ZONE` UTC.
- PRIMARY KEY `(consumer_id, namespace, message_id)`. Внешних ключей и payload нет.

- [ ] Написать unit-тесты корректной identity, пустых и слишком длинных значений. Конкретные примеры: `MessageIdentity('provider.webhook', 'delivery-42')` и `MessageIdentity('batch.import', 'delivery-42')` различаются.
- [ ] Выполнить `make composer CMD="exec -- phpunit tests/Unit/Infrastructure/MessageIdentityTest.php"`, подтвердить FAIL, реализовать DTO и получить PASS.
- [ ] После согласования написать schema-тест и подтвердить FAIL до миграции.
- [ ] Создать отдельную миграцию processed_messages с CREATE SCHEMA IF NOT EXISTS shared; в down удалять только таблицу, не схему. Не менять миграцию Outbox и не требовать наличия его таблицы.
- [ ] Настроить отдельный kernel с default PostgreSQL connection и существующим test-database-guard, без Outbox, Messenger handlers и IntegrationEvent fixtures.
- [ ] После согласованной подготовки БД получить PASS схемы и проверить, что ORM schema diff не предлагает удалить processed_messages.

## Задача 2. Транзакционная отметка и callback

**Files:**

- Create: `src/Shared/Infrastructure/Messaging/IdempotentConsumer/IdempotentConsumerInterface.php`.
- Create: `src/Shared/Infrastructure/Messaging/IdempotentConsumer/DoctrineIdempotentConsumer.php`.
- Create/Test: `tests/Integration/IdempotentConsumerTest.php`.
- Modify: `tests/Fixtures/IdempotentConsumerKernel.php`.

**Interfaces:**

- Produces: `IdempotentConsumerInterface::consume(string $consumerId, MessageIdentity $identity, callable $reaction): void`; PHPDoc callback — `callable(): void`.
- Consumes реализации: `Connection`, `ManagerRegistry`, `Psr\Clock\ClockInterface`; private AsAlias.
- `consumerId` — непустой стабильный ID обработки до 190 символов. Он не выводится из имени PHP-класса.
- Callback обеспечивает локальный эффект; consumer не знает, вызывается ли внутри Command, обработчик webhook или другая операция.

- [ ] Написать `testRepeatedIdentityDoesNotRepeatEffect`, `testDifferentConsumersProcessSameIdentity` и `testSameIdFromDifferentNamespacesDoesNotCollide`. Использовать строки из задачи 1 и SQL/ORM callback, а не событие.
- [ ] Добавить `testFailureRollsBackReceiptAndEffect` и `testTwoProcessesApplyOneEffect`: первый callback меняет probe-строку и падает; после rollback нет эффекта и receipt.
- [ ] Добавить `testOuterTransactionIsRejected`: выбранная Doctrine-реализация владеет внешней транзакцией обработки; при уже активной транзакции callback не вызывается. Это ограничение реализации, не правило транспорта.
- [ ] Выполнить `make composer CMD="exec -- phpunit tests/Integration/IdempotentConsumerTest.php"` и подтвердить ожидаемый FAIL.
- [ ] Реализовать транзакцию и `INSERT ... ON CONFLICT DO NOTHING` по `(consumer_id, namespace, message_id)`. При 0 вставленных строк callback пропускается; предварительный SELECT не используется как защита.
- [ ] После INSERT выполнить callback, flush единственного EntityManager и внешний commit. Ошибка callback/flush/commit откатывает receipt и эффект вместе.
- [ ] Повторить тесты: при конкуренции второй процесс после commit первого пропускает callback, после rollback первого получает возможность выполнить его. Перезапуск не требует объекта исходного сообщения.
- [ ] Проверить сохранение receipt без TTL; автоматическая очистка не включается.

## Задача 3. Граница Doctrine UnitOfWork

**Files:**

- Modify: `src/Shared/Infrastructure/Messaging/IdempotentConsumer/DoctrineIdempotentConsumer.php`.
- Create: `tests/Fixtures/Messaging/ConsumerProbe.php` — нейтральная ORM-fixture.
- Create/Test: `tests/Integration/IdempotentConsumerOrmTest.php`.
- Modify: `tests/Fixtures/IdempotentConsumerKernel.php` — mapping только test-fixture.

**Interfaces:**

- Consumes: единственный default EntityManager через ManagerRegistry.
- Produces: чистый UnitOfWork между обработками и отсутствие нового receipt при недоступном EntityManager.
- Восстановление manager между доставками относится к вызывающему адаптеру/worker, не к Consumer.

- [ ] Написать `testUnflushedChangesCannotLeakIntoNextCallback`: первый callback меняет managed entity и падает до flush; следующий меняет другую строку; первая строка остаётся прежней.
- [ ] Написать `testFlushFailureDoesNotCommitReceiptOrUndoPreviousSuccess` с реальной constraint-ошибкой ORM и `testClosedEntityManagerRejectsCallbackWithoutReceipt`.
- [ ] Выполнить тесты и подтвердить ожидаемый FAIL до защиты UnitOfWork.
- [ ] Проверять открытый manager до callback; после завершения/rollback очищать открытый UnitOfWork. На закрытом manager пробрасывать обычную ошибку без попытки собственного reset контейнера и без зависимостей на Messenger exceptions.
- [ ] В тесте восстановить manager средствами test-kernel и повторить нейтральную identity: обработка завершается. Штатный reset worker проверяется отдельно на этапе 4.
- [ ] Выполнить целевые тесты, `make lint` и `git diff --check`.

## Готовность этапа

Identity и callback достаточно для безопасной обработки. IntegrationEvent,
Envelope, таблица Outbox и event.bus для проверки механизма не нужны.
Связка с событиями и атомарная запись следующего события проверяются
на [этапе 4](2026-10-26-module-events-stage-4-messenger-cutover.md).
