# Карта модулей

Прочитай карту до внесения изменений, чтобы определить владельца кода.
Затем прочитай AGENTS.md выбранного модуля. Если изменение касается нескольких
модулей, прочитай инструкции каждого из них, включая владельцев тестов.

Все пути ниже указаны от корня проекта. Подробные правила границ и зависимостей
находятся в [rules/architecture.md](rules/architecture.md), команды проверок — в
[rules/checks.md](rules/checks.md).

## Существующие модули

| Название | Назначение | Путь | Инструкция | Подключение |
| --- | --- | --- | --- | --- |
| Shared | Общая техническая основа: CQRS, события и надёжная доставка, доменные базовые типы, HTTP-маппинг ошибок, расписание и DI. | `src/Shared/` | [src/Shared/AGENTS.md](../../src/Shared/AGENTS.md) | Подключён всегда: Kernel загружает `src/Shared/config/` отдельно от списка бизнес-модулей. |

Бизнес-модулей пока нет. В `src/Kernel.php` метод `moduleDirectories()` возвращает
пустой список; Shared подключается отдельно как техническая основа.
Примеры Alpha, Beta и RuleFixtures в `tests/Fixtures/` — технические fixtures,
а не модули приложения.

## Границы ответственности

Shared содержит только механизмы, независимые от предметной области.
Бизнесовые сущности, value objects, репозитории, правила и сценарии использования
относятся к бизнес-модулю владельца. Аутентификация в шаблоне не реализована;
Shared не является модулем пользователей или авторизации.

| Механизм Shared | Путь внутри `src/Shared/` |
| --- | --- |
| Основа типизированных коллекций | `Domain/Collection/AbstractCollection.php` |
| Основы UUID/ULID | `Domain/ValueObject/UuidId.php`, `UlidId.php` |
| Контракты Command/Query | `Application/Bus/` |
| Локальные доменные события и контракт синхронной шины | `Domain/Event/` |
| Публичный контракт метаданных IntegrationEvent | `Presentation/Contracts/Event/` |
| HTTP-маппинг ошибок | `Presentation/Http/` |
| Реализации синхронных шин | `Infrastructure/Bus/Symfony/` |
| Dispatcher публичных событий | `Infrastructure/Messaging/IntegrationEvent/` |
| Нейтральный Transactional Outbox | `Infrastructure/Messaging/Outbox/` |
| Нейтральный Idempotent Consumer | `Infrastructure/Messaging/IdempotentConsumer/` |
| Адаптер Outbox publisher для Messenger event.bus | `Infrastructure/Messaging/Messenger/` |
| Расписание и compiler passes | `Infrastructure/Schedule/`, `Infrastructure/Symfony/DependencyInjection/` |
| Регистрация общих сервисов | [config/services.yaml](../../src/Shared/config/services.yaml) |

Инварианты реализаций и принадлежащие им тесты описаны в
[инструкции Shared](../../src/Shared/AGENTS.md). Назначение механизмов и
эксплуатация — в [правилах надёжной доставки](rules/transactional-messaging.md),
контракт и композиция событий — в [правилах межмодульных событий](rules/integration-events.md).
Нейтральные OutboxKernel и IdempotentConsumerKernel проверяют механизмы отдельно;
TransactionalMessagingKernel — полную цепочку. Требования к раздельным PostgreSQL
test-БД и их подготовке — в [правилах проверок](rules/checks.md#межмодульные-события).

[Реестр событий и подписчиков](events.md) пуст: публичных бизнесовых событий
пока нет. Перечни фактов и подписок ведутся только в реестре, вместе с кодом;
технические Alpha/Beta в него не входят.

## Связи модулей

Межмодульных бизнес-связей пока нет: бизнес-модули и их публичные Contracts
отсутствуют. Shared не зависит от бизнес-модулей и их Contracts.

Бизнес-модули смогут использовать технические контракты Shared и расширять
общие механизмы через провайдеры HTTP-ошибок и поставщиков расписания.
Их регистрацию и загрузку конфигурации собирает
[src/Kernel.php](../../src/Kernel.php).

При изменении связей указывай здесь потребителя, поставщика и назначение
связи. Разрешение зависимости проверяй по `src/<Consumer>/config/dependencies.php`,
а конкретный публичный контракт — по AGENTS.md поставщика.
Формат sync/events и ограничения графов описаны в
[архитектуре](rules/architecture.md#межмодульные-зависимости).
Подписки перечисляй в [реестре](events.md), здесь оставляй только назначение связи.

## Куда класть код

Сначала выбери модуль по его ответственности, затем слой внутри него:

| Что добавляется | Куда класть |
| --- | --- |
| Агрегат, сущность, бизнесовый value object или исключение, интерфейс репозитория агрегата | `src/<Module>/Domain/` |
| Доменный Action с бизнес-операцией | `src/<Module>/Domain/Action/` |
| Доменное событие, публикуемое Action | `src/<Module>/Domain/Event/`; [правила](rules/domain-actions.md#доменные-события-и-реакции) |
| Доменный интерфейс поиска сущностей | `src/<Module>/Domain/DataProvider/` |
| Типизированная или пагинированная коллекция сущностей | `src/<Module>/Domain/Collection/` |
| Command/Query-сообщение и его обработчик, оркестрирующий сценарий бизнес-модуля | `src/<Module>/Application/Command/<UseCase>/` или `src/<Module>/Application/Query/<UseCase>/` |
| Дополнительные входные DTO сценария | `src/<Module>/Application/Command/<UseCase>/Input/` или `src/<Module>/Application/Query/<UseCase>/Input/` |
| Главная и вложенные модели результата Command/Query-сценария | `src/<Module>/Application/Command/<UseCase>/Result/` или `src/<Module>/Application/Query/<UseCase>/Result/`; [структура сценария](rules/domain-actions.md#структура-commandquery-сценария) |
| Listener адаптации собственного доменного факта | `src/<Module>/Infrastructure/Messaging/EventListener/` |
| Технические Inbox-типы конкретной внешней интеграции | `src/<Module>/Infrastructure/Messaging/Inbox/<Operation>/`; [назначение](rules/transactional-messaging.md) |
| Технические Outbox-типы конкретной внешней интеграции | `src/<Module>/Infrastructure/Messaging/Outbox/<Operation>/`; [назначение](rules/transactional-messaging.md) |
| Синхронная адаптация собственного доменного события в публичный факт | `src/<Module>/Infrastructure/Messaging/EventListener/`; [правила](rules/integration-events.md) |
| Прикладной провайдер внешних или межмодульных данных и его собственная модель результата | `src/<Module>/Application/Command/<UseCase>/Provider/` или `src/<Module>/Application/Query/<UseCase>/Provider/`; [правила](rules/domain-actions.md#прикладные-провайдеры) |
| Публичный межмодульный интерфейс | `src/<Module>/Presentation/Contracts/` |
| Неизменяемый DTO публичного межмодульного API | `src/<Module>/Presentation/Contracts/Dto/` |
| Публичное межмодульное событие | `src/<Module>/Presentation/Contracts/Event/`; [правила](rules/integration-events.md) |
| Реализация публичного интерфейса через Command/Query владельца | `src/<Module>/Presentation/Adapter/` |
| HTTP-контроллер, Request/Response DTO, CLI, адаптация входящего внешнего сообщения | `src/<Module>/Presentation/` |
| Типизированный DTO входа HTTP с ограничениями Assert для MapRequestPayload/MapQueryString | `src/<Module>/Presentation/Http/Request/` |
| API-контроллер одного действия с `__invoke()` | `src/<Module>/Presentation/Http/Controller/Api/<Actor>/` |
| Реализация репозитория, DataProvider или другого порта, внешний адаптер | `src/<Module>/Infrastructure/` |
| Doctrine-фикстуры начальных dev/test-данных | `src/<Module>/Infrastructure/Persistence/Doctrine/Fixtures/` |
| Адаптер чужого публичного события в собственный прикладной сценарий | `src/<Module>/Infrastructure/Integration/<Owner>/`; [правила](rules/integration-events.md) |
| Адаптер к Contracts другого модуля | `src/<Module>/Infrastructure/Integration/` потребителя |
| Сервисы, маршруты, mappings и настройки интеграций модуля | `src/<Module>/config/` |

В Application бизнес-модуля разрешены только Command/ и Query/ с группировкой
типов по сценарию. Application/Message, Application/EventListener и
Application/Service запрещены. Inbox/Outbox группируются по операциям;
файлы непосредственно в этих каталогах не размещаются.
Shared/Application/Bus и общая доставка в Shared/Infrastructure/Messaging
сохраняют техническую структуру Shared.

Общий технический механизм для нескольких модулей относится к `src/Shared/`,
если соответствует его инструкции. Бизнесовый код относится к модулю владельца;
если подходящего модуля нет, определи новый bounded context по
[руководству](rules/architecture.md) и создай его инструкцию по
[шаблону](templates/module-AGENTS.md).

Общая загрузка модулей находится в `src/Kernel.php`, общие настройки — в `config/`.
Тесты находятся в `tests/Unit/`, `tests/Integration/` и `tests/Functional/`;
их владельца и команды целевого запуска указывают в AGENTS.md модуля.
Технические fixtures находятся в `tests/Fixtures/`.

## Актуальность карты

После завершения работы над каждой задачей, до итогового ответа, сверяй карту
с фактическим состоянием проекта и актуализируй её по результатам работы.
Проверяй состав модулей, их назначение, границы ответственности, связи, пути
к коду и инструкциям, статус подключения и рекомендации по размещению кода.

При добавлении, удалении или переименовании модуля, изменении его назначения
или пути к AGENTS.md обновляй карту в том же изменении. Также обновляй карту
при изменении границ ответственности, межмодульных связей или подключения в Kernel.
Для каждого модуля указывай название, краткое назначение, путь к коду,
ссылку на его AGENTS.md и статус подключения.
Учитывай существующие модули, даже если они отключены в Kernel.

Детальные инварианты, контракты, зависимости и команды тестов остаются
в AGENTS.md владельца. Карта помогает найти владельца и его инструкцию.
