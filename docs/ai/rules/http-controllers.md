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

PHPStan проверяет наличие namespace актора, `__invoke()` и отсутствие других
публичных методов. Смысл актора, имя действия, маршруты и права проверяются ревью
и функциональными тестами; команды описаны в [checks.md](checks.md).
