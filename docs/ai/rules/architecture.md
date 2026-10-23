# Архитектура

Прочитай перед созданием, изменением или ревью модулей, зависимостей слоёв,
публичных контрактов и конфигурации подключения модулей.

Основа шаблона — Kernel и Shared. Добавленные бизнес-модули, демо-функции
и статус аутентификации перечислены в карте проекта. Стек: Symfony 8, PHP >= 8.4.17,
Doctrine ORM 3, PostgreSQL 16, Messenger, Scheduler, FrankenPHP.

Существующие модули, их назначение и инструкции перечислены в [карте](../map.md).

## Границы и структура

Каждый `src/<Module>` — bounded context с собственным языком, моделью и данными:

```text
src/<Module>/
├── AGENTS.md
├── Domain/
│   ├── Entity/
│   ├── Action/
│   ├── ValueObject/
│   ├── Repository/
│   ├── DataProvider/
│   ├── Collection/
│   └── Event/
├── Application/
│   ├── Command/
│   └── Query/
├── Presentation/
│   ├── Contracts/
│   │   ├── Dto/
│   │   └── Event/
│   ├── Adapter/
│   ├── Http/Controller/Api/<Actor>/
│   ├── Http/Request/
│   ├── Http/Response/
│   ├── Console/
│   └── Messaging/
├── Infrastructure/
│   ├── Persistence/Doctrine/
│   │   └── Fixtures/
│   ├── Integration/
│   ├── Messaging/
│   │   ├── EventListener/
│   │   ├── Inbox/<Operation>/
│   │   └── Outbox/<Operation>/
│   └── Symfony/DependencyInjection/
└── config/
    ├── services.yaml
    ├── routes.yaml
    ├── dependencies.php
    └── packages/
```

Создавайте каталоги по необходимости. В Application бизнес-модуля разрешены
только Command/ и Query/. Сообщение, обработчик, входные DTO, модели результатов
и необходимые прикладные порты группируются внутри соответствующего сценария.
Не создавай Application/Message, Application/EventListener, Application/Service
и другие каталоги верхнего уровня в Application бизнес-модуля.
Listener собственных доменных событий находятся в Infrastructure/Messaging/EventListener/.
Inbox/Outbox-сообщения, их обработчики и вспомогательные DTO группируются
по операции в `Infrastructure/Messaging/Inbox/<Operation>/` или
`Infrastructure/Messaging/Outbox/<Operation>/`. Файлы непосредственно в Inbox/
и Outbox/ запрещены. Обработчик бизнесового продолжения преобразует сообщение
в собственный Command/Query и вызывает соответствующую шину; оркестрация
остаётся в Application, бизнесовые решения — в Domain.
Listener публикации публичного события находится в Infrastructure/Messaging/EventListener/,
само событие — в Presentation/Contracts/Event/. Подробности — в
[правилах межмодульных событий](integration-events.md).
Интерфейсы получения внешних или межмодульных данных и их собственные модели
результатов находятся в подкаталоге `<UseCase>/Provider/`; правила размещения
и именования описаны в [правилах прикладных провайдеров](domain-actions.md#прикладные-провайдеры).
Контракты шин Command, Query, Inbox и Outbox находятся в Shared/Application/Bus;
интерфейс шины доменных событий — в Shared/Domain/Event рядом с EventInterface.
Результаты Query для сущностей, списков и составных данных оформляются моделями
`<QueryName>Result` рядом с запросом и обработчиком. Application преобразует
доменные сущности в эти модели, не раскрывая их через результат сценария.
Command/Query-обработчики оркестрируют доменные операции и их результаты;
бизнес-правила реализуют доменные Action, агрегаты и value objects.
Перед работой с ними прочитай [правила доменных Action](domain-actions.md).

| Слой | Ответственность |
| --- | --- |
| Domain | Агрегаты, сущности, Action с бизнес-операциями, value objects, коллекции сущностей, доменные события и исключения, интерфейсы репозиториев и DataProvider. |
| Application | Только Command/Query-сценарии как оркестраторы доменного слоя, их входные DTO, модели результата и прикладные порты внутри сценария. |
| Presentation/Contracts | Публичный межмодульный API: интерфейсы, неизменяемые DTO в Dto/ и события в Event/. |
| Presentation | Реализации публичных интерфейсов в Adapter/, HTTP-контроллеры и Request/Response DTO, CLI, адаптация входящих внешних сообщений. |
| Infrastructure | Doctrine-репозитории, внешние и межмодульные адаптеры, listener событий, транспортные Inbox/Outbox-сообщения и их обработчики. |
| config | Сервисы, маршруты, mappings и настройки интеграций владельца. |

Зависимости: `Presentation -> Application -> Domain`. Infrastructure реализует
порты Application/Domain. Сборка компонентов относится к конфигурации и DI-коду.
Application не зависит от адаптеров. Контроллеры не вызывают репозитории.
Контроллеры также не вызывают DataProvider; доступ к данным идёт через Application.
API-контроллер выполняет одно действие через `__invoke()` и размещается
в namespace актора по [правилам HTTP-контроллеров](http-controllers.md).

Presentation — входные адаптеры. Infrastructure — реализации исходящих портов.
Domain не зависит от Symfony, других модулей и адаптеров, кроме явно указанных
технических исключений ниже.
Интерфейс репозитория агрегата находится в Domain/Repository, интерфейс поиска
сущностей — в Domain/DataProvider. Их разделение, результаты поиска и коллекции
описаны в [правилах доступа к данным](data-access.md); прочитай их перед работой
с этими интерфейсами и их реализациями.

Прикладные порты внешних возможностей находятся внутри Command/Query-сценариев
Application потребителя. Application может использовать
Validator Constraints и AsMessageHandler для Command/Query с явной шиной.
Это узкое исключение для метаданных: прямые зависимости Application от Symfony
MessageBusInterface, EventDispatcher и остальных сервисов фреймворка запрещены.
Listener с AsEventListener размещаются во внешних слоях, вне Application.
Domain использует PHP и Doctrine-атрибуты маппинга сущностей.
Дополнительное исключение — `Shared/Domain/Collection/AbstractCollection`,
наследующий Doctrine `ArrayCollection`. Коллекции сущностей наследуют эту основу;
прямые импорты Doctrine Collections в остальных доменных классах запрещены.
Это исключение не разрешает зависимости Domain от ORM-сервисов или других
адаптеров.

Ещё одно исключение — `Shared/Domain/ValueObject/UuidId` и `UlidId`, которые
используют `symfony/uid` для генерации, проверки и нормализации идентификаторов.
Это абстрактные readonly-обёртки; конкретные бизнесовые ID наследуют их в своём
модуле по [правилам доменных типов](domain-types.md). Прямые зависимости
остальных доменных классов от Symfony UID запрещены. Исключение не разрешает
другие компоненты Symfony в основах ID и не меняет остальные границы слоёв.

Presentation/Contracts — опубликованный межмодульный API, независимый
от Symfony/Doctrine. Он не раскрывает сущности и не зависит от внутренних слоёв.
Публичные события реализуют только точную Shared Presentation-метку
`IntegrationEventInterface` из `Shared/Presentation/Contracts/Event/`.
Это исключение не разрешает другие Shared Contracts, транспортные маркеры
или зависимости Domain/Application от интеграционной метки.
Реализации синхронных интерфейсов находятся в Presentation/Adapter и вызывают
собственные Command/Query через шины. Публичные события формируются
инфраструктурными listener владельца по
[правилам межмодульных событий](integration-events.md).
Application не зависит от Contracts и других частей Presentation.
Подключение и границы описаны в
[правилах контрактов модулей](module-contracts.md).
HTTP Request/Response и адаптация входящих внешних форматов находятся в Presentation.
При синхронном получении данных другого модуля потребитель использует
собственный провайдер в `<UseCase>/Provider/`
и переводящий адаптер в Infrastructure/Integration. Только этот адаптер
импортирует чужие Presentation/Contracts. Публичные события другого модуля
также принимает Infrastructure/Integration потребителя по
[правилам межмодульных событий](integration-events.md).

Ограничение Application каталогами Command/Query относится к бизнес-модулям.
Shared сохраняет технические контракты в Shared/Application/Bus и общую доставку
в Shared/Infrastructure/Messaging. IntegrationEventMessage и IntegrationEventHandler
не являются операциями бизнес-модуля и не требуют каталогов Inbox/Outbox.

Shared — общая техническая основа, не набор общих бизнес-моделей. Бизнесовые
идентификаторы, Money, Customer и репозитории принадлежат конкретным контекстам.
Не перемещайте туда сущности, бизнесовые value objects, универсальные репозитории
или бизнес-правила ради устранения дублирования.
Осознанный Shared Kernel не вводится шаблоном заранее.

## Регистрация сервисов и атрибуты

Предпочитай PHP-атрибуты везде, где механизм их поддерживает и позволяет
граница слоя. Правило касается всех сервисов, а не только событий:

- Обработчики Command/Query и сообщений: `#[AsMessageHandler(bus: 'command.bus')]`
  или другая явная шина. Например, CreateDraftHandler помечается этим атрибутом,
  без тега messenger.message_handler в YAML.
- Синхронные listener: `#[AsEventListener(event: SomeDomainEvent::class)]`.
- Маршруты и контроллеры: Route и при необходимости AsController;
  CLI-команды: AsCommand; окружения инфраструктурных сервисов: When/WhenNot.
- DI в адаптерах: AsAlias для нужного связывания интерфейса, AsDecorator для
  декорирования, Autowire для неоднозначного аргумента, AutowireIterator для
  набора помеченных сервисов.
- Адаптеры межмодульных событий: штатный AsEventListener для постановки
  собственного Inbox-сообщения, AsMessageHandler с inbox.bus для его обработки
  по [правилам межмодульных событий](integration-events.md).

Если настройки по умолчанию, autowire/autoconfigure и атрибуты уже обеспечивают
нужное поведение, запись в конфиг не добавляй. Не заменяй ненужный YAML
столь же ненужным атрибутом. services.yaml содержит подключение узких ресурсов
и только необходимые настройки, которые нельзя корректно выразить атрибутами
или получить автоматически, например сложную сборку сервисов через фабрику.
Не дублируй атрибуты YAML-тегами: это может вызвать повторную обработку.

Атрибут сам по себе не загружает класс в контейнер. Подключай каталоги сервисов
узкими `resource` с autowire/autoconfigure, например Domain/Action/,
Infrastructure/Integration/ или только файлы Application/**/*Handler.php.
Уже охваченные ресурсом классы не перечисляй отдельно как `Class: ~`.
Не сканируй весь модуль или Domain; исключай DTO, сообщения, сущности,
value objects и прочие объекты данных.

Для Inbox/Outbox handlers подключай только файлы `*Handler.php` рекурсивно
из соответствующих каталогов Infrastructure/Messaging/Inbox/ и Outbox/.
Listener подключай отдельным ресурсом Infrastructure/Messaging/EventListener/.
Транспортные сообщения и вспомогательные DTO не должны становиться сервисами.

Единственная реализация интерфейса не гарантирует автоматический alias:
важно, какие интерфейсы и реализации попали в service discovery. Если alias
уже создан загрузчиком, дополнительная регистрация не нужна. Иначе укажи
`#[AsAlias(ProductOfferProviderInterface::class)]` на инфраструктурном адаптере;
сам интерфейс остаётся независимым от Symfony. Не вводи неявный выбор
реализации при неоднозначности. Для DI сервисы и aliases оставляй private;
`public: true` требует конкретного потребителя с доступом через контейнер,
а не только желания получить сервис в тесте.

Для единственного подключения Doctrine Connection внедряется по типу.
Messenger предоставляет именованные зависимости MessageBusInterface
`$commandBus`, `$queryBus`, `$inboxBus`, `$outboxBus`; при совпадении имён
и проверенной маршрутизации отдельные arguments/Autowire не нужны.

Границы зависимостей важнее удобства регистрации. В Application AsMessageHandler
регистрирует только Command/Query handlers; Inbox/Outbox handlers находятся
в Infrastructure. AsEventListener размещается во внешних слоях.
Оба атрибута запрещены в Domain и Presentation/Contracts.
Инфраструктурные атрибуты схем/подписок
размещаются на инфраструктурных сервисах, а не на публичных DTO.

## Добавление и подключение модуля

1. Определите ответственность, язык, границы агрегатов и опубликованные контракты.
2. Создайте `src/<Module>/AGENTS.md` по [шаблону](../templates/module-AGENTS.md).
3. Добавьте `config/` и нужные слои с namespace `App\<Module>\...`.
4. Явно зарегистрируйте модуль в `App\Kernel::moduleDirectories()`:

```php
return ['Example' => __DIR__.'/Example'];
```

5. Добавьте конфигурацию и тесты, обновите [карту модулей](../map.md).
При удалении регистрации исчезают сервисы,
маршруты, mappings, поставщики расписания и регистрации миграций владельца.
Данные при этом не удаляются. Перед отключением завершите обработку его очередей:
в сообщениях Messenger сохраняются PHP-типы, их нельзя удалять при наличии задач.
6. Выполните [проверки кода, архитектуры и конфигурации](checks.md).

Kernel загружает общую конфигурацию, затем Shared, затем модули в порядке
регистрации. Из каждого владельца загружаются `config/packages/*.yaml`,
`config/services.yaml` и `config/routes.yaml`. Для окружений используйте
`when@test`, `when@dev`, `when@prod` внутри файла владельца.

Относительный `resource` Symfony разрешается относительно YAML-файла.
Параметры расширений вроде Doctrine `dir` — обычные строки, поэтому для них
используйте `%kernel.project_dir%/src/<Module>/...`.

Пример `config/services.yaml` будущего модуля:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
    App\Example\Application\:
        resource: '../Application/**/*Handler.php'
    App\Example\Domain\Action\:
        resource: '../Domain/Action/'
    App\Example\Infrastructure\Integration\:
        resource: '../Infrastructure/Integration/'
    App\Example\Presentation\Http\Controller\Api\:
        resource: '../Presentation/Http/Controller/Api/'
```

В примере DoSomethingHandler помечен `#[AsMessageHandler(bus: 'command.bus')]`
в PHP-коде, маршруты контроллеров заданы Route; их теги добавляет autoconfigure.
Адаптеры при необходимости объявляют AsAlias на реализуемые порты.
Добавляй только ресурсы существующих каталогов, нужные конкретному модулю.
Не переопределяй сервисы соседей.

Начальные dev/test-данные загружайте через DoctrineFixturesBundle. Фикстуры
принадлежат Infrastructure владельца; их размещение и регистрация описаны
в [правилах фикстур](fixtures.md). Не создавайте для наполнения базы отдельные
Application-команды и CLI вроде `InitDemoCatalogCommand`.

Пример `config/routes.yaml`:

```yaml
example_api:
    resource: ../Presentation/Http/Controller/Api/
    type: attribute
    prefix: /api/v1
    name_prefix: example_
```

Маршруты `/api` остаются защищёнными `IS_AUTHENTICATED_FULLY`. Поддержка
access_token в шаблоне не настроена; новую аутентификацию этот рефакторинг не вводит.

## Данные, CQRS и события

У каждого модуля своя PostgreSQL-схема. Одно подключение и EntityManager сохраняются:
это логическая изоляция, не разграничение прав БД. Нельзя импортировать чужие
сущности, создавать межмодульные ORM-ассоциации/FK или читать чужие таблицы SQL.

Модуль регистрирует mapping в `config/packages/doctrine.yaml`:

```yaml
doctrine:
    orm:
        entity_managers:
            default:
                mappings:
                    Example:
                        type: attribute
                        is_bundle: false
                        dir: '%kernel.project_dir%/src/Example/Domain/Entity'
                        prefix: 'App\Example\Domain\Entity'
```

Схему задавайте явно: `#[ORM\Table(name: 'items', schema: 'example')]`.
Миграции владельца размещайте в Infrastructure/Persistence/Doctrine/Migrations,
регистрируя отдельный namespace в его `config/packages/doctrine_migrations.yaml`.
Общая миграция таблицы Messenger остаётся в корневом `migrations/`.
Не создавайте и не применяйте миграции без согласования.

Контракты прикладных шин находятся в Shared/Application/Bus:

- Command: `dispatch(CommandInterface): void`, validation + doctrine_transaction;
- Query: `dispatch(QueryInterface): mixed`, результат единственного обработчика,
  без изменения состояния;
- Inbox: `dispatch(InboxMessageInterface): void`, async, validation + transaction
  при обработке, три повтора и failed;
- Outbox: `publish(OutboxMessageInterface): void`, отдельная шина и PostgreSQL-очередь.

`Shared/Domain/Event/EventBusInterface` объявляет `dispatch(EventInterface): void`.
Реализация находится в Infrastructure и использует синхронный Symfony EventDispatcher.
Событие доменной операции создаёт и публикует выполняющий её Action;
сущностям шину не внедряйте. Domain не зависит от Application или Symfony
EventDispatcher; исключение Symfony UID описано в разделе границ выше.

Обработчики Messenger предпочтительно помечаются AsMessageHandler с явным bus,
listener доменных событий — AsEventListener. Их подключение описано в
[правилах регистрации сервисов](#регистрация-сервисов-и-атрибуты).
Входящий внешний формат преобразуется Presentation-адаптером во внутреннюю
команду или inbox DTO.
Репозиторий не вызывает flush самостоятельно. Локальный use case — граница
транзакции. Межконтекстную запись одной общей транзакцией не координируйте.

Доменные события обрабатываются только внутри своего контекста до commit;
исключение обработчика откатывает транзакцию. Не выполняйте здесь HTTP, email
или другие внешние побочные эффекты. Listener собственных доменных событий
находятся в Infrastructure/Messaging/EventListener/: преобразуют данные в
собственный Command/Query либо записывают сообщение внутреннего продолжения
в outbox в той же транзакции. Оркестрацию выполняет вызываемый прикладной сценарий,
бизнесовые решения — доменный слой. Содержание доменного события, eventAt
и допустимые ссылки на агрегаты описаны в
[правилах доменных событий](domain-actions.md#доменные-события-и-реакции).
Интеграционные события — отдельные версионируемые контракты в
Presentation/Contracts/Event/. Синхронный инфраструктурный listener владельца
формирует публичное событие и записывает его в outbox через общую техническую
обёртку до commit. Формирование снимка, метаданные, границы зависимостей
и совместимость описаны в [правилах межмодульных событий](integration-events.md).

### Исходящие сообщения

`OutboxMessageInterface` — технический маркер в Shared Application без обязательного
`messageId()`. Его реализуют внутренние сообщения и общая техническая обёртка
публичных событий; сами публичные события от маркера не зависят.
`OutboxBusInterface::publish()` вызывается внутри активной транзакции `command.bus`
или явной транзакции того же Doctrine-подключения. Для внутренних реакций
и публичных событий это делает инфраструктурный listener владельца.
Реализация отклоняет вызов вне транзакции. Domain EventBus
остаётся синхронным и не служит транспортом для межмодульных сообщений.

`outbox.bus` направляет сообщения в Doctrine transport `outbox` с
`queue_name=outbox`. Он пишет в существующую таблицу `messenger_messages` через
тот же DBAL connection, что и данные владельца. PostgreSQL фиксирует изменение
модуля и запись сообщения вместе, даже если таблицы находятся в разных схемах.
Отдельной миграции outbox и relay в брокер нет. Не откладывайте запись outbox
через `DispatchAfterCurrentBusStamp` или after-commit: запись после commit
разрушает атомарность. Worker доставляет сохранённые сообщения после commit.

Инфраструктурный listener владельца напрямую вызывает
`OutboxBusInterface::publish(new IntegrationEventMessage($publicEvent))`:
одна обёртка в outbox независимо от числа listener. Знание Outbox и технической
обёртки относится к ответственности Infrastructure. Отдельный сервис,
который только упаковывает событие и делегирует publish, не нужен.
Штатный сериализатор Messenger сохраняет снимок публичного DTO
со скалярными метаданными и вложенными DTO, включая их PHP-типы.
Shared-handler на outbox.bus передаёт DTO в Symfony EventDispatcher.
Listener потребителей только переводят его в собственные Inbox-сообщения.
Doctrine transport async использует тот же default connection: рассылка
атомарно ставит все Inbox-сообщения в одной транзакции outbox.bus.
Каждый Inbox handler позже вызывает собственный прикладной сценарий
в своей транзакции inbox.bus.
Ошибки постановки откатывают всю рассылку; ошибка бизнес-реакции повторяет
только её сообщение. Повтор fanout после commit → ack может создать дубликаты.
API, выбор listener при доставке и совместимость сохранённых классов описаны
в [правилах межмодульных событий](integration-events.md).

Worker запускается `make outbox-worker` (или `messenger:consume outbox`).
Обработчики исходящих сообщений регистрируются явно на `outbox.bus`;
`inbox.bus` остаётся для входящих сообщений. Три повтора и `failed` transport
работают и для outbox. Возможна повторная доставка после сбоя worker, поэтому
каждый получатель обеспечивает идемпотентность по смысловому ключу операции.
Маркер общего ключа не задаёт. Несколько независимых получателей одного события
должны получать отдельные сообщения, чтобы ошибка одного не повторяла эффекты
другого.

## HTTP-ошибки и расписание

Модульный Presentation-провайдер реализует
`App\Shared\Presentation\Http\Error\DomainErrorProviderInterface`:

```php
public static function getErrors(): array
{
    return [ExpectedFailure::class => ['error' => 'example.expected_failure', 'status' => 409]];
}
```

Зарегистрируйте провайдер сервисом с `autoconfigure: true`; его tag добавляется
автоматически. Метод статический, не выполняет I/O. Карта собирается при компиляции
контейнера, дубликаты и некорректные классы/статусы приводят к ошибке.
Маппинг точный по классу: подкласс не наследует HTTP-код автоматически.
Listener поддерживает прямое исключение и HandlerFailedException, возвращает
`{"text":"...","error":"..."}` только для /api. Неизвестные исключения остаются Symfony.

Поставщик расписания находится в Infrastructure и реализует
`ScheduleContributorInterface::getMessages(): iterable<RecurringMessage>`.
Зарегистрируйте его с autoconfigure. Общая stateful-схема `scheduler_default`
собирает сообщения всех поставщиков; без них расписание пусто.

Для обработки inbox через async возвращайте recurring RedispatchMessage с
явным BusNameStamp, чтобы worker выбрал inbox.bus:

```php
yield RecurringMessage::every('1 minute', new RedispatchMessage(
    new Envelope(new YourInboxMessage(), [new BusNameStamp('inbox.bus')]),
    'async',
));
```

Типы: `Symfony\Component\Scheduler\RecurringMessage`,
`Symfony\Component\Messenger\Message\RedispatchMessage`,
`Symfony\Component\Messenger\Envelope`,
`Symfony\Component\Messenger\Stamp\BusNameStamp`.
Планировщик запускается `make scheduler`, async worker — `make worker`,
outbox worker — `make outbox-worker`.

## Межмодульные зависимости

Разрешённые поставщики Presentation/Contracts перечисляются
в `src/<Consumer>/config/dependencies.php`:

```php
<?php

declare(strict_types=1);

return ['Provider'];
```

Отсутствующий файл означает отсутствие межмодульных зависимостей. Неизвестные
модули, самозависимости и циклы запрещены. Разрешение относится только к
Infrastructure/Integration потребителя, не ко всему модулю.
Реализации из Presentation/Adapter поставщика потребителю недоступны.
Прямые вызовы обработчиков другого модуля запрещены.

Линтеры, архитектурные проверки, тесты и проверки конфигурации описаны
в [checks.md](checks.md).

Правила ИИ: общий AGENTS.md + инструкция затрагиваемого модуля, включая его тесты.
