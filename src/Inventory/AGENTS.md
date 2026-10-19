# Инструкция модуля Inventory

## Назначение и язык

Контекст `Inventory` владеет:
- Учётом складских остатков товаров (`onHand`);
- Учётом зарезервированного количества товаров (`reserved`);
- Расчётом доступного для заказа остатка (`available = max(0, onHand - reserved)`);
- Администраторским изменением физических остатков (`PATCH /api/v1/admin/stock/{productId}`);
- Демо-фикстурами складских остатков для товаров.

Контекст `Inventory` НЕ владеет:
- Карточкой товара, наименованиями, ценами и публикацией на витрине (владеет `Catalog`);
- Корзинами покупателей, созданием и переходами статусов заказов (владеет `Ordering`);
- Оплатой и доставкой.

Термины:
- `StockPosition` — складская позиция конкретного товара;
- `onHand` — физический остаток товара на складе (фактическое наличие);
- `reserved` — зарезервированное количество товара под заказы;
- `available` — доступное для оформления количество (`max(0, onHand - reserved)`).

## Модель и инварианты

Агрегат `StockPosition` (`productId: ProductId`, `onHand: int`, `reserved: int`, `createdAt: DateTimeImmutable`, `updatedAt: DateTimeImmutable`):
- `ProductId` — `final readonly` value object в `Domain/ValueObject/ProductId`, оборачивает непустую строку. Использование скалярной строки обосновано едиными строковыми идентификаторами товаров (`prod-keyboard`, `prod-mouse` и др.) без межмодульных внешних ключей между таблицами каталога, склада и заказов.
- Инвариант неотрицательности остатков (BR-06, A-12): `onHand >= 0` и `reserved >= 0`. Попытка задать отрицательное значение отклоняется `InvalidArgumentException`.
- Инвариант баланса (BR-06, A-12): физический остаток никогда не может опускаться ниже зарезервированного количества (`onHand >= reserved`). Попытка уменьшить `onHand` ниже `reserved` вызывает доменное исключение `StockOnHandBelowReservedException`.
- Инвариант доступности: `available = max(0, onHand - reserved)`. Доступное количество никогда не бывает отрицательным.
- Защита на уровне PostgreSQL: таблица `inventory.stock_positions` снабжена CHECK-ограничениями:
  - `CONSTRAINT check_stock_on_hand_gte_zero CHECK (on_hand >= 0)`
  - `CONSTRAINT check_stock_reserved_gte_zero CHECK (reserved >= 0)`
  - `CONSTRAINT check_stock_on_hand_gte_reserved CHECK (on_hand >= reserved)`

Доменные Action:
- `ChangeStockOnHandAction` (`Domain/Action/ChangeStockOnHandAction`): загружает позицию через `StockPositionDataProviderInterface::findByProductId` с пессимистической блокировкой на запись (`lock: true`), изменяет `onHand` через `StockPosition::changeOnHand($newOnHand)`, сохраняет через `StockPositionRepositoryInterface::save($position)`.

Application сценарии:
- `ChangeStockOnHandCommand` / `ChangeStockOnHandHandler`: оркестратор изменения остатка. При невалидном `productId` выбрасывает `StockPositionNotFoundException`. Тонкий обработчик вызывает `ChangeStockOnHandAction`.
- `GetStockPositionQuery` / `GetStockPositionHandler`: запрашивает позицию через `StockPositionDataProviderInterface::findByProductId` и преобразует её в модель `GetStockPositionQueryResult` (`productId`, `onHand`, `reserved`, `available`, `updatedAt: string` в формате ATOM). Доменные сущности наружу не раскрываются.

## Публичный API и зависимости

- Модуль `Inventory` на этапе T06 не зависит от других бизнес-модулей (`config/dependencies.php` возвращает пустой массив).
- Экспортируемые контракты в `Presentation/Contracts/` будут добавлены в задаче T07/T08 для интеграции резервов.

HTTP API контроллеры одного действия:
- Актор `Admin` (`Presentation/Http/Controller/Api/Admin/`):
  - `UpdateStockOnHandController::__invoke(string $productId, UpdateStockOnHandRequest $request)`: `PATCH /api/v1/admin/stock/{productId}` (format: `json`), имя маршрута `inventory_admin_stock_update`, доступ `ROLE_ADMIN` (защищён `security.yaml` и глобальной проверкой `X-CSRF-Token`). Маппинг через `#[MapRequestPayload(acceptFormat: 'json')]`.

HTTP Request DTO и валидация (`Presentation/Http/Request/`):
- `UpdateStockOnHandRequest`:
  - `onHand: ?int = null`, ограничения `#[Assert\NotNull]`, `#[Assert\PositiveOrZero]` (неотрицательное целое число).
  - Преобразуется в `ChangeStockOnHandCommand(productId: $productId, onHand: $request->onHand)`.

Семантика PATCH:
- Поле `onHand` является обязательным. Отсутствие поля или передача `null` отклоняются валидатором Symfony с кодом HTTP 422.
- Отрицательные значения `onHand` отклоняются валидацией с кодом HTTP 422.
- Попытка установить `onHand` ниже зарезервированного количества возвращает HTTP 422 с телом `{"error": "stock_on_hand_below_reserved", ...}` через `InventoryErrorProvider`.
- Несуществующий `productId` возвращает HTTP 404 с телом `{"error": "stock_position_not_found", ...}` через `InventoryErrorProvider`.
- Некорректный синтаксис JSON возвращает HTTP 400.
- Неподдерживаемый `Content-Type` возвращает HTTP 415.
- Отсутствующий или невалидный заголовок `X-CSRF-Token` возвращает HTTP 403 (`invalid_csrf_token`).
- Неавторизованный запрос возвращает HTTP 401, запрос без роли администратора — HTTP 403.

## Хранение, сообщения и конфигурация

- Собственная схема PostgreSQL: `inventory`.
- Таблица: `inventory.stock_positions` с первичным ключом `product_id`.
- Маппинг `ProductId` зарегистрирован через DBAL-тип `inventory_product_id` (`ProductIdType`).
- Репозиторий: `StockPositionRepositoryInterface` в `Domain/Repository/` содержит `save(StockPosition $position): void` и `remove(StockPosition $position): void`. Реализация в `Infrastructure/Persistence/Doctrine/Repository/StockPositionRepository`.
- DataProvider: `StockPositionDataProviderInterface` в `Domain/DataProvider/` содержит:
  - `findByProductId(ProductId $productId, bool $lock = false): StockPosition` (выбрасывает `StockPositionNotFoundException` при отсутствии);
  - `findAll(): StockPositionCollection`.
  Реализация в `Infrastructure/Persistence/Doctrine/DataProvider/StockPositionDataProvider`.
- Коллекции:
  - `StockPositionCollection` в `Domain/Collection/StockPositionCollection` — строго типизированная коллекция сущностей `StockPosition`, наследует `App\Shared\Domain\Collection\AbstractCollection<StockPosition>`, возвращает `StockPosition::class` в `elementClass()`.
- Фикстуры: `InventoryFixtures` в `Infrastructure/Persistence/Doctrine/Fixtures/InventoryFixtures` наполняет склад начальными остатками для демо-товаров (`prod-keyboard`: 10, `prod-mouse`: 20, `prod-monitor`: 5, `prod-headphones`: 0).

## Проверки и изменения

- Тесты модуля:
  - Unit: `tests/Unit/Inventory/`
  - Integration: `tests/Integration/Inventory/`
  - Functional: `tests/Functional/Inventory/`
- Запуск тестов модуля:
  - `make test`
  - `make console CMD="doctrine:migrations:migrate --no-interaction"`
  - `make console CMD="doctrine:fixtures:load --append --group=InventoryFixtures"`
- Проверки качества кода:
  - `make lint`
  - `make architecture`
  - `make console CMD="lint:container"`
  - `make console CMD="doctrine:schema:validate"`

