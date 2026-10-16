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

HTTP API контроллеры одного действия:
- Актор `Visitor` (`Presentation/Http/Controller/Api/Visitor/`):
  - `GetProductsController::__invoke(GetProductsRequest $request)`: `GET /api/v1/products` (format: `json`), имя маршрута `products_list`, доступ публичный. Маппинг через `#[MapQueryString(validationFailedStatusCode: 422)]`.
  - `GetProductController::__invoke(string $id)`: `GET /api/v1/products/{id}` (format: `json`), имя маршрута `products_item`, доступ публичный.
- Актор `Admin` (`Presentation/Http/Controller/Api/Admin/`):
  - `UpdateProductController::__invoke(string $id, UpdateProductRequest $request)`: `PATCH /api/v1/admin/products/{id}` (format: `json`), имя маршрута `admin_products_update`, доступ `IS_AUTHENTICATED_FULLY` (защищён security-конфигурацией). Маппинг через `#[MapRequestPayload(acceptFormat: 'json')]`.

HTTP Request DTO и валидация (`Presentation/Http/Request/`):
- `GetProductsRequest`:
  - `page: int = 1`, ограничение `#[Assert\Positive]` (значения <= 0 запрещены);
  - `limit: int = 10`, ограничение `#[Assert\Range(min: 1, max: 50)]` (допустимо от 1 до 50);
  - Преобразуется в `GetProductsQuery($request->page, $request->limit)`.
- `UpdateProductRequest`:
  - `price: ?int = null`, ограничение `#[Assert\Positive]` (строго положительное целое число копеек);
  - `published: ?bool = null` (булево значение);
  - Преобразуется в `UpdateProductCommand(productId: $id, priceAmount: $request->price, published: $request->published)`.

Семантика PATCH:
- Отсутствие поля в JSON-теле запроса (`null` в DTO по умолчанию) — свойство товара не изменяется и сохраняет своё текущее значение.
- Значение `false` для `published` — явная команда на деактивацию публикации (`unpublish()`), не являющаяся отсутствием поля.
- Значение `null` — сущность `Product` не поддерживает `null` для цены и публикации; передача `null` в качестве цены отклоняется валидацией.
- Ошибки типов данных и нарушение Assert-ограничений возвращают HTTP 422 в формате JSON.
- Некорректный синтаксис JSON возвращает HTTP 400.
- Неподдерживаемый `Content-Type` возвращает HTTP 415.
- При ошибочном вводе контроллер не вызывает Command/Query-шины.

## Хранение, сообщения и конфигурация

- Собственная схема PostgreSQL: `catalog`.
- Таблица: `catalog.products` с индексом по `(published, created_at)`.
- Маппинг `ProductId` зарегистрирован через DBAL-тип `product_id` (`ProductIdType`).
- Репозиторий: `ProductRepositoryInterface` в `Domain/Repository/` содержит только `save(Product $product): void` и `remove(Product $product): void`. Реализация в `Infrastructure/Persistence/Doctrine/Repository/ProductRepository`.
- DataProvider: `ProductDataProviderInterface` в `Domain/DataProvider/` содержит `findById()`, `findPublishedById()` (выбрасывают `ProductNotFoundException` при отсутствии/скрытии) и `findPublishedPaginated()`. Реализация в `Infrastructure/Persistence/Doctrine/DataProvider/ProductDataProvider`.
- Коллекции:
  - `ProductCollection` в `Domain/Collection/ProductCollection` — коллекция сущностей `Product`, наследует `App\Shared\Domain\Collection\AbstractCollection<Product>`, возвращает `Product::class` в `elementClass()`.
  - `ProductPaginatedCollection` в `Domain/Collection/ProductPaginatedCollection` — readonly-оболочка выборки с пагинацией (`ProductCollection $items`, `int $count`).
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
