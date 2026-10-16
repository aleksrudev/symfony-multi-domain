# HTTP-контроллеры

Прочитай перед созданием, изменением или ревью API-контроллеров и их маршрутов.

Каждый конкретный API-контроллер выполняет одно действие через публичный
нестатический `__invoke()`. Конструктор допускается; вспомогательные методы
делай private/protected. Дополнительные публичные методы, в том числе из traits,
запрещены. Унаследованные методы Symfony `AbstractController` допустимы.

Размещай контроллеры в `Presentation/Http/Controller/Api/<Actor>/` владельца.
Актор обозначает пользователя API, например `Admin` или `Visitor`;
перечень акторов фиксируй в AGENTS.md модуля. Называй класс по действию:

```text
Catalog/Presentation/Http/Controller/Api/
├── Admin/UpdateProductController.php
└── Visitor/
    ├── GetProductController.php
    └── GetProductsController.php
```

Например, вместо `AdminProductController::update()` создавай
`App\Catalog\Presentation\Http\Controller\Api\Admin\UpdateProductController::__invoke()`.
Маршрут задавай атрибутом класса/`__invoke()` либо конфигурацией владельца.
При разделении контроллера сохраняй URL, имена маршрутов, HTTP-методы
и ограничения доступа, если задача явно не меняет API.

Namespace актора не обеспечивает авторизацию. Настраивай права через механизмы
безопасности Symfony и проверяй их HTTP-тестами. Контроллер адаптирует вход,
вызывает Command/Query через шину и преобразует модель результата в HTTP-ответ.
Бизнес-операции и доступ к данным остаются за Application/Domain.

## Маппинг входных данных и валидация

Тело запроса и query string принимай как отдельные `final readonly` DTO
из `Presentation/Http/Request/` владельца. Используй Symfony
`#[MapRequestPayload(acceptFormat: 'json')]` для JSON-тела и `#[MapQueryString]`
для query string. Параметры маршрута, например `string $id`, допускаются
отдельными типизированными аргументами.

Ограничения полей задавай атрибутами
`Symfony\Component\Validator\Constraints as Assert` на Request DTO.
Symfony выполняет валидацию при маппинге до вызова контроллера;
не вызывай Validator вручную и не используй PHP `assert()` для проверки ввода.
HTTP DTO не является Application Command/Query: контроллер явно создаёт
сообщение из проверенных полей. В Application и Domain не передавай Request DTO.
Доменные инварианты продолжают обеспечивать сущности и value objects.

Не разбирай входные поля в контроллере через `json_decode()`, `getContent()`,
`$request->query` или `$request->request`. Не приводи непроверенные значения
через `(int)`/`(bool)` и не исправляй недопустимые значения через `min()`/`max()`.
Значения по умолчанию задавай в DTO. `Request` допустим для HTTP-метаданных,
например заголовков или сессии, а не для ручного маппинга полей.

Например, параметры списка:

```php
use Symfony\Component\Validator\Constraints as Assert;

final readonly class GetProductsRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 50)]
        public int $limit = 10,
    ) {
    }
}
```

Аргумент контроллера:

```php
#[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
GetProductsRequest $request = new GetProductsRequest()
```

Значение аргумента по умолчанию обеспечивает запрос без query string.
Контроллер передаёт `$request->page` и `$request->limit` в `GetProductsQuery`.

Ошибки типов и ограничений возвращай как JSON с HTTP 422. Для `MapQueryString`
явно указывай `validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY`:
стандартное значение Symfony — 404. Для `MapRequestPayload` стандартное — 422.
Задавай `format: 'json'` в маршруте или соответствующую общую настройку маршрутов.
Некорректный JSON возвращает 400, неподдерживаемый Content-Type — 415.
Сохраняй согласованную структуру ошибок API; не собирай её отдельно в контроллерах.

Для PATCH опиши в AGENTS.md владельца семантику отсутствующего поля, `null`
и `false` и сохраняй её при маппинге. `false` — переданное значение, а не отсутствие
поля. Если отсутствие и `null` имеют разный смысл, nullable-свойства со значением
по умолчанию `null` недостаточно: модель входа должна сохранять факт наличия поля.

Механизм описан в [документации Symfony 8.0](https://symfony.com/doc/8.0/controller.html#automatic-mapping-of-the-request).

## Проверки

PHPStan проверяет наличие namespace актора, `__invoke()` и отсутствие других
публичных методов. Использование маппинга, Assert и семантика Request DTO
проверяются ревью и HTTP-тестами; действующие проектные PHPStan-правила
их не проверяют. Смысл актора, имя действия, маршруты и права также проверяются
ревью и функциональными тестами; список проверок описан в [checks.md](checks.md#http-вход-и-валидация).
