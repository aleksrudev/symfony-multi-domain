# Общая техническая основа

Применяются корневые [AGENTS.md](../../AGENTS.md). Этот файл также прочитай
при изменении тестов Shared в `tests/Unit` и `tests/Integration`.

Назначение механизмов определяют
[правила надёжной доставки](../../docs/ai/rules/transactional-messaging.md).
В main реализованы контракт метаданных IntegrationEvent и анализ sync/events.
OutboxWriter, Idempotent Consumer, IntegrationEventDispatcher и event.bus пока
не реализованы; их наличие в планах не означает существующий runtime.
Текущие Application Inbox/Outbox и native fanout остаются до единого переключения
на этапе 4, затем удаляются без совместимости.

Допустимы только независимые от предметной области механизмы, необходимые
нескольким модулям:

- Domain: маркер и интерфейс шины доменных событий, базовое доменное исключение
  и типизированная основа коллекций `Collection/AbstractCollection`,
  основы UUID/ULID `ValueObject/UuidId` и `ValueObject/UlidId`;
- Application: контракты шин команд и запросов;
- Presentation: публичный контракт метаданных IntegrationEventInterface, общий HTTP listener
  и расширяемый реестр ошибок;
- Infrastructure: реализации шин, доставка межмодульных событий,
  сборка расписания, compiler passes;
- config: регистрация только общих сервисов.

Shared/Application/Bus и общая доставка в Shared/Infrastructure/Messaging —
технические исключения из структуры бизнес-модулей. Текущие Inbox/Outbox
контракты и цепочка IntegrationEventMessage/IntegrationEventHandler заменяются
вместе с конфигурацией и fixtures. Не сохраняй их через aliases, адаптеры
или параллельные пути. Совместимость архитектурных экспериментов не требуется.

services.yaml подключает узкие ресурсы сервисов без перечисления классов.
Адаптеры шин объявляют private AsAlias; MessageBusInterface внедряется по
именам аргументов шин, default Connection — по типу. HTTP listener использует
AsEventListener, расписание — AutowireIterator для поставщиков.
Messaging подключает только `*Handler.php`; DTO и метка не сервисы.
Следуй общим [правилам регистрации](../../docs/ai/rules/architecture.md#регистрация-сервисов-и-атрибуты).

Зависимости от бизнес-модулей, их Contracts и тестовых fixtures запрещены.
Не добавляй общие Entity, Customer, Money, бизнесовые идентификаторы или
универсальные Repository. Совпадение структуры двух типов не означает
совпадение их бизнес-смысла.

`Domain/Collection/AbstractCollection<T>` — единственное место прямого наследования
от Doctrine `ArrayCollection`. Конкретные коллекции принадлежат бизнес-модулям
и задают тип через `elementClass(): class-string<T>`. База проверяет элементы
в final-конструкторе, `add()` и `set()`; запись через `[]` делегируется этим методам.
Унаследованные операции создают результат через тот же конструктор, сохраняя
проверку типа. `all()` возвращает список без исходных ключей. Не добавляй
в основу бизнес-методы и не регистрируй коллекции как сервисы.
Регрессионные тесты базы находятся в `tests/Unit/Domain/AbstractCollectionTest.php`.

`Domain/ValueObject/UuidId` и `UlidId` — абстрактные readonly-обёртки над
`symfony/uid`. Только эти два доменных класса напрямую используют Symfony UID.
Конкретные бизнесовые ID остаются final readonly-наследниками в своих модулях.
Final-конструктор без аргумента (или с `null`) генерирует UUIDv4 / ULID; строковый
аргумент проверяется и нормализуется без генерации. UUID хранится в канонической
строке с дефисами в нижнем регистре, ULID — в 26-символьной строке в верхнем.
Существующие UUID не ограничены версией v4, но должны иметь стандартный вариант.
Пустая и некорректная строка отклоняются с `InvalidArgumentException`.
Публичное неизменяемое свойство `value` и `__toString()` возвращают строку;
`equals()` сравнивает конкретный класс и нормализованное значение.
Не добавляй фабрики `generate()`, собственные алгоритмы генерации или регистрацию
ID как сервисов. Регрессионные тесты — `tests/Unit/Domain/UidIdTest.php`.

`Domain/Event/EventBusInterface` принимает только `EventInterface`; реализация
через Symfony находится в Infrastructure. Шина выполняет обработчики синхронно
до commit внутри контекста владельца. Событие доменной операции создаёт
и публикует выполняющий её Action; сущностям шину не внедряй. Внутренние
прикладные реакции адаптируют инфраструктурные listener владельцев,
вызывающие собственные Command/Query либо записывающие outbox.
Публичные события адаптируют синхронные инфраструктурные listener владельцев.
Следуй [правилам доменных событий](../../docs/ai/rules/domain-actions.md#доменные-события-и-реакции).

Новые модульные ошибки регистрируются провайдером владельца. Провайдер возвращает
статическую карту точных классов исключений; конфликты выявляются при компиляции.
Общий listener не раскрывает неизвестные исключения и обслуживает только /api.

## Событийная инфраструктура

Правила контрактов и композиции — в
[межмодульных событиях](../../docs/ai/rules/integration-events.md).

IntegrationEventInterface в Presentation/Contracts/Event объявляет читаемые
свойства eventId/eventAt/source/type типа string и schemaVersion типа int.
Его реализуют public readonly-свойства публичного DTO. Контракт зависит только
от PHP. Это точечное исключение Shared для публичных Event-контрактов;
Domain/Application его не используют.

Доставка пока прежняя: IntegrationEventMessage реализует старый Outbox marker,
общий handler вызывает native EventDispatcher, получатели ставят Inbox DTO.
InboxBus не реализует Transactional Inbox. Не расширять этот путь для новых
интеграций; он удаляется на этапе подключения новой композиции.

Целевые нейтральные механизмы принадлежат Infrastructure/Messaging:
OutboxWriter принимает object, relay вызывает нейтральный OutboxPublisher;
Consumer принимает consumerId, MessageIdentity(namespace, id) и callback.
Они не импортируют IntegrationEventInterface, Messenger или Envelope.
Схемы, миграции и тесты механизмов независимы; MessageIdentity — DTO, не сервис.

Событийный Dispatcher проверяет метаданные и вызывает Writer.
Отдельный Messenger publisher получает event.bus через DI.
Адаптер подписчика переводит source/eventId в identity и вызывает свой сценарий
через Consumer. Общей транзакции event.bus нет; retry/failed общие для события.
Самостоятельные очереди/Delivery на подписчика и исторический replay не вводятся.

Текущие тесты доставки: tests/Integration/IntegrationEventDeliveryTest.php,
PostgresIntegrationEventTest.php, OutboxBusTest.php и PostgresOutboxTest.php
в том же каталоге. Они проверяют прежний runtime и будут переписаны/заменены
при переключении; прежний API ради них не сохраняется.
Контракт метаданных проверяется tests/Unit/Infrastructure/IntegrationEventContractTest.php.
Для native compiler-проверок DTO используется отдельный PHP-процесс без
расширений (-n): отладочные расширения окружения не должны менять отказ PHP
на невалидной реализации интерфейса.

Границы и графы проверяются tests/Unit/Architecture/ArchitectureTest.php
и DependencyGraphTest.php через tools/architecture.php и bin/architecture.
Технические Alpha/Beta остаются только в tests.
PostgreSQL-проверки требуют отдельную OUTBOX_TEST_DATABASE_URL с проверкой
суффикса test-БД; сами миграции не применяют.

## Расписание и проверки

Поставщики расписания реализуют ScheduleContributorInterface и возвращают
RecurringMessage. Домен и Application не зависят от этого Symfony-контракта.
Пустой набор поставщиков означает пустое расписание scheduler_default.

Проверки: make lint, make test, make architecture. Изменения контейнерных
расширений проверяй также компиляцией test/prod и модульными integration fixtures.
