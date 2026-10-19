# Архитектура

Прочитай перед созданием, изменением или ревью модулей, зависимостей слоёв,
публичных контрактов и конфигурации подключения модулей.

Шаблон содержит только Kernel и Shared. Бизнес-модули, демо-функции и
аутентификация добавляются в создаваемом сервисе. Стек: Symfony 8, PHP >= 8.4.17,
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
│   │   └── Dto/
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
│   └── Symfony/DependencyInjection/
└── config/
    ├── services.yaml
    ├── routes.yaml
    ├── dependencies.php
    └── packages/
```

Создавайте каталоги по необходимости. В Application бизнес-модуля находятся
только каталоги Command и Query. Сообщения, обработчики, модели результатов
и необходимые прикладные порты группируются внутри соответствующего сценария.
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
| Application | Command/Query-сценарии как оркестраторы доменного слоя, сообщения, обработчики и прикладные порты. |
| Presentation/Contracts | Публичный межмодульный API: интерфейсы и неизменяемые DTO в Dto/. |
| Presentation | Реализации публичных интерфейсов в Adapter/, HTTP-контроллеры и Request/Response DTO, CLI, адаптация входящих внешних сообщений. |
| Infrastructure | Doctrine-репозитории, внешние и межмодульные адаптеры. |
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
Validator Constraints. Domain использует PHP и Doctrine-атрибуты маппинга сущностей.
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
Реализации интерфейсов находятся в Presentation/Adapter и вызывают
собственные Command/Query через шины. Application не зависит от Contracts
и других частей Presentation. Подключение и границы описаны в
[правилах контрактов модулей](module-contracts.md).
HTTP Request/Response и транспортные форматы сообщений находятся в Presentation.
Потребитель использует собственный провайдер в `<UseCase>/Provider/`
и переводящий адаптер в Infrastructure/Integration. Только этот адаптер
импортирует чужие Presentation/Contracts.

Shared — общая техническая основа, не набор общих бизнес-моделей. Бизнесовые
идентификаторы, Money, Customer и репозитории принадлежат конкретным контекстам.
Не перемещайте туда сущности, бизнесовые value objects, универсальные репозитории
или бизнес-правила ради устранения дублирования.
Осознанный Shared Kernel не вводится шаблоном заранее.

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
    App\Example\Application\DoSomething\Handler:
        tags:
            - { name: messenger.message_handler, bus: command.bus }
    App\Example\Domain\Action\DoSomethingAction: ~
    App\Example\Presentation\Http\Controller\Api\:
        resource: '../Presentation/Http/Controller/Api/'
        tags: [controller.service_arguments]
    App\Example\Infrastructure\Persistence\Doctrine\ExampleRepository: ~
    App\Example\Domain\Repository\ExampleRepositoryInterface:
        alias: App\Example\Infrastructure\Persistence\Doctrine\ExampleRepository
    App\Example\Infrastructure\Persistence\Doctrine\ExampleDataProvider: ~
    App\Example\Domain\DataProvider\ExampleDataProviderInterface:
        alias: App\Example\Infrastructure\Persistence\Doctrine\ExampleDataProvider
```

Не сканируйте целиком Domain или весь модуль как сервисы. Сущности, value objects,
команды/запросы и DTO не являются сервисами. Не переопределяйте сервисы соседей.

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
Доменный Action или Application-обработчик организует публикацию;
сущностям шину не внедряйте. Domain не зависит от Application или Symfony
EventDispatcher; исключение Symfony UID описано в разделе границ выше.

Прикладные обработчики регистрируются с явным bus. Входящий внешний формат
преобразуется Presentation-адаптером во внутреннюю команду или inbox DTO.
Репозиторий не вызывает flush самостоятельно. Локальный use case — граница
транзакции. Межконтекстную запись одной общей транзакцией не координируйте.

Доменные события обрабатываются только внутри своего контекста до commit;
исключение обработчика откатывает транзакцию. Не выполняйте здесь HTTP, email
или другие внешние побочные эффекты. Интеграционные события — отдельные
версионируемые DTO в Presentation/Contracts/Dto. Публичные Contracts остаются независимыми от транспорта:
адаптер владельца преобразует их в исходящее сообщение.

### Исходящие сообщения

`OutboxMessageInterface` — технический маркер в Shared Application без обязательного
`messageId()`. `OutboxBusInterface::publish()` вызывается из сценария использования
только внутри активной транзакции `command.bus` или явной транзакции того же
Doctrine-подключения. Реализация отклоняет вызов вне транзакции. Domain EventBus
остаётся синхронным и не служит транспортом для межмодульных сообщений.

`outbox.bus` направляет сообщения в Doctrine transport `outbox` с
`queue_name=outbox`. Он пишет в существующую таблицу `messenger_messages` через
тот же DBAL connection, что и данные владельца. PostgreSQL фиксирует изменение
модуля и запись сообщения вместе, даже если таблицы находятся в разных схемах.
Отдельной миграции outbox и relay в брокер нет. `DispatchAfterCurrentBusStamp`
здесь не применять: публикация после commit разрушает атомарность.

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
