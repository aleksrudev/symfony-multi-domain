# Этап 1. Контракты событий и архитектурные правила

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Зафиксировать контракт публичного события, графы модульных зависимостей и независимость Outbox/Consumer от конкретного протокола.

**Architecture:** Публичный контракт события остаётся независимым от транспорта; общие механизмы надёжности не импортируют этот контракт. Синхронные зависимости и подписки на события проверяются отдельно; выбранные правила доставки описываются как целевые до переключения runtime.

**Tech Stack:** PHP >= 8.4.17, Symfony 8, PHPUnit, Deptrac, существующий генератор `tools/architecture.php`.

**Spec:** [Разделы 1–4 и 9](../specs/2026-10-24-module-events-design.md); [общий план и ограничения](2026-10-26-module-events-implementation.md).

## Global Constraints

Действуют все ограничения общего плана. Не подключать новый путь доставки,
не создавать миграции, не выполнять коммиты. Существующие правила «пустая метка»
и «никаких зависимостей событийного канала на Outbox» заменить выбранным решением;
не оставлять взаимно исключающие инструкции.

## Review Focus

- Метаданные читаются через интерфейс без reflection, property_exists и предположений о конкретном DTO — задача 1.
- Сериализация сохраняет идентичность и время факта — задача 1.
- Взаимные подписки не запрещаются как синхронный цикл — задача 2.
- `events` не даёт права вызывать синхронный API — задача 2.
- Старые инструкции не направляют агентов в InboxBus и композицию EventDispatcher → Inbox — задача 3.

## Задача 1. Контракт метаданных

**Files:**

- Modify: `src/Shared/Presentation/Contracts/Event/IntegrationEventInterface.php`.
- Modify: `tests/Fixtures/Architecture/Allowed/Shared/Presentation/Contracts/Event/IntegrationEventInterface.php` и аналог в `Forbidden/`.
- Modify: `tests/Fixtures/Architecture/Allowed/Alpha/Presentation/Contracts/Event/PublishedEvent.php`.
- Modify: `tests/Fixtures/Architecture/Forbidden/Alpha/Domain/DomainIntegrationEventLeak.php` и `tests/Fixtures/Architecture/Forbidden/Alpha/Application/ApplicationIntegrationEventLeak.php` — сохранить валидную реализацию метаданных, чтобы fixtures нарушали именно границы слоёв.
- Modify: `tests/Fixtures/Modules/Alpha/Presentation/Contracts/Event/ProbePublishedEvent.php`, если текущего readonly DTO недостаточно.
- Create/Test: `tests/Unit/Infrastructure/IntegrationEventContractTest.php`.

**Interfaces:**

- Consumes: текущий `ProbePublishedEvent` и штатная PHP-сериализация без Envelope.
- Produces: `IntegrationEventInterface` с читаемыми свойствами:

```php
public string $eventId { get; }
public string $eventAt { get; }
public string $source { get; }
public string $type { get; }
public int $schemaVersion { get; }
```

Свойства выполняются обычными public readonly-свойствами DTO. `eventId` —
непустой стабильный ID; `eventAt` — UTC `Y-m-d\TH:i:s.u\Z`; `source` и `type`
непустые; `schemaVersion >= 1`. Время относится к факту, а не к relay/worker.
Валидация значений выполняется IntegrationEventDispatcher на этапе 4.
Outbox и Consumer не читают эти свойства; они относятся только к событийной композиции.

- [x] Добавить тест `testMetadataCanBeReadThroughContractAndSurvivesSerialization`: для `ProbePublishedEvent` проверить все пять значений после serialize/unserialize, включая `2026-10-22T09:30:00.123456Z`, `Alpha`, `alpha.published` и версию `1`.
- [x] Добавить testMetadataContractRejectsIncompleteOrIncorrectDto: изолированный PHP -n отклоняет DTO без каждого из пяти свойств и с неверным типом eventId. Сериализация readonly DTO проверяется отдельно; проверка формы интерфейса через Reflection не нужна.
- [x] Выполнить `make composer CMD="exec -- phpunit tests/Unit/Infrastructure/IntegrationEventContractTest.php"`. Убедиться, что пустой интерфейс ошибочно принимает неполный DTO и тест падает.
- [x] Объявить читаемые свойства, обновить архитектурные fixture-интерфейсы и их DTO; не добавлять зависимости от Symfony/Doctrine.
- [x] Выполнить `make test-unit`. Ожидается PASS, включая проверки сериализации и существующие архитектурные fixtures.

## Задача 2. Разделить графы зависимостей

**Files:**

- Modify: `tools/architecture.php`, `bin/architecture`.
- Modify: `tests/Unit/Architecture/ArchitectureTest.php`.
- Create/Test: `tests/Unit/Architecture/DependencyGraphTest.php`.
- Modify: `tests/Fixtures/Architecture/Allowed/Alpha/config/dependencies.php` и `tests/Fixtures/Architecture/Forbidden/Alpha/config/dependencies.php`.
- Create: `tests/Fixtures/Architecture/EventsOnly/` и `tests/Fixtures/Architecture/SyncOnly/` с минимальными Alpha/Beta Contracts и адаптерами для проверок прав импорта.

**Interfaces:**

- Consumes: существующий builder `Closure(string $source, array $dependencies): array`.
- Produces: декларация `array{sync: list<string>, events: list<string>}` для каждого потребителя; плоская форма отклоняется.
- `sync` открывает публичные интерфейсы и DTO, `events` — публичные события и DTO; доступ только из Infrastructure/Integration потребителя.

- [x] Добавить тесты builder: `testMutualEventDependenciesAreAllowed`, `testMixedGraphIsAllowed`, `testSynchronousCycleIsRejected`, `testFlatFormatIsRejected`, `testUnknownSelfAndSharedProvidersAreRejected`.
- [x] Добавить Deptrac-проверки `testEventsPermissionDoesNotExposeSynchronousInterfaces` и `testSyncPermissionDoesNotExposePublicEvents`; обе разрешают импорт публичных DTO.
- [x] Выполнить `make composer CMD="exec -- phpunit tests/Unit/Architecture"` и подтвердить падение новых проверок на нынешнем плоском формате/общем Contracts-слое.
- [x] Разделить Contracts на слои интерфейсов, Event и Dto. Валидацию циклов применять только к `sync`; неизвестные ключи и не-list значения отклонять с понятным сообщением.
- [x] Перевести все существующие декларации и test fixtures на новую форму в том же изменении. Не добавлять fallback для старого списка.
- [x] Выполнить `make composer CMD="exec -- phpunit tests/Unit/Architecture"` и `make architecture`. Ожидается PASS, допустимые графы проходят, запрещённые импорты обнаруживаются.

## Задача 3. Согласовать инструкции

**Files:**

- Modify: `AGENTS.md`, `src/Shared/AGENTS.md`.
- Modify: `docs/ai/rules/architecture.md`, `transactional-messaging.md`, `integration-events.md`, `checks.md`, `domain-actions.md`, `module-contracts.md` в том же каталоге правил.
- Modify: `docs/ai/templates/module-AGENTS.md`, `docs/ai/map.md`.

**Interfaces:**

- Consumes: выбранная схема из спецификации и контракт задачи 1.
- Produces: единые правила `InboxBus удаляется; Outbox в Infrastructure; event.bus → async; Idempotent Consumer каждой реакции`.

- [x] Описать выбранное направление и отдельно текущее состояние кода. До этапа 4 не объявлять новую доставку реализованной.
- [x] Убрать указания, что событийная реализация должна обходиться без инфраструктурного Outbox; запрет старых прикладных Inbox/Outbox API сохранить.
- [x] Зафиксировать нейтральные контракты append(object), OutboxPublisher и consume(consumerId, MessageIdentity, callback). В них и механизмах нет IntegrationEvent, Envelope, MessageBusInterface и знания event.bus.
- [x] Указать, что validation метаданных и перевод source/eventId в namespace/message_id выполняют событийные адаптеры. Схемы и миграции Outbox/Consumer независимы.
- [x] Заменить гарантию отдельных retry/failed подписчиков общим бюджетом доставки события и транзакционной независимостью уже завершённых эффектов.
- [x] Убрать обязательный fingerprint-журнал из базового пути: каноническая дедупликация использует identity, неизменность содержимого — контракт издателя.
- [x] Описать readable-свойства вместо пустой метки, новый формат dependencies.php и stable `consumerId` вместо транспортных Inbox DTO.
- [x] Прочитать изменённые документы целиком, проверить относительные ссылки, выполнить `git diff --check`, `make lint` и сверить карту модулей.

## Готовность этапа

Новый контракт и правила графов проверяются тестами. Документы согласованы,
при этом карта честно описывает ещё работающий старый runtime.
Следующий этап — [Transactional Outbox](2026-10-26-module-events-stage-2-transactional-outbox.md).

## Результат проверки этапа

Этап прошёл ревью пользователя и закоммичен: ec57313. Полный PHPUnit: 111 тестов,
434 assertions, 2 PostgreSQL-теста пропущены без выделенной тестовой БД.
Полный запуск потребовал временного process-only memory_limit=256M вместо
128M: `make test PHP="docker compose exec php php -d memory_limit=256M"`.
Настройки окружения не изменялись.

`make lint`, `make architecture`, lint:container в test/prod и проверка Doctrine
metadata проходят. Проверены 13 Markdown-файлов и 108 относительных ссылок.
Независимое ревью завершено без оставшихся замечаний. Карта модулей сверена
с Kernel и src: бизнес-модулей нет, доставка пока использует прежний runtime.
Коммит ec57313 и переход к этапу 2 согласованы пользователем; работа продолжена на этапе 2.
