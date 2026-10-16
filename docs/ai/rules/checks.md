# Проверки кода и тесты

## Обязательные проверки

Перед завершением запускай релевантные тесты и `make lint`. При изменениях
нескольких слоёв — `make test` и `make architecture`. Для конфигурации также
проверяй контейнер test/prod и Doctrine metadata. Сообщай фактические результаты
и ограничения окружения.

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

Разрешённые межмодульные зависимости задаются в `config/dependencies.php`
потребителя; правила описаны в [архитектуре](architecture.md).
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

Для межмодульного API выполни
[проверки контрактов и адаптеров](module-contracts.md#проверки).
Deptrac выделяет Presentation/Contracts отдельно: запрещает его зависимость
от внутренних слоёв, доступ Application к публичным Contracts и доступ
потребителей к Presentation/Adapter поставщика. Разрешение чужих Contracts
действует только для Infrastructure/Integration при объявленной зависимости.

## Тесты

`make test` запускает PHPUnit, `make test-unit` — только unit-тесты.
Unit-тесты изолированы от контейнера и БД. Интеграции с контейнером/БД используют
`Tests\Integration\IntegrationTestCase`; HTTP-тесты — WebTestCase либо прямой
Kernel для проверки адаптеров. Технические fixtures находятся только в tests.

Тесты технической загрузки используют только tests/Fixtures и отдельный Kernel.
Они не создают production-модули и не регистрируются обычным Kernel. Команды,
inbox и базовая outbox-маршрутизация проверяются на SQLite in-memory.
Дополнительный outbox-тест на PostgreSQL запускается при наличии
`OUTBOX_TEST_DATABASE_URL`, указывающего на отдельную БД с суффиксом `_test`;
CI подготавливает её отдельно. Тест проверяет атомарность записи между схемой
модуля и таблицей `messenger_messages`.

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
