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

## Архитектурные проверки

`make architecture` запускает Deptrac 4 и строит отдельные слои для каждого
каталога модуля, даже если модуль отключён в Kernel.

Deptrac проверяет границы слоёв, запрет чужих моделей, зависимости Shared,
запрет доступа Presentation к Domain/Repository и Application к адаптерам.
DI-код сборки в Infrastructure/Symfony/DependencyInjection может связывать слои
своего модуля. Domain/Repository — обязательное место репозиторных интерфейсов.

Разрешённые межмодульные зависимости задаются в `config/dependencies.php`
потребителя; правила описаны в [архитектуре](architecture.md).
Статический анализ зависимостей не анализирует смысл SQL; это проверяется ревью
и интеграционными тестами конкретных репозиториев.

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

`make dev-db-recreate` удаляет dev-базу со всеми данными, создаёт её заново
и применяет миграции. Все шаги явно используют `--env=dev` и выполняются
последовательно; при ошибке следующие шаги не запускаются. Используется
подключение, настроенное для dev-окружения. Запускайте только для выделенной
dev-базы.

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
