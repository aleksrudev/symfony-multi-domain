# Проверки кода и тесты

## Обязательные проверки

Перед завершением запускай релевантные тесты и `make lint`. При изменениях
нескольких слоёв — `make test` и `make architecture`. Для конфигурации также
проверяй контейнер test/prod и Doctrine metadata. Сообщай фактические результаты
и ограничения окружения.

Для изменений только Markdown проверь относительные ссылки и якоря, парность
кодовых блоков, `git diff --check` и актуальность карты. После удаления документов
найди все ссылки на удалённые пути, включая скрытые проектные инструкции.
`make lint` остаётся обязательным; полный PHPUnit и проверки контейнера только
из-за правки текста не требуются. Для изменений кода и конфигурации применяй
требования выше. Успех прошлого прогона не выдавай за свежую проверку.

Основной интерфейс запуска — make. По умолчанию команды выполняются в Docker
Compose-сервисе `php`. Для окружения без Docker make допускает `PHP=/path/to/php`,
например `make test PHP=/path/to/php`. Требование PHP >= 8.4.17 сохраняется.

## Линтеры и стиль кода

| Команда | Назначение |
| --- | --- |
| `make lint` | PHPStan и проверка стиля PHP-CS-Fixer |
| `make phpstan` | Статический анализ PHPStan |
| `make cs-check` | PHP-CS-Fixer с `--dry-run --diff`, без изменения файлов |
| `make cs-fix` | Исправление стиля PHP-CS-Fixer |

PHPStan использует `phpstan.dist.neon`, PHP-CS-Fixer — `.php-cs-fixer.dist.php`.

Проектные PHPStan-правила находятся в `tools/phpstan/Rules/`:

- API-контроллеры: namespace `Api/<Actor>/`, публичный нестатический `__invoke()`,
  отсутствие дополнительных публичных методов, включая traits. Идентификаторы:
  `architecture.controllerActor`, `architecture.controllerInvoke`,
  `architecture.controllerPublicMethod`. Абстрактные классы и унаследованные
  методы Symfony не считаются действиями конкретного контроллера.
- `{Entity}Collection` в `Domain/Collection/` наследует Shared `AbstractCollection`:
  `architecture.collectionBase`. Модели `*PaginatedCollection` исключены:
  это отдельные оболочки страницы.

Тесты правил находятся в `tests/Unit/Tooling/`, их намеренно некорректные примеры —
в `tests/Fixtures/PHPStan/`. Примеры исключены из обычного анализа и форматирования,
но проверяются через PHPStan `RuleTestCase`. После изменения dev-autoload выполни
`make composer CMD="dump-autoload --no-scripts"`.

## Архитектурные проверки

`make architecture` запускает Deptrac 4 и строит отдельные слои для каждого
каталога модуля, даже если модуль отключён в Kernel.

Deptrac проверяет границы слоёв, запрет чужих моделей, зависимости Shared,
запрет доступа Presentation к Domain/Repository и Application к адаптерам.
AsMessageHandler и AsEventListener выделены в отдельный слой HandlerAttributes:
Deptrac допускает их в Application и внешних слоях, запрещает в Domain и Contracts.
Правила структуры строже: Application бизнес-модуля содержит только
Command/Query handlers с AsMessageHandler, listener с AsEventListener —
во внешних слоях. Размещение listener отдельно проверяется ревью.
Исключение не разрешает Application зависеть от MessageBusInterface,
EventDispatcher и остальных сервисов Symfony. Положительные/отрицательные
fixtures проверяются `ArchitectureTest::testRegistrationAttributesDoNotExposeFrameworkServicesToInnerLayers`.

DI-код сборки в Infrastructure/Symfony/DependencyInjection может связывать слои
своего модуля. Domain/Repository — обязательное место репозиторных интерфейсов.

В Domain прямые зависимости от Doctrine Collections разрешены только Shared
`Domain/Collection/AbstractCollection`. Для коллекций модулей Deptrac допускает
унаследованную зависимость через эту основу; подписчик
`tools/deptrac/InheritedCollectionDependencySubscriber.php` проверяет, что
источник зависимости — файл Shared-основы, в том числе при раскрытии generic-типов.
Прямые импорты Doctrine в конкретной коллекции запрещены,
даже если она наследует Shared-основу. Исключение не разрешает ORM-сервисы.
Положительные и отрицательные примеры проверяются в `tests/Unit/Architecture/`.

Прямые зависимости Domain от Symfony UID разрешены только Shared-основам
`Domain/ValueObject/UuidId` и `UlidId`. Deptrac выделяет их в слой `UidBase`,
а компонент — в `SymfonyUid`. Подписчик
`tools/deptrac/InheritedUidDependencySubscriber.php` допускает унаследованные
зависимости доменных ID только с источником в файлах этих основ.
Прямые импорты UID в других доменных классах и зависимости основ от остальных
компонентов Symfony запрещены. Проверяй обе стороны исключения через
`tests/Unit/Architecture/ArchitectureTest.php`, поведение ID — через
`tests/Unit/Domain/UidIdTest.php`.

Разрешённые зависимости задаются в config/dependencies.php двумя обязательными
списками sync/events. Sync открывает интерфейсы/DTO, events — события/DTO;
циклы запрещены только в sync. Правила — в [архитектуре](architecture.md).
DependencyGraphTest проверяет графы и формат, ArchitectureTest — реальные импорты
через Deptrac. CLI отклоняет невалидную декларацию с exit code 2 без stack trace.
Статический анализ зависимостей не анализирует смысл SQL; это проверяется ревью
и интеграционными тестами конкретных репозиториев и DataProvider.

При изменениях доступа к данным выполни также
[проверки репозиториев, DataProvider и коллекций](data-access.md#проверка-изменений).
Deptrac не проверяет сигнатуры методов и семантику результатов поиска.
Запрет доступа Presentation к Domain/DataProvider пока также проверяется ревью.

Для Command/Query и доменных Action выполни
[проверки распределения бизнес-логики](domain-actions.md#проверка-изменений).
Deptrac проверяет зависимости слоёв, но не определяет, где принимается
бизнес-решение.

При ревью структуры бизнес-модуля проверь, что в Application есть только
Command/ и Query/, а сообщения, handlers, входные DTO, результаты и порты
группируются по сценарию. Listener собственных доменных событий находятся
в Infrastructure/Messaging/EventListener/. Технические Inbox/Outbox DTO
конкретных внешних интеграций,
handlers и вспомогательные типы находятся в
`Infrastructure/Messaging/Inbox/<Operation>/` или `Outbox/<Operation>/`;
файлов непосредственно в Inbox/Outbox нет. Handler бизнесового продолжения
вызывает собственный Command/Query через шину, Application не импортирует
инфраструктурные сообщения. Shared/Application/Bus и общая техническая доставка
Shared сохраняют свою структуру. Текущий Deptrac не проверяет перечень каталогов,
группировку по операциям и роль handler: это отдельные пункты ревью.

Для доменных событий и listener выполни также
[проверки событий и outbox](#доменные-события-и-outbox).
Deptrac не проверяет содержание события, имя eventAt, регистрацию listener
и атомарность его реакции; эти требования проверяются ревью и тестами.

Для межмодульного API выполни
[проверки контрактов и адаптеров](module-contracts.md#проверки).
Для публичных событий выполни также
[проверки формирования и доставки](#межмодульные-события) по
[соглашению о межмодульных событиях](integration-events.md).
Deptrac выделяет публичные интерфейсы, Event и Dto отдельно. Он запрещает их
зависимости от внутренних слоёв, доступ Application к Contracts и доступ
потребителя к Presentation/Adapter. Чужие Contracts доступны только
Infrastructure/Integration согласно sync/events. Точный Shared-контракт
метаданных разрешён публичным Event, но не Domain/Application.

При ревью проследи весь путь исходящего Outbox-сообщения до конечного эффекта:
нет ли отложенного адресного вызова API другого модуля, в том числе из handler
отправителя. Применяй [границы асинхронного взаимодействия](transactional-messaging.md#границы-асинхронного-взаимодействия).
Deptrac проверяет импорты по `sync/events`, но не определяет назначение
сообщения и всей цепочки вызовов; допустимые импорты не доказывают соблюдения
этого запрета.

## Тесты

`make test` запускает PHPUnit, `make test-unit` — только unit-тесты.
Unit-тесты не требуют загрузки Symfony Kernel или внешней БД; технические
проверки могут использовать SQLite in-memory. Для обычных интеграций доступен
`Tests\Integration\IntegrationTestCase` на основе KernelTestCase. Независимые
механизмы и процессные проверки используют собственные test-kernels и базовые
классы из `tests/Fixtures/Messaging/`; не привязывай их к полному приложению.
HTTP-тесты используют WebTestCase либо прямой Kernel для проверки адаптеров.
Технические fixtures находятся только в tests.

При изменении DI проверь, что сервисы загружаются узкими ресурсами и не требуют
дублирующих `Class: ~`, tags, aliases или arguments. Все нужные шины и порты
разрешаются в реальном контейнере; DTO и другие объекты данных не стали сервисами.
Учитывай, что атрибут не заменяет service discovery, а единственная реализация
сама по себе не гарантирует alias. Проверяй private aliases, регистрацию listener
без повторов, состав tagged iterator и сборку контейнера test/prod.
Не делай production-сервисы публичными только ради тестов: используй тестовый
контейнер, а для удалённых при оптимизации сервисов — точечные настройки when@test.

Тесты технической загрузки используют только tests/Fixtures и отдельный Kernel.
Они не создают production-модули и не регистрируются обычным Kernel. Команды,
Query, локальное расписание и routing событий проверяются на SQLite/in-memory.
Полная надёжная доставка проверяется отдельно на PostgreSQL.
PostgreSQL-проверки надёжности используют отдельные guarded test-БД для
Outbox, Consumer и полной композиции, как описано ниже. Подготавливай их
отдельно перед запуском; тесты не применяют миграции.

### Доменные события и outbox

Для надёжной доставки выполни [проверки паттернов](transactional-messaging.md#проверка-решения).
При изменении публикации доменного факта и его инфраструктурной адаптации проверь:

- Успешная операция публикует соответствующее событие из Action после изменения
  агрегата и вызова Repository. Содержание события соответствует доменному факту;
  eventAt — момент операции, а не время обработки listener.
- При отказе операции событие не публикуется; идемпотентное воспроизведение
  результата команды не создаёт повторного события.
- Несколько listener получают одно событие; его контракт не привязан к данным
  конкретного получателя. Регистрация проверяется через действующий контейнер
  и EventBus, а не только прямым вызовом метода listener.
- Инфраструктурный listener формирует самостоятельный снимок публичного факта
  либо намерение конкретного внешнего эффекта. В нём нет агрегатов, сущностей
  и ORM-коллекций. Бизнесовое решение остаётся в Domain/Application.
  Для публичного события проверь самостоятельный событийный канал по разделу ниже.
- Интеграционный тест проверяет откат изменения агрегата при ошибке записи outbox
  и откат уже записанного сообщения при последующей ошибке до commit.
  Сообщение идентифицируется по проверяемой операции, а не общему числу в очереди.
- Атомарность на PostgreSQL проверяется на выделенной тестовой БД по правилам
  подготовки ниже. Тест не должен самостоятельно применять миграции.

### Межмодульные события

Контракт метаданных уже проверяется IntegrationEventContractTest:
PHP отклоняет отсутствие каждого обязательного свойства и неверный тип,
текущий readonly DTO сохраняет метаданные/вложенные значения при сериализации.
Компиляторные проверки используют изолированный PHP -n, без отладочных расширений.

Нейтральный Outbox проверяется DoctrineOutboxWriterTest (SQLite),
TransactionalOutboxSchemaTest, TransactionalOutboxTest, OutboxRelayTest и
OutboxCommandsTest (PostgreSQL). OutboxKernel регистрирует только нейтральный Outbox,
тестовый publisher и ORM-probe; Messenger/Consumer не нужны. Тесты используют
подготовленную test-БД, не применяют миграции сами. Проверка SIGTERM требует pcntl,
который включён в Dockerfile.
Для запуска PostgreSQL-тестов переменная OUTBOX_TEST_DATABASE_URL должна быть
доступна внутри PHP-процесса. При передаче уже заданной переменной хоста:

```console
make test PHP="docker compose exec -e OUTBOX_TEST_DATABASE_URL php php -d memory_limit=256M"
```

Значение должно указывать на отдельную БД с суффиксом _test, проверяемую guard.
При нехватке стандартных 128M допускается временный process-only memory_limit,
без изменения php.ini.

Consumer проверяется MessageIdentityTest, IdempotentConsumerSchemaTest,
IdempotentConsumerTest и IdempotentConsumerOrmTest. Его Kernel не подключает
Outbox/Messenger; CONSUMER_TEST_DATABASE_URL указывает на отдельную guarded test-БД
без их таблиц. Подготовка выполняется после согласования: создание отдельной БД
и применение только Version20261026000100 через make console. Тесты сами миграции
не применяют. Проверяются реальная блокировка второго процесса на receipt,
commit/rollback победителя, изоляция идентификаторов, flush/commit failure,
очистка UnitOfWork и явный reset закрытого manager.
Полная композиция проверяется через TransactionalMessagingKernel и
MESSAGING_TEST_DATABASE_URL в IntegrationEventDeliveryTest,
PostgresIntegrationEventTest, IdempotentConsumerOutboxTest и WorkerHealthCheckTest.
Healthchecks relay, async-worker и scheduler проверяются при работающих
и завершённых реальных процессах из Compose-конфигурации.
Эта отдельная test-БД
содержит три существующие миграции: Messenger transport, Outbox и Consumer.
Статическое подключение DAMA в этом kernel отключено для настоящих commit/rollback.
PostgresIntegrationEventTest проверяет crash после commit до ACK и после relay send
до commit через SIGKILL отдельных процессов. Барьеры фиксируют фазу, а pg_stat_activity
подтверждает конкуренцию по advisory/transactionid lock, не длительность sleep.
После crash тест сдвигает только delivered_at мёртвого worker для ускорения lease;
сообщение, receipts и эффекты остаются исходными.
ORM-ошибка проверяется первой и последней реакцией со штатным reset между доставками.
IntegrationEventDeliveryTest проверяет четыре отказа relay с настоящим Messenger
publisher, retry сохранённого снимка, failed retry после runtime/ORM-ошибки и свежий
Envelope. ModuleKernelTest отдельно проверяет production retry с jitter ±10%;
IdempotentConsumerTest подтверждает сохранение receipt после продвижения Clock на 20 лет.
Все три переменные можно передать из окружения хоста:

```console
make test PHP="docker compose exec -e OUTBOX_TEST_DATABASE_URL -e CONSUMER_TEST_DATABASE_URL -e MESSAGING_TEST_DATABASE_URL php php -d memory_limit=256M"
```

При изменениях сохраняй следующие проверки:

- Outbox принимает plain object без IntegrationEvent/Messenger и сохраняет
  снимок в source-транзакцию; ошибка serialize/INSERT и последующий отказ
  откатывают снимок и бизнесовые данные. Observer PostgreSQL не видит его до commit.
- Relay проверяется нейтральным publisher без event.bus: конкуренция,
  send/decode failure, повтор и восстановление failed без потери body.
- Consumer проверяется namespace/id и callback без payload/Outbox/Messenger.
  Конкурирующие процессы применяют один эффект; callback и receipt атомарны.
- ORM rollback не переносит pending-изменения в следующий callback.
  На закрытом EM нет нового receipt; восстановление принадлежит worker/адаптеру.
- IntegrationEventDispatcher проверяет метаданные и вызывает append($event).
  Издатель не создаёт Envelope/Inbox DTO и не обращается к event.bus.
- Messenger publisher получает event.bus через DI. Только в этой композиции
  INSERT Doctrine async и DELETE Outbox атомарны на одном connection.
- Subscriber объявляет events-зависимость и AsMessageHandler с bus event.bus,
  переводит source/eventId в MessageIdentity и вызывает собственный сценарий.
  Application не получает чужой DTO.
- Несколько handlers не объединены общей doctrine_transaction. A-success/B-failure
  сохраняет A; retry/failed общие для события. Проверять настоящим worker,
  включая восстановление и повтор свежего Envelope без HandledStamp.
- После commit до ACK и при конкуренции эффект не применяется повторно;
  следующие исходящие события реакции атомарны с её receipt/данными.
- Сериализация обеих границ сохраняет текущие DTO, вложенные значения,
  eventId/eventAt и содержимое. Старые форматы не поддерживаются.
- Нет handlers — допустимо; выбор подписок при получении и отсутствие replay/FIFO
  описаны и проверены. Receipt не имеет автоматического TTL.
- Заменённые API/config/fixtures удалены при переключении, без двойного пути
  и aliases совместимости.

IntegrationEventDeliveryTest/PostgresIntegrationEventTest проверяют полную
композицию, без старых шин и промежуточных DTO. Обязательная PostgreSQL-приёмка требует реально выполненных
тестов: SKIP не доказывает гарантии. Подготовка test-БД согласуется отдельно,
тесты не применяют миграции сами.

### HTTP-вход и валидация

При изменении контроллеров и Request DTO выполни ревью по
[правилам маппинга входных данных](http-controllers.md#маппинг-входных-данных-и-валидация).
HTTP-тестами через Symfony Kernel, с действующими resolver и Validator, проверь:

- JSON-тело маппится через `MapRequestPayload`, query string — через `MapQueryString`;
  HTTP DTO явно преобразуется в Application Command/Query.
- Правильные значения и запрос без необязательных параметров работают;
  значения по умолчанию соответствуют DTO.
- Неверные типы и нарушения Assert возвращают JSON с HTTP 422;
  ограничения пагинации проверяются на границах и за их пределами.
- Некорректный JSON возвращает 400, неподдерживаемый Content-Type — 415.
- PATCH сохраняет согласованное поведение отсутствующих полей, `null` и `false`.
- При ошибочном вводе контроллер не вызывает Command/Query-шины.

Прямой вызов `__invoke()` не проверяет маппинг и автоматическую валидацию.
Текущие PHPStan-правила проверяют структуру контроллеров, но не наличие
`MapRequestPayload`/`MapQueryString`, Assert и семантику входных данных.

### Подготовка тестовой БД

Bootstrap PHPUnit не создаёт/удаляет БД и не применяет миграции.
`make test-db-prepare` — отдельная явная подготовка выделенной PostgreSQL БД
с суффиксом `_test[TEST_TOKEN]`. Команда требует test-окружения, создаёт базу
при отсутствии и применяет миграции, не удаляя существующую базу.
Подготовка требует согласования. После согласования:

```console
make test-db-prepare
make test
```

Никогда не направляйте тесты на рабочую БД.

### Пересоздание dev-базы

`make dev-db-recreate` удаляет dev-базу со всеми данными, создаёт её заново,
применяет миграции и загружает зарегистрированные Doctrine-фикстуры.
Если фикстур нет, загрузка пропускается. Команда загрузки использует `--append`:
база уже пересоздана, повторная очистка не нужна и данные миграций сохраняются.
Все шаги явно используют `--env=dev` и выполняются
последовательно; при ошибке следующие шаги не запускаются. Используется
подключение, настроенное для dev-окружения. Запускайте только для выделенной
dev-базы. Размещение и проверки фикстур описаны в [правилах фикстур](fixtures.md).

## Контейнер и Doctrine metadata

При изменениях конфигурации проверяй контейнер в обоих окружениях:

```console
make console CMD="lint:container --env=test"
make console CMD="lint:container --env=prod"
make console CMD="doctrine:schema:validate --skip-sync --env=test"
```

## Полная проверка

```console
make lint
make test
make architecture
make console CMD="lint:container --env=test"
make console CMD="lint:container --env=prod"
make console CMD="doctrine:schema:validate --skip-sync --env=test"
```
