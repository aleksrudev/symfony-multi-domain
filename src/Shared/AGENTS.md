# Общая техническая основа

Применяются корневые [AGENTS.md](../../AGENTS.md). Этот файл также прочитай
при изменении тестов Shared в `tests/Unit` и `tests/Integration`.

Назначение механизмов определяют
[правила надёжной доставки](../../docs/ai/rules/transactional-messaging.md).
Shared предоставляет контракт метаданных IntegrationEvent и композицию
Dispatcher → нейтральный Outbox → Messenger event.bus → Idempotent Consumer.
Нейтральные механизмы также проверяются отдельно в OutboxKernel и
IdempotentConsumerKernel; TransactionalMessagingKernel проверяет полную цепочку.

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
технические исключения из структуры бизнес-модулей. Не восстанавливай удалённые
Inbox/Outbox API через aliases, обёртки или параллельные пути.
Совместимость архитектурных экспериментов не требуется.

services.yaml подключает узкие ресурсы сервисов без перечисления классов.
Адаптеры шин объявляют private AsAlias; MessageBusInterface внедряется по
именам аргументов шин, default Connection — по типу. HTTP listener использует
AsEventListener, расписание — AutowireIterator для поставщиков.
Messaging/IntegrationEvent содержит контракт и OutboxIntegrationEventDispatcher.
Messaging/Messenger содержит publisher с bind $messageBus: '@event.bus'. Outbox
подключает Writer/Relay/Console, Consumer — только Consumer/; Identity и DTO не сервисы.
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

Нейтральные механизмы принадлежат Infrastructure/Messaging:
OutboxWriter принимает object, relay вызывает нейтральный OutboxPublisher;
Consumer принимает consumerId, MessageIdentity(namespace, id) и callback.
Они не импортируют IntegrationEventInterface, Messenger или Envelope.
Схемы, миграции и тесты механизмов независимы; MessageIdentity — DTO, не сервис.

Outbox находится в Infrastructure/Messaging/Outbox/:
Writer/ содержит контракт записи и DoctrineOutboxWriter, Publisher/ — порт доставки,
Relay/ — OutboxRelay, Console/ — команды relay/retry.
DoctrineOutboxWriter требует активную default-транзакцию и сохраняет base64
от serialize(object); flush/commit и чтения метаданных события нет.
OutboxRelay отклоняет внешнюю транзакцию и в своей выбирает готовую запись через
`ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED`,
передаёт object через OutboxPublisherInterface и удаляет запись. `relayOne(): bool`
возвращает false, если готовых записей нет; true — если доставлена одна запись
или сохранена её неудачная попытка, а не только при успехе. Publish/delete
защищены savepoint; отказ сохраняет попытку, не снимая внешнюю блокировку.
Задержки после первых трёх отказов — 1/3/9 секунд, четвёртый помечает failed_at.
last_error и логи содержат только класс ошибки; body в логи не передаётся.
Ошибка соединения/внешнего commit выходит вызывающей стороне.

Таблица shared.outbox_messages описана отдельной миграцией
[Version20261026000000](../../migrations/Version20261026000000.php), ORM исключает её через schema_filter.
В ней только id/body/created_at/available_at/attempts/failed_at/last_error;
частичный индекс готовых записей — (available_at, id) WHERE failed_at IS NULL.
Времена хранятся в UTC с микросекундами. Изменение схемы требует проверки миграции,
ORM diff и независимых тестов; наличие миграции не означает её применения в окружении.
Правила восстановления снимка — в [Outbox](../../docs/ai/rules/transactional-messaging.md#outbox-исходящее-намерение-и-бизнесовые-данные).

app:outbox:relay и app:outbox:retry зарегистрированы в приложении.
make/Compose outbox-worker запускают app:outbox:relay; async worker — Messenger.
Relay завершается после текущей транзакции по SIGTERM/SIGINT при наличии pcntl;
расширение включено в Dockerfile. Retry меняет только одну failed-запись,
сбрасывает попытки/ошибку и сохраняет body/created_at.

Тесты Outbox: DoctrineOutboxWriterTest, TransactionalOutboxSchemaTest,
TransactionalOutboxTest, OutboxRelayTest и OutboxCommandsTest. PostgreSQL-часть
использует отдельную guarded test-БД; снимок, failed/retry, savepoint,
внешний commit, конкуренция и SIGTERM проверяются без Messenger/Consumer/событий.

Consumer находится в Infrastructure/Messaging/IdempotentConsumer/:
Identity/ содержит readonly MessageIdentity, Consumer/ — интерфейс и
DoctrineIdempotentConsumer с private AsAlias. Identity и consumerId содержат
1–190 символов UTF-8 без NUL, значения не нормализуются. Identity не сервис.

consume принимает только consumerId, identity и callable(): void. До транзакции
проверяет отсутствие внешней транзакции, открытый default EntityManager и
совпадение его Connection с подключением receipt. INSERT ON CONFLICT DO NOTHING
защищает ключ (consumer_id, namespace, message_id). Дубликат пропускает callback;
новая отметка коммитится с callback и ORM flush. После завершения/rollback
открытый UnitOfWork очищается, включая дубликат. Consumer не восстанавливает EM;
после ORM-ошибки reset принадлежит вызывающей инфраструктуре.
Flush/clear охватывает весь default UnitOfWork, а не выбранные сущности.
ORM-изменения реакции выполняй внутри callback; pending-изменений другого
сценария на входе быть не должно. Callback не управляет внешним commit/rollback,
не подменяет manager и завершает свои вложенные транзакции.

Таблица shared.processed_messages имеет отдельную миграцию
[Version20261026000100](../../migrations/Version20261026000100.php)
и исключена из ORM diff. Обе миграции независимо создают схему shared при её
отсутствии; down удаляет только собственную таблицу, а не схему или таблицу
соседнего механизма. Независимая Consumer test-БД не содержит таблиц
Outbox/Messenger; отдельная Messaging test-БД содержит все три механизма.
Receipt не содержит payload и TTL.
Тесты MessageIdentityTest, IdempotentConsumerSchemaTest, IdempotentConsumerTest,
IdempotentConsumerOrmTest проверяют дедупликацию, независимые namespaces/consumerId,
конкурирующие commit/rollback, границы SQL/ORM, отказ flush/commit и reset manager.

Событийный Dispatcher проверяет метаданные и вызывает Writer.
Отдельный Messenger publisher получает event.bus через DI.
Адаптер подписчика переводит source/eventId в identity и вызывает свой сценарий
через Consumer. Общей транзакции event.bus нет; retry/failed общие для события.
Самостоятельные очереди/Delivery на подписчика и исторический replay не вводятся.
[Реестр событий](../../docs/ai/events.md) описывает фактические бизнес-контракты;
Сам технический контракт Shared и fixtures Alpha/Beta в него не входят.

Тесты композиции: IntegrationEventDeliveryTest, PostgresIntegrationEventTest и
IdempotentConsumerOutboxTest в tests/Integration/. Они используют отдельную
MESSAGING_TEST_DATABASE_URL. Полный fixture kernel отключает статическое
подключение DAMA, чтобы observer и worker видели настоящие commit/rollback.
Проверяются source snapshot/rollback, атомарный relay → async, A-success/B-failure,
общий retry/failed, свежий Envelope, выбор handlers при получении,
receipt/эффект/следующее событие и штатный reset закрытого EM реальным consume.
Приёмка PostgresIntegrationEventTest дополнительно проверяет SIGKILL после commit
до ACK, SIGKILL relay после send до commit и конкурирующих worker с наблюдением
реальных PostgreSQL-блокировок. ProcessBarrier и процессные entrypoints находятся
в tests/Fixtures/Messaging/Integration/. После аварии тест ускоряет только истечение
lease мёртвого worker; исходное сообщение/receipt не подменяются.
Вариант FailFirstMessagingKernel меняет порядок стандартных handlers для проверки
ORM-ошибки первым. Изоляция commit сохраняется; незавершённые реакции восстанавливаются
следующей доставкой после штатного reset, без гарантии отдельного прогресса каждого handler.
Контракт метаданных проверяется tests/Unit/Infrastructure/IntegrationEventContractTest.php.
Для native compiler-проверок DTO используется отдельный PHP-процесс без
расширений (-n): отладочные расширения окружения не должны менять отказ PHP
на невалидной реализации интерфейса.

Границы и графы проверяются tests/Unit/Architecture/ArchitectureTest.php
и DependencyGraphTest.php через tools/architecture.php и bin/architecture.
Технические Alpha/Beta остаются только в tests.
PostgreSQL-проверки требуют отдельные OUTBOX_TEST_DATABASE_URL,
CONSUMER_TEST_DATABASE_URL и MESSAGING_TEST_DATABASE_URL с проверкой
суффикса test-БД; сами миграции не применяют.

## Расписание и проверки

Поставщики расписания реализуют ScheduleContributorInterface и возвращают
RecurringMessage. Домен и Application не зависят от этого Symfony-контракта.
Пустой набор поставщиков означает пустое расписание scheduler_default.

Проверки: make lint, make test, make architecture. Изменения контейнерных
расширений проверяй также компиляцией test/prod и модульными integration fixtures.
