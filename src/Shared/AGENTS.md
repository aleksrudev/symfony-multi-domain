# Общая техническая основа

Применяются корневые [AGENTS.md](../../AGENTS.md). Этот файл также прочитай
при изменении тестов Shared в `tests/Unit` и `tests/Integration`.

Shared не является бизнес-контекстом или заранее выделенным Shared Kernel.
Допустимы только независимые от предметной области механизмы, необходимые
нескольким модулям:

- Domain: маркер и интерфейс шины доменных событий, базовое доменное исключение
  и типизированная основа коллекций `Collection/AbstractCollection`,
  основы UUID/ULID `ValueObject/UuidId` и `ValueObject/UlidId`;
- Application: контракты шин команд, запросов, inbox и outbox;
- Presentation: общий HTTP listener и расширяемый реестр ошибок;
- Infrastructure: реализации шин, JSON-снимки и доставка межмодульных событий,
  сборка расписания, compiler passes;
- config: регистрация только общих сервисов.

services.yaml подключает узкие ресурсы сервисов без перечисления классов.
Адаптеры шин объявляют private AsAlias; MessageBusInterface внедряется по
именам аргументов шин, default Connection — по типу. HTTP listener использует
AsEventListener, расписание — AutowireIterator для поставщиков.
DTO и дескрипторы Messaging не попадают в ресурсы сервисов.
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
прикладные реакции выполняют Application listener. Публичные события
адаптируют синхронные инфраструктурные listener владельцев.
Следуй [правилам доменных событий](../../docs/ai/rules/domain-actions.md#доменные-события-и-реакции).

Новые модульные ошибки регистрируются провайдером владельца. Провайдер возвращает
статическую карту точных классов исключений; конфликты выявляются при компиляции.
Общий listener не раскрывает неизвестные исключения и обслуживает только /api.

OutboxBusInterface публикует только внутри активной транзакции default Doctrine
connection. Маркер OutboxMessageInterface не навязывает идентификатор сообщения: ключ
идемпотентности определяет конкретный получатель. Outbox-сообщения идут в
`outbox.bus` и Doctrine transport `outbox`, отдельно от inbox.

Для публичных межмодульных событий действует
[соглашение о контрактах и доставке](../../docs/ai/rules/integration-events.md).
Реализация находится в `Infrastructure/Messaging/`:

- `IntegrationEventPublisher::publish(object, DateTimeImmutable)` требует
  активную default-транзакцию, возвращает `IntegrationEventMetadata`, фиксирует
  JSON-снимок кодеком и отдельную `IntegrationEventEnvelope` на подписчика.
- `IntegrationEventMetadata` содержит eventId/eventAt/source/type/schemaVersion;
  envelope добавляет JSON-payload и постоянный subscriberId. Только envelope
  реализует OutboxMessageInterface. DTO и дескрипторы сервисами не регистрируются.
- `IntegrationEventCodecInterface` явно кодирует/восстанавливает публичную
  схему. `IntegrationEventRegistry` связывает логические ключи схем и
  подписчиков с сервисами; конкретные бизнес-классы Shared не импортирует.
- `Attribute/AsIntegrationEvent` применяется к инфраструктурному кодеку,
  `Attribute/AsIntegrationEventSubscriber` — к инфраструктурному адаптеру
  потребителя. `IntegrationEventPass::register()` вызывается Kernel и собирает
  регистрации с проверкой конфликтов и сигнатур при компиляции.
- `IntegrationEventHandler` регистрируется AsMessageHandler на outbox.bus.
  Его вызов восстанавливает DTO и запускает одного подписчика. Ошибки схемы
  и адресации unrecoverable; ошибки подписчика сохраняют retry-семантику.

Общих таблиц дедупликации и новых миграций нет. OutboxBusInterface и
OutboxMessageInterface сохраняют прежние контракты. В message body нет
PHP-типов бизнесовых DTO. Состав подписчиков определяется при публикации;
при отсутствии подписчиков записей очереди нет. Метаданные и JSON-снимок
сохраняются при повторной доставке, включая восстановление из failed.

Тесты: `tests/Unit/Infrastructure/Messaging/`,
`tests/Unit/Infrastructure/IntegrationEventPassTest.php`,
`tests/Integration/IntegrationEventDeliveryTest.php` и
`tests/Integration/PostgresIntegrationEventTest.php`. Технические Alpha/Beta
fixtures находятся только в tests. PostgreSQL-тест использует отдельную
OUTBOX_TEST_DATABASE_URL с проверкой суффикса test-БД; миграции сам не применяет.

Поставщики расписания реализуют ScheduleContributorInterface и возвращают
RecurringMessage. Домен и Application не зависят от этого Symfony-контракта.
Пустой набор поставщиков означает пустое расписание scheduler_default.

Проверки: make lint, make test, make architecture. Изменения контейнерных
расширений проверяй также компиляцией test/prod и модульными integration fixtures.
