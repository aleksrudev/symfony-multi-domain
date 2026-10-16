# Начальные данные и фикстуры

Применяй при создании или изменении начальных dev/test-данных и их загрузки.
Используй установленный в require-dev `doctrine/doctrine-fixtures-bundle`:
[документация](https://symfony.com/bundles/DoctrineFixturesBundle/current/index.html).
Bundle включён только в окружениях dev и test.

## Размещение и регистрация

Фикстуры бизнес-модуля размещай в
`src/<Module>/Infrastructure/Persistence/Doctrine/Fixtures/`.
Наследуй их от `Doctrine\Bundle\FixturesBundle\Fixture`.
Не создавай для наполнения базы Application-команды, обработчики и CLI вроде
`InitDemoCatalogCommand`. Не размещай бизнесовые фикстуры в Shared или
в общем `src/DataFixtures/`; перенеси туда, куда указывает владелец,
результат генерации `make:fixtures`.

Регистрируй фикстуры явно в `config/services.yaml` модуля только для dev/test
с `autowire: true` и `autoconfigure: true`: autoconfigure добавляет
тег `doctrine.fixture.orm`. Например:

```yaml
when@dev:
    services:
        App\Example\Infrastructure\Persistence\Doctrine\Fixtures\:
            resource: '../Infrastructure/Persistence/Doctrine/Fixtures/'
            autowire: true
            autoconfigure: true

when@test:
    services:
        App\Example\Infrastructure\Persistence\Doctrine\Fixtures\:
            resource: '../Infrastructure/Persistence/Doctrine/Fixtures/'
            autowire: true
            autoconfigure: true
```

Добавляй регистрацию вместе с реальными фикстурами. Пустые фикстуры-заглушки
в шаблоне не нужны.

## Данные и загрузка

Создавай сущности через их доменный API, сохраняя инварианты и типы ID.
Фикстуры могут использовать ObjectManager и вызывать `flush()` в `load()`;
это инфраструктурная загрузка данных, а не метод репозитория.
Не вызывай внешние интеграции и не отправляй реальные уведомления.

Каждая фикстура записывает только данные своего модуля. Согласованные ID
для связанных демо-данных фиксируй в инструкциях владельцев; не используй
чужие сущности, ORM-связи и запросы к чужим таблицам.

`make dev-db-recreate` после пересоздания dev-базы и миграций вызывает
`doctrine:fixtures:load --env=dev --append --no-interaction`, если фикстуры
зарегистрированы. `--append` сохраняет данные миграций; повторный запуск
этой команды отдельно не гарантирует идемпотентность.
Ручную загрузку в существующую БД и подготовку test-БД выполняй только
в рамках согласованной задачи по [правилам проверок](checks.md).
Bootstrap тестов не должен автоматически загружать фикстуры или пересоздавать БД.

## Проверки

Проверь регистрацию через `debug:container --tag=doctrine.fixture.orm --env=dev`
и аналогично для test. При отсутствии фикстур эта команда сообщает, что тег
не найден. В prod бизнесовые фикстуры не должны быть зарегистрированы.
Проверяй загрузку и ожидаемые данные на выделенной тестовой БД или изолированной
БД интеграционного теста; не пересоздавай существующую dev-базу ради проверки.
