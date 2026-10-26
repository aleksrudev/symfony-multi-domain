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
Публичные события реализуют типизированный Shared Presentation-контракт метаданных
`IntegrationEventInterface` из `Shared/Presentation/Contracts/Event/`.
Это единственное исключение зависимости Contracts от Shared Contracts;
внутренние Event/Inbox/Outbox маркеры и остальные Shared-типы не разрешены.
Не регистрируй DTO и контракт метаданных как сервисы.

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

В Application бизнес-модуля разрешены только Command/ и Query/.
Command/Query-сообщения, обработчики, модели результатов и необходимые прикладные
порты размещай внутри соответствующего сценария. Провайдеры внешних данных и их
собственные модели результатов находятся в `<UseCase>/Provider/` по
[правилам прикладных провайдеров](domain-actions.md#прикладные-провайдеры).
Application не импортирует
собственные или чужие Presentation/Contracts. Технические механизмы Shared
сохраняют структуру Shared/Application/Bus.

Внутренние доменные события и их listener не являются публичным API модуля.
Публичное интеграционное событие из Presentation/Contracts/Event/ формирует
инфраструктурный listener владельца. Он зависит от собственного Domain
и Contracts и передаёт публичный факт событийной инфраструктуре.
Контракт события остаётся независимым от внутренних шин и их маркеров.
Outbox/Idempotent Consumer — нейтральные инфраструктурные механизмы, не дополнительные публичные API;
их применение определяется [отдельными правилами](transactional-messaging.md).
IntegrationEventDispatcherInterface реализован в Shared Infrastructure:
Dispatcher проверяет метаданные и сохраняет object через OutboxWriter.
Messenger publisher и адаптеры подписчиков связывают нейтральные механизмы
с event.bus. Прежние API и форматы удалены без совместимости.
Снимок данных, метаданные, путь доставки и сериализация определяются
[правилами межмодульных событий](integration-events.md).

Потребитель объявляет поставщика в config/dependencies.php:
sync для интерфейсов/DTO, events для событий/DTO. Оба списка обязательны;
плоский формат запрещён. Циклы запрещены только в sync.
Для синхронного получения данных он использует собственный прикладной провайдер в `Application/Command/<UseCase>/Provider/`
или `Application/Query/<UseCase>/Provider/`. Реализация этого
порта находится в `Infrastructure/Integration/` потребителя: только она
импортирует чужие Presentation/Contracts, получает интерфейс поставщика
через DI и переводит публичные DTO в собственные типы.
Для событий адаптер из Infrastructure/Integration потребителя преобразует
чужой публичный факт в собственные значения и вызывает свой Command/Query
для выполнения реакции после доставки событийным каналом.
Адаптер создаёт MessageIdentity из source/eventId и оборачивает сценарий
в нейтральный Consumer с собственным stable consumerId; retry/failed общие
для события. Эта композиция реализована в Shared и проверяется сквозными PostgreSQL-тестами.
Пара транспортных DTO и дополнительная Inbox-шина на каждый переход не нужны.
Прикладной провайдер только ради подписки не нужен.

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
- Application не зависит от Contracts и Infrastructure и содержит только
  Command/Query-сценарии с собственными DTO, моделями результата и портами.
  Listener событий находятся в Infrastructure владельца; транспортные сообщения
  и handlers внешних интеграций сгруппированы по операциям
  в Infrastructure/Messaging/Inbox|Outbox/. Они не становятся новым каналом
  межмодульных команд.
- Потребитель использует опубликованный API только через свой
  Infrastructure/Integration с соответствующим разрешением sync/events.
- Интерфейс провайдера и его собственная модель результата находятся в
  `<UseCase>/Provider/` потребителя и не импортируют Contracts поставщика.
- Интеграционные тесты проверяют разрешение интерфейса через DI, выполнение
  сценария и результат внешнего контракта, включая согласованное отсутствие.
- Для публичных событий выполни также
  [проверки формирования, сериализации и доставки](checks.md#межмодульные-события).
