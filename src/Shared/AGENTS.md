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
- Presentation: пустая публичная метка IntegrationEventInterface, общий HTTP listener
  и расширяемый реестр ошибок;
- Infrastructure: реализации шин, доставка межмодульных событий через Outbox →
  EventDispatcher → Inbox,
  сборка расписания, compiler passes;
- config: регистрация только общих сервисов.

Shared/Application/Bus и общая доставка в Shared/Infrastructure/Messaging —
технические исключения из структуры бизнес-модулей. IntegrationEventMessage
и IntegrationEventHandler сохраняют своё расположение; группировка операций
Inbox/Outbox бизнес-модулей не требует переносить эти общие классы.

services.yaml подключает узкие ресурсы сервисов без перечисления классов.
Адаптеры шин объявляют private AsAlias; MessageBusInterface внедряется по
именам аргументов шин, default Connection — по типу. HTTP listener использует
AsEventListener, расписание — AutowireIterator для поставщиков.
Messaging подключает только `*Handler.php`; DTO и метка не сервисы.
Следуй общим [правилам регистрации](../../docs/ai/rules/architecture.md#регистрация-сервисов-и-атрибуты).

В демо-ветке `Presentation/Http/` также содержит технические адаптеры сессионного
входа: AuthController, CsrfProtectionListener, LoginFailureListener и LogoutListener.
Контроллер и request-listener имеют необходимую явную регистрацию в services.yaml;
subscriber реализуют EventSubscriberInterface и подключаются через autoconfigure.
Конфигурация безопасности и маршруты остаются общими; бизнес-моделей пользователей
в Shared нет.

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

OutboxBusInterface публикует только внутри активной транзакции default Doctrine
connection. Маркер OutboxMessageInterface не навязывает идентификатор сообщения: ключ
идемпотентности определяет конкретный получатель. Outbox-сообщения идут в
`outbox.bus` и Doctrine transport `outbox`, отдельно от inbox.

Для публичных межмодульных событий действует
[соглашение о контрактах и доставке](../../docs/ai/rules/integration-events.md).
Реализация находится в `Infrastructure/Messaging/`:

- `IntegrationEventMessage` — readonly-обёртка с типизированным полем event,
  реализующая OutboxMessageInterface. Метаданные формирует владелец в публичном DTO.
  Инфраструктурный listener владельца напрямую вызывает
  `OutboxBusInterface::publish(new IntegrationEventMessage($publicEvent))`.
  Проверку активной default-транзакции выполняет OutboxMessageBus.
- `IntegrationEventHandler` помечен AsMessageHandler на outbox.bus и передаёт
  сохранённый event в штатный Symfony Component EventDispatcherInterface.
  Набор listener определяется при доставке. Отсутствие listener допустимо.
- Listener потребителей с AsEventListener только ставят собственные сообщения
  в inbox.bus. Doctrine async, как и outbox, использует default connection:
  все записи inbox в одной доставке коммитятся или откатываются вместе.
  Инфраструктурные Inbox handlers бизнес-модулей позже вызывают собственные
  Command/Query в отдельных транзакциях обработки сообщений.

`Presentation/Contracts/Event/IntegrationEventInterface` не содержит методов
и не зависит от фреймворка. Это точечное исключение для зависимости публичных
Contracts от Shared. Domain и Application не используют эту метку; маркеры
доменного события, Outbox и Inbox остаются отдельными типами.
Shared не импортирует бизнесовые классы и не содержит общего реестра,
кодеков, специальных атрибутов или общей таблицы дедупликации.

Штатный PHP-сериализатор Messenger сохраняет публичный DTO и вложенные DTO,
включая их PHP-типы. eventId, eventAt и снимок сохраняются при повторах.
При смене формата со старого JSON/subscriberId envelope сначала обработай
pending/failed старой версией, останови старых worker, затем переключи код
и перезапусти worker. Очереди не очищай; старый формат автоматически не читается.
Повтор fanout после commit → ack может создать повторные inbox-сообщения;
идемпотентность обеспечивает каждый бизнесовый получатель.

Тесты: `tests/Integration/IntegrationEventDeliveryTest.php`,
`tests/Integration/PostgresIntegrationEventTest.php` и архитектурные fixtures
в `tests/Unit/Architecture/ArchitectureTest.php`. Технические Alpha/Beta
находятся только в tests. PostgreSQL-тест использует отдельную
OUTBOX_TEST_DATABASE_URL с проверкой суффикса test-БД; миграции сам не применяет.

Поставщики расписания реализуют ScheduleContributorInterface и возвращают
RecurringMessage. Домен и Application не зависят от этого Symfony-контракта.
Пустой набор поставщиков означает пустое расписание scheduler_default.

Проверки: make lint, make test, make architecture. Изменения контейнерных
расширений проверяй также компиляцией test/prod и модульными integration fixtures.
