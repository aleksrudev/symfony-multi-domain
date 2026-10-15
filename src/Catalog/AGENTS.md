# Инструкция модуля Catalog

## Назначение и язык

Контекст `Catalog` владеет:
- Карточкой товара (название, идентификатор);
- Текущей ценой товара целым числом копеек (валюта RUB);
- Признаком публикации (опубликован / скрыт).

Контекст `Catalog` НЕ владеет:
- Складскими остатками и резервированием (владеет `Inventory`);
- Исторической ценой и снимками позиций в оформленных заказах (владеет `Ordering`);
- Корзинами и покупателями (владеет `Ordering`).

Термины:
- `Product` — товар в каталоге;
- `Offer` — коммерческое предложение опубликованного товара для оформления заказа.

## Модель и инварианты

Агрегат `Product` (`id: ProductId`, `name: string`, `priceAmount: int`, `currency: Currency`, `published: bool`, `createdAt: DateTimeImmutable`).
- `ProductId` — `final readonly` value object в `Domain/ValueObject/ProductId`, оборачивает непустую строку.
- `Currency` — backed enum в `Domain/Enum/Currency` (`case RUB = 'RUB'`).
- Инвариант цены: `priceAmount` — строго положительное целое число копеек (> 0).
- Инвариант публикации (BR-01): коммерческим предложением (`Offer`) является только опубликованный товар (`isPublished() === true`). Скрытый товар не выводится на витрину и возвращает `null` в публичном контракте.
- Инвариант снимков (BR-15): изменение цены или снятие публикации не влияет на уже оформленные заказы.

Доменные Action:
- `UpdateProductAction` (`Domain/Action/UpdateProductAction`): загружает товар через `ProductDataProviderInterface`, применяет обновление цены (`Product::changePrice()`) и/или публикацию (`Product::publish() / Product::unpublish()`), сохраняет через `ProductRepositoryInterface`. Инварианты допустимости цены защищает сам `Product`.

Application сценарии:
- `UpdateProductCommand` / `UpdateProductHandler`: тонкий оркестратор, вызывает `UpdateProductAction`.
- `GetProductQuery` / `GetProductHandler`: запрашивает товар через `ProductDataProviderInterface` и преобразует в модель `GetProductQueryResult` (`productId`, `name`, `priceAmount`, `currency`, `published`).
- `GetProductsQuery` / `GetProductsHandler`: запрашивает пагинированную выборку через `ProductDataProviderInterface` и формирует модель `GetProductsQueryResult` (`items: list<GetProductQueryResult>`, `total`, `page`, `limit`). Доменные сущности наружу не раскрываются.

## Публичный API и зависимости

Экспортируемый контракт:
- Интерфейс `App\Catalog\Presentation\Contracts\CatalogContractInterface` с методом `getOffer(string $productId): ?Offer`.
- Неизменяемый DTO `App\Catalog\Presentation\Contracts\Dto\Offer` (`productId`, `name`, `priceAmount`, `currency`).
- Входящий адаптер `App\Catalog\Presentation\Adapter\CatalogContractAdapter`: вызывает `GetProductQuery(productId, onlyPublished: true)` через `query.bus` и преобразует `GetProductQueryResult` в `Offer`, при `ProductNotFoundException` возвращает `null`.
- Связь DI: `CatalogContractInterface` ассоциирован через alias с `CatalogContractAdapter` (`public: true`).
- Зависимости от других бизнес-модулей отсутствуют (`config/dependencies.php` возвращает пустой массив).

## Хранение, сообщения и конфигурация

- Собственная схема PostgreSQL: `catalog`.
- Таблица: `catalog.products` с индексом по `(published, created_at)`.
- Маппинг `ProductId` зарегистрирован через DBAL-тип `product_id` (`ProductIdType`).
- Репозиторий: `ProductRepositoryInterface` в `Domain/Repository/` содержит только `save(Product $product): void` и `remove(Product $product): void`. Реализация в `Infrastructure/Persistence/Doctrine/Repository/ProductRepository`.
- DataProvider: `ProductDataProviderInterface` в `Domain/DataProvider/` содержит `findById()`, `findPublishedById()` (выбрасывают `ProductNotFoundException` при отсутствии/скрытии) и `findPublishedPaginated()`. Реализация в `Infrastructure/Persistence/Doctrine/DataProvider/ProductDataProvider`.
- Коллекции: `ProductCollection` и `ProductPaginatedCollection` в `Domain/Collection/`.
- Начальные dev/test-данные: `CatalogFixtures` в `Infrastructure/Persistence/Doctrine/Fixtures/CatalogFixtures.php`. Зарегистрированы в `services.yaml` под `when@dev` и `when@test`. Согласованные идентификаторы: `prod-keyboard`, `prod-mouse`, `prod-monitor`, `prod-headphones` (неопубликован).
- Доменные исключения `ProductNotFoundException` (404) и `InvalidProductPriceException` (422) маппятся через `CatalogErrorProvider`.

## Проверки и изменения

- Расположение тестов:
  - Unit: `tests/Unit/Catalog/`
  - Integration: `tests/Integration/Catalog/`
  - Functional: `tests/Functional/Catalog/`
- Команда запуска тестов модуля:
  ```console
  docker compose exec php vendor/bin/phpunit tests/Unit/Catalog tests/Integration/Catalog tests/Functional/Catalog
  ```
