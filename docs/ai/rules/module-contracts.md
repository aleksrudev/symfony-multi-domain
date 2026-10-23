# Контракты модулей и адаптеры

Применяй при создании, изменении и ревью публичного межмодульного API,
его реализаций и интеграций потребителей. Границы слоёв описаны
в [архитектуре](architecture.md), сценарии Application —
в [правилах Command/Query](domain-actions.md).

## Публичный API

Публичные интерфейсы размещай в `src/<Module>/Presentation/Contracts/`,
неизменяемые DTO — в `Presentation/Contracts/Dto/`, межмодульные события —
в `Presentation/Contracts/Event/`. Для событий обязательно прочитай
[правила формирования и доставки](integration-events.md). Например:

```text
Catalog/Presentation/
├── Contracts/
│   ├── CatalogContractInterface.php
│   └── Dto/Offer.php
└── Adapter/
    └── CatalogContractAdapter.php
```

Contracts содержит только опубликованные интерфейсы, DTO и события. Он не зависит
от Domain, Application, Infrastructure, адаптеров, Symfony или Doctrine.
Не раскрывай через него сущности, внутренние QueryResult, обработчики
и другие детали реализации. DTO содержат значения и другие публичные DTO.
Публичные события реализуют пустой Shared Presentation
`IntegrationEventInterface` из `Shared/Presentation/Contracts/Event/`.
Это единственное исключение зависимости Contracts от Shared Contracts;
внутренние Event/Inbox/Outbox маркеры и остальные Shared-типы не разрешены.
Не регистрируй DTO и метку как сервисы.

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

Если автоподключение не создало alias, укажи на CatalogContractAdapter
`#[AsAlias(CatalogContractInterface::class)]` из
`Symfony\Component\DependencyInjection\Attribute\AsAlias`.

В `config/services.yaml` владельца достаточно подключить каталог адаптеров:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\Catalog\Presentation\Adapter\:
        resource: '../Presentation/Adapter/'
```

Повторный YAML alias, `Class: ~` и `public: true` для обычного DI не нужны.
Правило действует также для исходящих адаптеров Infrastructure/Integration
и их прикладных портов. Подробности — в
[правилах регистрации](architecture.md#регистрация-сервисов-и-атрибуты).

Путь вызова: интерфейс → входящий адаптер → command.bus/query.bus →
Application-обработчик → доменный слой. QueryResult преобразуется
в публичный DTO на обратном пути. Отдельный Application/Service для
реализации публичного интерфейса не нужен.

## Application и потребители

В Application бизнес-модуля находятся Command/, Query/, EventListener/ и Message/.
Command/Query-сообщения, обработчики, модели результатов и необходимые прикладные
порты размещай внутри соответствующего сценария. Провайдеры внешних данных и их
собственные модели результатов находятся в `<UseCase>/Provider/` по
[правилам прикладных провайдеров](domain-actions.md#прикладные-провайдеры).
Application не импортирует
собственные или чужие Presentation/Contracts. Технические механизмы Shared
сохраняют структуру Shared/Application/Bus.

Внутренние доменные события и их listener не являются публичным API модуля.
Application listener может создавать сообщение внутреннего продолжения из
Application/Message/ по [правилам доменных событий](domain-actions.md#доменные-события-и-реакции).
Публичное интеграционное событие из Presentation/Contracts/Event/ формирует
инфраструктурный listener владельца. Он зависит от собственного Domain
и Contracts и записывает событие в outbox через общую техническую обёртку.
Контракт события остаётся независимым от внутренних шин и их маркеров.
Listener использует тонкий Shared Infrastructure IntegrationEventDispatcher.
Общий outbox handler передаёт публичный DTO в штатный Symfony EventDispatcher.
Адаптеры потребителей помечаются AsEventListener и только ставят собственные
Inbox-сообщения, а бизнесовые handlers — AsMessageHandler с inbox.bus.
Снимок данных, метаданные и совместимость определяются
[правилами межмодульных событий](integration-events.md).

Потребитель объявляет поставщика в `config/dependencies.php`. Для синхронного
получения данных он использует собственный прикладной провайдер в `Application/Command/<UseCase>/Provider/`
или `Application/Query/<UseCase>/Provider/`. Реализация этого
порта находится в `Infrastructure/Integration/` потребителя: только она
импортирует чужие Presentation/Contracts, получает интерфейс поставщика
через DI и переводит публичные DTO в собственные типы.
Для событий адаптер из Infrastructure/Integration потребителя преобразует
чужой публичный контракт в собственный inbox DTO и вызывает InboxBusInterface.
Бизнесовую реакцию выполняет отдельный Inbox handler. Прикладной провайдер
только ради подписки не нужен.

Не импортируй чужой Presentation/Adapter и не создавай его вручную.
Не вызывай чужие обработчики и шины напрямую. Сервисы и DI aliases
поставщика регистрируются только в его конфигурации.

## Проверки

- Публичные интерфейсы, DTO и события находятся в Presentation/Contracts и независимы
  от внутренних слоёв и фреймворков.
- Реализация синхронного интерфейса находится в Presentation/Adapter
  и связана с интерфейсом DI alias.
- Адаптер синхронного интерфейса вызывает собственные Command/Query через шины и преобразует
  результат Application в публичный DTO без доменных сущностей.
- Application не зависит от Contracts; сценарии, внутренние прикладные listener
  и сообщения размещены в соответствующих каталогах Application. Listener
  формирования публичных событий находится в Infrastructure владельца.
- Потребитель использует опубликованный API только через свой
  Infrastructure/Integration при объявленной зависимости.
- Интерфейс провайдера и его собственная модель результата находятся в
  `<UseCase>/Provider/` потребителя и не импортируют Contracts поставщика.
- Интеграционные тесты проверяют разрешение интерфейса через DI, выполнение
  сценария и результат внешнего контракта, включая согласованное отсутствие.
- Для публичных событий выполни также
  [проверки формирования, совместимости и доставки](checks.md#межмодульные-события).
