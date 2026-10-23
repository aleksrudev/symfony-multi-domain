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
| Shared | Общая техническая основа: шины, доменные события, публичная метка событий и доставка через Outbox → EventDispatcher → Inbox, базовые исключение и коллекция, основы UUID/ULID, HTTP-маппинг ошибок, демо-вход и сессионный доступ, расписание и DI-механизмы. | `src/Shared/` | [src/Shared/AGENTS.md](../../src/Shared/AGENTS.md) | Подключён всегда: Kernel загружает `src/Shared/config/` отдельно от списка бизнес-модулей. |
| Catalog | Витрина и карточки товаров, актуальные цены в копейках и публикация, публичный контракт `GetOffer` для оформления заказов. | `src/Catalog/` | [src/Catalog/AGENTS.md](../../src/Catalog/AGENTS.md) | Подключён в `Kernel::moduleDirectories()`, собственная PostgreSQL-схема `catalog`. |
| Inventory | Складской учёт физических остатков (`onHand`), резервов (`reserved`) и доступности (`available`), изменение остатков администратором, демо-фикстуры остатков. | `src/Inventory/` | [src/Inventory/AGENTS.md](../../src/Inventory/AGENTS.md) | Подключён в `Kernel::moduleDirectories()`, собственная PostgreSQL-схема `inventory`. |
| Ordering | Жизненный цикл заказов покупателей: черновик заказа, редактирование позиций, владение заказом, идемпотентность и оформление с фиксацией цен и ожиданием проверки наличия. | `src/Ordering/` | [src/Ordering/AGENTS.md](../../src/Ordering/AGENTS.md) | Подключён в `Kernel::moduleDirectories()`, собственная PostgreSQL-схема `ordering`. |

Примеры Alpha, Beta и RuleFixtures в `tests/Fixtures/` — технические fixtures,
а не модули приложения.

## Границы ответственности

Shared содержит только механизмы, независимые от предметной области.
Бизнесовые сущности, value objects, репозитории, правила и сценарии использования
относятся к бизнес-модулю владельца. Демонстрационный вход и сессионный доступ
реализованы через общую конфигурацию безопасности и технические HTTP-адаптеры
в `src/Shared/Presentation/Http/` (сессия, CSRF, login/logout, throttling); Shared
не является модулем пользователей и не содержит их бизнес-моделей.

`Catalog` владеет товарами (`Product`), их наименованием, ценой целым числом копеек (RUB)
и признаком публикации. Не владеет складскими остатками (владеет `Inventory`) и историческими
снимками позиций в заказах (владеет `Ordering`).

`Inventory` владеет складскими позициями (`StockPosition`), физическими остатками (`onHand`),
резервами (`reserved`) и доступным для заказа остатком (`available`). Не владеет карточками товаров
и витринными ценами (владеет `Catalog`), корзинами и оформлением заказов (владеет `Ordering`).

`Ordering` владеет заказами (`Order`), их статусами, позициями (`OrderItem`), снимками
наименований и цен товаров, количествами, черновиком покупателя и идемпотентностью команд.
Не владеет каталожной витриной и актуальными ценами (владеет `Catalog`), остатками на складе
(владеет `Inventory`) и оплатой (владеет `Payment`).

Общая типизированная основа коллекций находится в
`src/Shared/Domain/Collection/AbstractCollection.php`; коллекции конкретных
сущностей принадлежат бизнес-модулям и наследуются от неё.

Общие основы UUID/ULID находятся в `src/Shared/Domain/ValueObject/UuidId.php`
и `UlidId.php`; конкретные бизнесовые ID принадлежат модулям и наследуют
соответствующую основу. Правила использования — в
[rules/domain-types.md](rules/domain-types.md).

Контракты шин Command, Query, Inbox и Outbox находятся в `src/Shared/Application/Bus/`,
контракт доменной шины событий и маркер события — в `src/Shared/Domain/Event/`.
HTTP-маппинг ошибок — в `src/Shared/Presentation/Http/`,
реализация шин, сборка расписания и compiler passes —
в `src/Shared/Infrastructure/`. Регистрация общих сервисов находится
в [src/Shared/config/services.yaml](../../src/Shared/config/services.yaml).
Файл подключает узкие ресурсы; aliases шин, HTTP listener и набор поставщиков
расписания настраиваются атрибутами сервисов. Конкретные классы без необходимого
переопределения в YAML не перечисляются.

Пустая публичная метка `IntegrationEventInterface` находится в
`src/Shared/Presentation/Contracts/Event/`. Общая `IntegrationEventMessage`
и `IntegrationEventHandler` находятся в `src/Shared/Infrastructure/Messaging/`.
Инфраструктурный listener владельца отправляет обёртку напрямую через
OutboxBusInterface. Handler использует штатный Symfony EventDispatcher; listener потребителей
ставят собственные сообщения через InboxBusInterface.
Outbox и Inbox используют Doctrine transport на одном default connection.
Собственного реестра, кодеков или compiler pass межмодульных событий нет.
Семантика публикации и подключения — в
[правилах межмодульных событий](rules/integration-events.md).

## Связи модулей

`Catalog` экспортирует публичный межмодульный контракт `App\Catalog\Presentation\Contracts\CatalogContractInterface`
(метод `getOffer(productId): ?Offer`). Поставщик: `Catalog`. Потребитель: модуль `Ordering`
(при оформлении заказа в задаче `T05`) через собственный адаптер `Ordering/Infrastructure/Integration/Catalog/CatalogOfferProvider.php`.
Сам `Catalog` не зависит от других бизнес-модулей (`Catalog/config/dependencies.php` пуст).
`Inventory` экспортирует публичный контракт `App\Inventory\Presentation\Contracts\InventoryContractInterface`
(методы `requestReservation`, `requestRelease`, `getReservationResult`). Поставщик: `Inventory`. Потребитель: модуль `Ordering`
через адаптер `Ordering/Infrastructure/Integration/Inventory/OrderSubmittedInventoryListener.php`.
Сам `Inventory` не зависит от других бизнес-модулей (`Inventory/config/dependencies.php` пуст).
Модуль `Ordering` зависит от `Catalog` и `Inventory` (`Ordering/config/dependencies.php` возвращает `['Catalog', 'Inventory']`).
Shared не зависит от бизнес-модулей и их Contracts.

`Ordering` экспортирует публичное межмодульное событие `App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent`
(реализует Shared `IntegrationEventInterface`), формируемое и публикуемое через `IntegrationEventMessage`
в `outbox.bus` инфраструктурным listener при переводе заказа в `pending_stock`. При доставке через Outbox
событие слушает адаптер `OrderSubmittedInventoryListener` в `Ordering`, который вызывает контракт склада `requestReservation()`.

Бизнес-модули смогут использовать технические контракты Shared и расширять
общие механизмы через провайдеры HTTP-ошибок и поставщиков расписания.
Их регистрацию и загрузку конфигурации собирает
[src/Kernel.php](../../src/Kernel.php).

При изменении связей указывай здесь потребителя, поставщика и назначение
связи. Разрешение зависимости проверяй по `src/<Consumer>/config/dependencies.php`,
а конкретный публичный контракт — по AGENTS.md поставщика.

## Куда класть код

Сначала выбери модуль по его ответственности, затем слой внутри него:

| Что добавляется | Куда класть |
| --- | --- |
| Агрегат, сущность, бизнесовый value object или исключение, интерфейс репозитория агрегата | `src/<Module>/Domain/` |
| Доменный Action с бизнес-операцией | `src/<Module>/Domain/Action/` |
| Доменное событие, публикуемое Action | `src/<Module>/Domain/Event/`; [правила](rules/domain-actions.md#доменные-события-и-реакции) |
| Доменный интерфейс поиска сущностей | `src/<Module>/Domain/DataProvider/` |
| Типизированная или пагинированная коллекция сущностей | `src/<Module>/Domain/Collection/` |
| Оркестрация доменных операций, Command/Query-сообщения, обработчики и модели результатов сценариев бизнес-модуля | `src/<Module>/Application/Command/<UseCase>/` или `src/<Module>/Application/Query/<UseCase>/` |
| Listener собственного доменного события: вызов прикладного сценария или запись outbox | `src/<Module>/Infrastructure/Messaging/EventListener/` |
| Внутреннее Inbox-сообщение, handler и вспомогательные DTO операции | `src/<Module>/Infrastructure/Messaging/Inbox/<Operation>/` |
| Внутреннее Outbox-сообщение, handler и вспомогательные DTO операции | `src/<Module>/Infrastructure/Messaging/Outbox/<Operation>/` |
| Синхронная адаптация собственного доменного события в публичное и запись outbox | `src/<Module>/Infrastructure/Messaging/EventListener/`; [правила](rules/integration-events.md) |
| Прикладной провайдер внешних или межмодульных данных и его собственная модель результата | `src/<Module>/Application/Command/<UseCase>/Provider/` или `src/<Module>/Application/Query/<UseCase>/Provider/`; [правила](rules/domain-actions.md#прикладные-провайдеры) |
| Модель результата Query `<QueryName>Result` для сущности, списка или составных данных | `src/<Module>/Application/Query/<UseCase>/` рядом с запросом и обработчиком |
| Публичный межмодульный интерфейс | `src/<Module>/Presentation/Contracts/` |
| Неизменяемый DTO публичного межмодульного API | `src/<Module>/Presentation/Contracts/Dto/` |
| Публичное межмодульное событие | `src/<Module>/Presentation/Contracts/Event/`; [правила](rules/integration-events.md) |
| Реализация публичного интерфейса через Command/Query владельца | `src/<Module>/Presentation/Adapter/` |
| HTTP-контроллер, Request/Response DTO, CLI, адаптация входящего внешнего сообщения | `src/<Module>/Presentation/` |
| Типизированный DTO входа HTTP с ограничениями Assert для MapRequestPayload/MapQueryString | `src/<Module>/Presentation/Http/Request/` |
| API-контроллер одного действия с `__invoke()` | `src/<Module>/Presentation/Http/Controller/Api/<Actor>/` |
| Реализация репозитория, DataProvider или другого порта, внешний адаптер | `src/<Module>/Infrastructure/` |
| Doctrine-фикстуры начальных dev/test-данных | `src/<Module>/Infrastructure/Persistence/Doctrine/Fixtures/` |
| Listener преобразования чужого публичного события в собственное Inbox-сообщение | `src/<Module>/Infrastructure/Integration/<Owner>/`; [правила](rules/integration-events.md) |
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
