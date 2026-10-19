# Контракты модулей и адаптеры

Применяй при создании, изменении и ревью публичного межмодульного API,
его реализаций и интеграций потребителей. Границы слоёв описаны
в [архитектуре](architecture.md), сценарии Application —
в [правилах Command/Query](domain-actions.md).

## Публичный API

Публичные интерфейсы размещай в `src/<Module>/Presentation/Contracts/`,
неизменяемые DTO — в `Presentation/Contracts/Dto/`. Например:

```text
Catalog/Presentation/
├── Contracts/
│   ├── CatalogContractInterface.php
│   └── Dto/Offer.php
└── Adapter/
    └── CatalogContractAdapter.php
```

Contracts содержит только опубликованные интерфейсы и DTO. Он не зависит
от Domain, Application, Infrastructure, адаптеров, Symfony или Doctrine.
Не раскрывай через него сущности, внутренние QueryResult, обработчики
и другие детали реализации. DTO содержат значения и другие публичные DTO.
Не регистрируй DTO как сервисы.

Не создавай отдельный верхнеуровневый каталог `<Module>/Contracts/`.
Само размещение в Presentation не делает остальной Presentation публичным API.

## Реализация и подключение

Интерфейс реализует входящий адаптер из `Presentation/Adapter/`, например
`CatalogContractAdapter`. Он преобразует публичные аргументы в собственные
Command/Query, вызывает `CommandBusInterface` или `QueryBusInterface`
и преобразует результат Application в публичный DTO.

Адаптер не вызывает Repository, DataProvider, доменные Action или обработчики
напрямую, не содержит бизнес-правил и не отдаёт внутренние модели наружу.
Сохраняй семантику внешнего контракта: например, `getOffer(): ?Offer`
возвращает `null` при отсутствии предложения. Преобразовывай только
согласованные случаи отсутствия и ошибки; не подавляй произвольные сбои.

Связь регистрируется явно в `config/services.yaml` владельца:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\Catalog\Presentation\Adapter\CatalogContractAdapter: ~
    App\Catalog\Presentation\Contracts\CatalogContractInterface:
        alias: App\Catalog\Presentation\Adapter\CatalogContractAdapter
```

Путь вызова: интерфейс → входящий адаптер → command.bus/query.bus →
Application-обработчик → доменный слой. QueryResult преобразуется
в публичный DTO на обратном пути. Отдельный Application/Service для
реализации публичного интерфейса не нужен.

## Application и потребители

В Application бизнес-модуля находятся только каталоги Command/ и Query/.
Сообщения, обработчики, модели результатов и необходимые прикладные порты
размещай внутри соответствующего сценария. Провайдеры внешних данных и их
собственные модели результатов находятся в `<UseCase>/Provider/` по
[правилам прикладных провайдеров](domain-actions.md#прикладные-провайдеры).
Application не импортирует
собственные или чужие Presentation/Contracts. Технические механизмы Shared
сохраняют структуру Shared/Application/Bus.

Потребитель объявляет поставщика в `config/dependencies.php` и использует
собственный прикладной провайдер в `Application/Command/<UseCase>/Provider/`
или `Application/Query/<UseCase>/Provider/`. Реализация этого
порта находится в `Infrastructure/Integration/` потребителя: только она
импортирует чужие Presentation/Contracts, получает интерфейс поставщика
через DI и переводит публичные DTO в собственные типы.

Не импортируй чужой Presentation/Adapter и не создавай его вручную.
Не вызывай чужие обработчики и шины напрямую. Сервисы и DI aliases
поставщика регистрируются только в его конфигурации.

## Проверки

- Публичные интерфейсы и DTO находятся в Presentation/Contracts и независимы
  от внутренних слоёв и фреймворков.
- Реализация находится в Presentation/Adapter и связана с интерфейсом DI alias.
- Адаптер вызывает собственные Command/Query через шины и преобразует
  результат Application в публичный DTO без доменных сущностей.
- Application не зависит от Contracts и содержит только Command/Query-сценарии.
- Потребитель использует опубликованный API только через свой
  Infrastructure/Integration при объявленной зависимости.
- Интерфейс провайдера и его собственная модель результата находятся в
  `<UseCase>/Provider/` потребителя и не импортируют Contracts поставщика.
- Интеграционные тесты проверяют разрешение интерфейса через DI, выполнение
  сценария и результат внешнего контракта, включая согласованное отсутствие.
