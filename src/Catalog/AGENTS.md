# Инструкция модуля Catalog

Применяются корневые [AGENTS.md](../../AGENTS.md). Этот файл также прочитай
при изменении тестов Catalog в `tests/Unit/Catalog`, `tests/Integration/Catalog`
и `tests/Functional/Catalog`.

## Назначение и язык

Контекст `Catalog` владеет:
- Карточкой товара (название, идентификатор);
- Текущей ценой товара целым числом копеек (валюта RUB);
- Признаком публикации (опубликован / скрыт).

Контекст `Catalog` НЕ владеет:
- Складскими остатками и резервированием (владеет `Inventory`);
- Исторической ценой и снимками позиций в оформленных заказах (владеет `Ordering`);
- Корзинами и покупателями (владеет `Ordering`).

## Модель и инварианты

- `Product` — агрегат с постоянным `productId`, названием, ценой в копейках и признаком публикации.
- Цена (`priceAmount`) — строго положительное целое число (> 0).
- Валюта в первой версии — RUB.
- Инвариант BR-01: продаваемым предложением является только опубликованный товар (`isPublished() === true`).
- Инвариант BR-15: снятие публикации или изменение цены не изменяет уже зафиксированные снимки в заказах.
- Скрытие товара (`unpublish()`) оставляет товар в каталоге, но исключает его из витрины и делает `GetOffer(productId)` равным `null`.

## Публичный API и зависимости

- Экспортируемый контракт: `App\Catalog\Contracts\CatalogContractInterface`:
  - `getOffer(string $productId): ?Offer` — возвращает опубликованное предложение (DTO `Offer`: `productId`, `name`, `priceAmount`, `currency`) либо `null`, если товар не существует или скрыт.
- Модуль `Catalog` не зависит от других бизнес-модулей (`config/dependencies.php` возвращает пустой массив).

## Хранение, сообщения и конфигурация

- Собственная схема PostgreSQL: `catalog`.
- Таблица: `catalog.products`.
- Команды используют `command.bus` (транзакционный commit через middleware). Репозиторий не вызывает `flush()` самостоятельно.
- Запросы используют `query.bus` и не изменяют состояние.
- Доменные исключения (`ProductNotFoundException`, `InvalidProductPriceException`) маппятся через `CatalogErrorProvider` (`DomainErrorProviderInterface`) в HTTP 404 и 422.
- Dev/demo-команда: `catalog:init-demo` (`InitDemoCatalogCommand`) создаёт согласованные начальные товары идемпотентно и запрещена к выполнению в окружении `prod`.

## Проверки и изменения

- Расположение тестов:
  - Unit: `tests/Unit/Catalog/`
  - Integration: `tests/Integration/Catalog/`
  - Functional: `tests/Functional/Catalog/`
- Запуск тестов модуля:
  - `docker compose exec php vendor/bin/phpunit tests/Unit/Catalog tests/Integration/Catalog tests/Functional/Catalog`

