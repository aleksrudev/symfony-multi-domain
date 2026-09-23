# Мультидоменный шаблон

Шаблон содержит только Kernel и Shared. Бизнес-модули, демо-функции и
аутентификация добавляются в создаваемом сервисе. Стек: Symfony 8, PHP >= 8.4.17,
Doctrine ORM 3, PostgreSQL 16, Messenger, Scheduler, FrankenPHP.

## Границы и структура

Каждый `src/<Module>` — bounded context с собственным языком, моделью и данными:

```text
src/<Module>/
├── AGENTS.md
├── Domain/
│   ├── Entity/
│   ├── ValueObject/
│   ├── Repository/
│   └── Event/
├── Application/
├── Contracts/
├── Presentation/
│   ├── Http/Controller/Api/
│   ├── Http/Request/
│   ├── Http/Response/
│   ├── Console/
│   └── Messaging/
├── Infrastructure/
│   ├── Persistence/Doctrine/
│   ├── Integration/
│   └── Symfony/DependencyInjection/
└── config/
    ├── services.yaml
    ├── routes.yaml
    ├── dependencies.php
    └── packages/
```

Создавайте каталоги по необходимости. Команды, запросы, обработчики и прикладные
DTO группируются по сценарию использования внутри Application.

Presentation — входные адаптеры. Infrastructure — реализации исходящих портов.
Domain не знает о них. Интерфейс репозитория агрегата находится в Domain/Repository,
порты внешних возможностей — в Application. Application может использовать
Validator Constraints, Domain — только PHP и Doctrine-атрибуты маппинга.

Contracts — опубликованный межмодульный API, независимый от Symfony/Doctrine.
HTTP Request/Response и транспортные форматы сообщений находятся в Presentation.
Потребитель использует собственный Application-порт и переводящий адаптер в
Infrastructure/Integration. Только этот адаптер импортирует чужие Contracts.

Shared — общая техническая основа, не набор общих бизнес-моделей. Бизнесовые
идентификаторы, Money, Customer и репозитории принадлежат конкретным контекстам.
Осознанный Shared Kernel не вводится шаблоном заранее.

## Добавление и подключение модуля

1. Определите ответственность, язык, границы агрегатов и опубликованные контракты.
2. Создайте `src/<Module>/AGENTS.md` по [шаблону](templates/module-AGENTS.md).
3. Добавьте `config/` и нужные слои с namespace `App\<Module>\...`.
4. Явно зарегистрируйте модуль в `App\Kernel::moduleDirectories()`:

```php
return ['Example' => __DIR__.'/Example'];
```

5. Добавьте конфигурацию и тесты. При удалении регистрации исчезают сервисы,
маршруты, mappings, поставщики расписания и регистрации миграций владельца.
Данные при этом не удаляются. Перед отключением завершите обработку его очередей:
в сообщениях Messenger сохраняются PHP-типы, их нельзя удалять при наличии задач.
6. Выполните `make lint`, `make test`, `make architecture`.

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
    App\Example\Presentation\Http\Controller\Api\:
        resource: '../Presentation/Http/Controller/Api/'
        tags: [controller.service_arguments]
    App\Example\Infrastructure\Persistence\Doctrine\ExampleRepository: ~
    App\Example\Domain\Repository\ExampleRepositoryInterface:
        alias: App\Example\Infrastructure\Persistence\Doctrine\ExampleRepository
```

Не сканируйте целиком Domain или весь модуль как сервисы. Сущности, value objects,
команды/запросы и DTO не являются сервисами. Не переопределяйте сервисы соседей.

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
Статический анализ зависимостей не анализирует смысл SQL; это проверяется ревью
и интеграционными тестами конкретных репозиториев.

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

Контракты шин находятся в Shared/Application/Bus:

- Command: `dispatch(CommandInterface): void`, validation + doctrine_transaction;
- Query: `dispatch(QueryInterface): mixed`, результат единственного обработчика,
  без изменения состояния;
- Inbox: `dispatch(InboxMessageInterface): void`, async, validation + transaction
  при обработке, три повтора и failed;
- Event: `dispatch(EventInterface): void`, синхронный EventDispatcher;
- Outbox: `publish(OutboxMessageInterface): void`, отдельная шина и PostgreSQL-очередь.

Прикладные обработчики регистрируются с явным bus. Входящий внешний формат
преобразуется Presentation-адаптером во внутреннюю команду или inbox DTO.
Репозиторий не вызывает flush самостоятельно. Локальный use case — граница
транзакции. Межконтекстную запись одной общей транзакцией не координируйте.

Доменные события обрабатываются только внутри своего контекста до commit;
исключение обработчика откатывает транзакцию. Не выполняйте здесь HTTP, email
или другие внешние побочные эффекты. Интеграционные события — отдельные
версионируемые Contracts. Публичные Contracts остаются независимыми от транспорта:
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

## Архитектурные проверки и тесты

`make architecture` запускает Deptrac 4 и строит отдельные слои для каждого
каталога модуля, даже если модуль отключён в Kernel. Разрешённые поставщики
Contracts перечисляются в `src/<Consumer>/config/dependencies.php`:

```php
<?php

declare(strict_types=1);

return ['Provider'];
```

Отсутствующий файл означает отсутствие межмодульных зависимостей. Неизвестные
модули, самозависимости и циклы запрещены. Разрешение относится только к
Infrastructure/Integration потребителя, не ко всему модулю.

Deptrac проверяет границы слоёв, запрет чужих моделей, зависимости Shared,
запрет доступа Presentation к Domain/Repository и Application к адаптерам.
DI-код сборки в Infrastructure/Symfony/DependencyInjection может связывать слои
своего модуля. Domain/Repository — обязательное место репозиторных интерфейсов.

Тесты технической загрузки используют только tests/Fixtures и отдельный Kernel.
Они не создают production-модули и не регистрируются обычным Kernel. Команды,
inbox и базовая outbox-маршрутизация проверяются на SQLite in-memory.
Дополнительный outbox-тест на PostgreSQL запускается при наличии
`OUTBOX_TEST_DATABASE_URL`, указывающего на отдельную БД с суффиксом `_test`;
CI подготавливает её отдельно. Тест проверяет атомарность записи между схемой
модуля и таблицей `messenger_messages`.

Bootstrap PHPUnit не создаёт/удаляет БД и не применяет миграции. Для настоящих
DB-интеграций после согласования:

```console
make test-db-prepare
make test
```

Подготовка требует test-окружения и БД с `_test[TEST_TOKEN]`, не удаляет существующую
базу, создаёт её при отсутствии и применяет миграции. Для unit-тестов:
`make test-unit`. Никогда не направляйте тесты на рабочую БД.

Полная проверка: `make lint`, `make test`, `make architecture`,
`make console CMD="lint:container --env=test"`, аналогично prod,
`make console CMD="doctrine:schema:validate --skip-sync --env=test"`.
Для окружения без Docker make допускает `PHP=/path/to/php`, например
`make test PHP=/path/to/php`. Требование версии PHP остаётся тем же.

Правила ИИ: общий AGENTS.md + инструкция затрагиваемого модуля, включая его тесты.
CLAUDE.md и ANTIGRAVITY.md ссылаются на единый источник правил.
