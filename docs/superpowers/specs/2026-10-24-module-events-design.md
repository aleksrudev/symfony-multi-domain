# Синхронные контракты и надёжные межмодульные события

Дата: 2026-10-24. Направление и границы механизмов актуализированы: 2026-10-26.
Статус: архитектура реализована, все пять этапов выполнены и прошли ревью пользователя.
Реализованы метаданные и графы sync/events (этап 1), нейтральные Outbox и Consumer
в отдельных test-kernels (этапы 2–3). На этапе 4 реализованы композиция через
Messenger event.bus, регистрация механизмов в Shared и удаление прежнего пути.
Этап 5 завершён: PostgreSQL-приёмка сбоев, эксплуатационные инструкции и реестр
событий. Приёмка не изменяет production API и не применяет миграции к dev/prod.

Используем штатный Messenger event.bus, нейтральный Transactional Outbox
и Idempotent Consumer. Протокол IntegrationEvent и Messenger соединяются
с механизмами надёжности через инфраструктурные адаптеры.
Outbox принимает object; Consumer — MessageIdentity и callback.
Envelope в их API и собственный аналог Envelope не вводятся.

Порядок работ — [общий план и пять этапов](../plans/2026-10-26-module-events-implementation.md).
AI-инструкции согласованы с этим решением на этапе 1.
Событийная композиция подключена; полная приёмка выделена в этап 5.

## 1. Контекст и согласованные принципы

- Одно Symfony-приложение, одна PostgreSQL БД, один default connection
  и EntityManager; у бизнес-модулей собственные схемы.
- Межмодульно доступны синхронный публичный интерфейс/HTTP
  и асинхронные публичные события. Адресные async-команды не вводятся.
- Локальные Command/Query выполняют сценарии владельца. Синхронная доменная
  шина остаётся локальной, работает до commit и не становится async-каналом.
- InboxBus удаляется. Долговечная очередь и processed-ID журнал
  не называются Transactional Inbox.
- Outbox и Idempotent Consumer относятся к Infrastructure и переиспользуются
  независимо от IntegrationEvent. Их контракты/механизмы не импортируют
  IntegrationEventInterface, Symfony Messenger или Envelope.
- Outbox атомарно сохраняет снимок произвольного поддерживаемого объекта
  вместе с бизнесовыми данными. Relay передаёт объект в подставляемый порт.
- Consumer атомарно фиксирует отметку идентичности и эффект callback.
  Тип payload и транспорт ему неизвестны.
- IntegrationEventDispatcher проверяет публичный факт и вызывает OutboxWriter.
  Адаптер подписчика преобразует source/eventId в MessageIdentity.
- Отдельный Messenger publisher, собранный с event.bus через DI, реализует
  порт доставки Outbox. Routing IntegrationEventInterface → async.
- Эффекты подписчиков имеют отдельные receipt/транзакции; retry/failed
  относятся к общей доставке события. Доставка допускает повторы.
- Граф sync ацикличен, взаимные подписки допустимы. Причинные циклы
  ограничиваются предметными переходами и идемпотентностью.
- Прежние API/DTO/config/test paths заменяются целиком без совместимости.
- Публичные события и подписчики документируются в docs/ai/events.md.

Фактический main содержит только Shared; Kernel::moduleDirectories() пуст.
Alpha/Beta в tests — технические fixtures. Создание бизнес-модулей и перенос
демо из другой ветки в эту работу не входят. Публичных бизнесовых событий
приложения пока нет.

## 2. Почему меняем нынешний путь

Shared/Application/Bus/Inbox только отправляет сообщения в Messenger:
это не долговечный журнал приёма с дедупликацией. Нынешний Outbox умеет
атомарную запись в Doctrine transport, но его прикладной API и композиция
Outbox → native EventDispatcher → Inbox смешивают роли.

Прикладной протокол определяет, что произошло. Надёжность хранения/повторов
и защита локального эффекта не должны зависеть от вида этого протокола.

| Подход | Статус |
| --- | --- |
| Адресные межмодульные async-команды | Отклонён из-за связности |
| Собственный registry/fanout с Delivery на реакцию | Заменён более простым решением |
| Переход на RabbitMQ + Messenger | Не принят; брокер не добавляется |
| Outbox/Consumer принимают IntegrationEventInterface или Envelope | Заменён нейтральными контрактами |
| Messenger event.bus + нейтральные механизмы + адаптеры IntegrationEvent | Выбранное направление |

## 3. Композиция и ответственность слоёв

```text
собственная Command / Domain Action
  → локальное доменное событие
  → инфраструктурный listener владельца
  → публичный IntegrationEvent DTO
  → IntegrationEventDispatcher: проверка контракта
  → OutboxWriter.append(object) в source-транзакции
  → commit
  → OutboxRelay → OutboxPublisherInterface
  → Messenger-адаптер, собранный с event.bus
  → event.bus → Doctrine async
  → адаптер подписчика: source/eventId → MessageIdentity
  → IdempotentConsumer.consume(consumerId, identity, callback)
  → собственная Command внутри callback
```

Публичный факт находится в Presentation/Contracts/Event/ владельца,
вложенные DTO — в Presentation/Contracts/Dto/. В нём нет внутренних моделей,
очередей и адресатов. Listener экспортирует только выбранные доменные факты.
Domain/Application не импортируют события Presentation или инфраструктурные
контракты надёжности.

Точка публикации в Shared/Infrastructure/Messaging/:

```php
interface IntegrationEventDispatcherInterface
{
    public function dispatch(IntegrationEventInterface $event): void;
}
```

OutboxIntegrationEventDispatcher проверяет метаданные и внедряет
`OutboxWriterInterface::append(object $message): void`. Он не формирует Envelope
и не выбирает очередь. Writer требует активную default-транзакцию, сохраняет
снимок без flush/commit; ошибка записи прерывает source-операцию.
Подписчики выполняются после commit, не во время вызова dispatch.

MessengerOutboxPublisher в Shared/Infrastructure/Messaging/Messenger/
реализует `OutboxPublisherInterface::publish(object $message): void`.
Он внедряет MessageBusInterface; текущая DI-композиция передаёт event.bus.
Имя шины не зашито в нейтральном relay, порте, сообщении или таблице Outbox.
Envelope и transport serializer остаются деталями Messenger.

## 4. Метаданные IntegrationEvent и графы зависимостей

IntegrationEventInterface в Shared Presentation содержит читаемые свойства
PHP 8.4, реализуемые public readonly-свойствами DTO:

```php
public string $eventId { get; }
public string $eventAt { get; }
public string $source { get; }
public string $type { get; }
public int $schemaVersion { get; }
```

Это контракт публичного факта, а не требование к каждому сообщению Outbox.
ID/source/type непустые, до 190 символов; schemaVersion >= 1.
eventAt — UTC `Y-m-d\TH:i:s.u\Z`, время факта. Метаданные проверяет
IntegrationEventDispatcher; общий Writer/Consumer их не извлекает.
Повтор события сохраняет identity и содержимое.

config/dependencies.php потребителя:

```php
return [
    'sync' => ['Catalog'],
    'events' => ['Inventory'],
];
```

sync A → B открывает A публичные интерфейсы/DTO B; events A → B —
публичные события/DTO B. Импорт доступен только Infrastructure/Integration
потребителя. Contracts-слой анализатора разделяется на интерфейсы, Event и Dto.

Циклы проверяются только в sync; взаимные events и смешанные пути допустимы.
Неизвестные модули, самозависимость, Shared как бизнес-поставщик, неизвестные
ключи и прежний плоский формат отклоняются. Отключённые модули тоже проверяются.
Fallback для старого формата не создаётся.

## 5. Независимый Transactional Outbox

Паттерн принадлежит Shared/Infrastructure/Messaging/Outbox/.
Нейтральные контракты:

```php
interface OutboxWriterInterface
{
    public function append(object $message): void;
}

interface OutboxPublisherInterface
{
    public function publish(object $message): void;
}
```

Writer не публикует сообщение немедленно; publisher вызывается relay.
Порт publisher — инфраструктурная возможность доставки, не новая прикладная шина.
Его реализация определяет способ передачи. Сейчас нужен один Messenger-адаптер;
registry транспорта, имена каналов в API и реализации на будущее не создаются.

Технические решения текущего плана:

- DoctrineOutboxWriter внедряет default Connection и PSR Clock. append требует
  активную транзакцию и немедленно сохраняет `base64_encode(serialize($message))`.
  Снимок сохраняет PHP-типы/вложенные значения; base64 допускает бинарные данные
  в PostgreSQL TEXT. Свой Envelope и отдельный codec framework не нужны.
- shared.outbox_messages содержит только id, body, created_at/available_at,
  attempts, failed_at/last_error. Полей source/event_id/bus/transport/headers нет.
  У Outbox собственная миграция; ORM-сущность и таблица Consumer ему не нужны.
- `OutboxRelay::relayOne(): bool` выбирает готовую запись через
  `ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED`, восстанавливает object,
  вызывает OutboxPublisherInterface и удаляет строку в своей транзакции.
- Вызов publisher и удаление находятся во вложенном savepoint.
  При отказе savepoint откатывается, внешняя блокировка остаётся для записи
  результата попытки. Сбой внешнего commit не считается сохранённым успехом.
- Декодирование принимает только корректный снимок поддерживаемого объекта.
  Неизвестный PHP-класс/неполное восстановление считаются отказом; старые форматы
  не поддерживаются. Payload берётся из собственного хранения Outbox.
- Задержки трёх повторов после первой попытки: 1000, 3000, 9000 мс.
  После четвёртого отказа сохраняется failed_at; body не теряется.
  Ошибка соединения прерывает процесс с ненулевым exit code.
- `app:outbox:relay --limit=100 --time-limit=3600 --sleep=1000` запускает relay.
  `app:outbox:retry <id>` восстанавливает одну failed-запись, сохраняя body.
  make outbox-worker и одноимённый Compose worker используют relay-команду.
- Логи содержат ID записи/попытку/класс ошибки без payload и без обязательного
  знания eventId/source.

При текущем Messenger-адаптере Doctrine async использует тот же connection:
постановка в очередь и удаление исходящей записи атомарны. Это проверяется
в композиции на этапе 4. Общий порт доставки не обещает такую атомарность
для внешнего HTTP/брокера: после отправки до отметки успеха возможен повтор.
Failed Outbox означает отсутствие зафиксированного завершения relay,
а не доказательство отсутствия внешнего эффекта.

Создание/применение миграций и действия с окружением согласуются по AGENTS.md.
Глобальный FIFO и порядок одного агрегата не обещаются: при необходимости
сценарий контролирует версии/переходы состояния. Сквозной exactly-once нет.

## 6. Независимый Idempotent Consumer

Паттерн принадлежит Shared/Infrastructure/Messaging/IdempotentConsumer/.
Контракт не принимает payload и не знает его типа:

```php
final readonly class MessageIdentity
{
    public function __construct(
        public string $namespace,
        public string $id,
    ) {
        // Непустые значения, до 190 символов.
    }
}

interface IdempotentConsumerInterface
{
    /** @param callable(): void $reaction */
    public function consume(
        string $consumerId,
        MessageIdentity $identity,
        callable $reaction,
    ): void;
}
```

Namespace — область уникальности произвольного протокола/источника:
например, provider.webhook и batch.import. consumerId — стабильный ID обработки;
не выводится автоматически из имени класса. Все три строки непустые,
до 190 символов. Схема shared.processed_messages: consumer_id, namespace,
message_id, processed_at; primary key `(consumer_id, namespace, message_id)`.
У Consumer своя миграция; Outbox для её выполнения не нужен.

DoctrineIdempotentConsumer:

1. Владеет внешней транзакцией callback; при уже активной транзакции отказывает.
   Требует открытый default EntityManager на том же Connection, что у receipt.
   Flush/clear охватывает весь default UnitOfWork; pending-изменений другого
   сценария на входе быть не должно, ORM-изменения реакции выполняются в callback.
   Это политика данной реализации,
   не требование какого-либо транспорта.
2. Выполняет INSERT ... ON CONFLICT DO NOTHING. Конкуренцию разрешает
   уникальность БД, не предварительный SELECT. При 0 вставленных строк
   callback пропускается.
3. После успешного INSERT выполняет callback, flush и commit.
   Ошибка callback/flush/commit откатывает receipt и эффект.
4. После завершения/rollback очищает открытый UnitOfWork. На закрытом EM
   отказывает без нового receipt; восстановление между доставками —
   ответственность вызывающей инфраструктуры.

Внутри callback может выполняться собственная Command с тем же connection;
Consumer не импортирует CommandBusInterface. В композиции receipt, локальные
данные и новые исходящие намерения фиксируются атомарно.

Именно адаптер IntegrationEvent-подписчика создаёт
`new MessageIdentity(namespace: $event->source, id: $event->eventId)` и callback.
Consumer не обращается к IntegrationEventInterface, Messenger или Outbox.
Отдельного fingerprint-журнала нет; неизменность identity/payload —
обязательство соответствующего протокола. Receipt не имеет TTL.
Смысловые дубли разных IDs защищаются бизнесовыми инвариантами.

## 7. Messenger-композиция, повторы и подписки

- Единственная новая Messenger-шина — event.bus. Routing IntegrationEventInterface
  → async; async/failed — Doctrine transport на default connection.
- Стандартные middleware включены; allow_no_handlers = true,
  allow_no_senders = false; validation и doctrine_ping_connection.
- Общей doctrine_transaction на event.bus нет: реакциям нужны отдельные commits.
- Стандартный handler потребителя находится в Infrastructure/Integration/<Owner>/,
  преобразует identity и вызывает свой сценарий через нейтральный Consumer.
- У события общие 3 retry и failed; отдельных Delivery/очередей на реакцию нет.
  Неуспешный handler может исчерпать общий бюджет.
- HandledStamp может пропускать успешные handlers при обычном retry;
  долговечная защита основана на receipt и работает без штампов, включая сбой до ACK.
- При закрытом EM штатный worker reset/doctrine_ping_connection восстанавливает
  инфраструктуру перед следующей доставкой. Consumer не импортирует этот механизм.
- Handlers выбираются Messenger при получении; новая подписка может получить
  ещё не ACK-событие, но исторического replay и архива нет.
- Событие без handlers проходит Outbox/async и успешно ACK.
- Успех A не откатывается из-за B; это независимость эффектов при общей доставке.
  Восстановление — штатный messenger:failed:retry с прежним событием.

Native PHP-снимок Outbox и штатный transport serializer Messenger — разные
границы. Обе сохраняют текущие DTO/вложенные значения. Форматы предыдущих
экспериментов, upcasters и совместимые маршруты не поддерживаются.

## 8. Другие сценарии и границы объёма

Переиспользуемость проверяется нейтральными DTO, publisher и callback.
Рабочие HTTP/email/webhook-интеграции на будущее не создаются; при конкретной
потребности владелец подставляет адаптер доставки/identity.
Это не открывает адресные межмодульные async-команды.

Локальные Command/Query и Scheduler сами по себе не требуют Outbox/Consumer.
Scheduler-fixture вызывает собственный ProbeCommand вместо ProbeInbox в async.
Native EventDispatcher остаётся локальным синхронным механизмом и технической
шиной событий worker.

Receipt БД не делает внешний HTTP атомарным с локальным эффектом.
Владельцу такой интеграции нужны idempotency key получателя или сверка.
Полноценный Transactional Inbox, внешние интеграции, новые зависимости,
брокеры, бизнес-модули и перенос демо вне объёма.

## 9. Реестр публичных событий и подписчиков

Создать docs/ai/events.md как документационный реестр внешних для модулей,
то есть публичных межмодульных событий. Он описывает фактическое состояние
приложения и не является конфигурацией маршрутизации или регистрации listener.
Локальные доменные события и технические сообщения очередей в него не входят.

При наличии событий в начале файла — оглавление по модулям-издателям.
Далее события группируются по издателю; каждой версии соответствует одна
карточка. Отдельная сводная таблица подписок не создаётся, чтобы не дублировать
сведения.

Идентичность карточки — `(source, type, schemaVersion)`. Идентичность подписки —
стабильный логический ID реакции. Разные реакции одного модуля на один факт
занимают отдельные строки. Эти ID соответствуют consumerId Idempotent Consumer;
переименование PHP handler само по себе не меняет ID реакции.

Фиксированный шаблон карточки:

```markdown
## <Модуль-издатель>

### <type> / v<schemaVersion>

**Источник:** `<source>`

**Статус:** active

**Контракт:** <ссылка на публичный PHP DTO>

**Публикация:** <ссылка на инфраструктурный listener>

**Факт:** <самостоятельный факт на языке владельца>

**Когда возникает:** <операция, переход состояния и условия публикации>

**Данные:** <назначение данных, единицы измерения и существенные ограничения>.
Точные типы и поля — в контракте.

#### Подписчики

| ID реакции | Модуль | Назначение | Адаптер → сценарий |
| --- | --- | --- | --- |
| <стабильный ID> | <модуль> | <бизнесовый эффект> | <ссылки на код> |

#### Особенности реакций

- `<ID реакции>`: <смысловой ключ идемпотентности,
  требования к порядку или другие ограничения сценария>.

#### Проверки процесса

- <ссылки на тесты обязательных реакций и завершения процесса>.
```

Если подписчиков нет, вместо пустой таблицы явно написать «Подписчиков нет».
Если для события нет особенностей реакций или проверок процесса, это также
указывается явно; отсутствие сведений не подменяется вымышленными гарантиями.
Наличие карточки не означает, что бизнес-процесс прошёл приёмку.

Статус active означает действующий контракт. При замене архитектуры удалённые
контракты и подписки убираются из реестра вместе с кодом; deprecated-период
ради совместимости не вводится. Планируемые события и подписки остаются
в проектных документах и не добавляются в реестр как существующие.

Начальный реестр для main содержит явную запись «Публичных межмодульных
событий и подписчиков пока нет». Оглавление издателей и карточки отсутствуют
до появления реальных бизнес-модулей с такими контрактами. Shared-метка
IntegrationEventInterface сама по себе не является опубликованным событием.
События и реакции технических fixtures Alpha/Beta в реестр не включаются.
Иллюстративные события демо-фичи добавляются только вместе с их фактической
реализацией в приложении в рамках отдельной задачи.

Полную схему DTO не копировать: источником точных PHP-типов и полей остаётся
код, а реестр объясняет смысл и ограничения. Ссылки ведут на существующие
файлы относительно docs/ai/events.md, без привязки к номерам строк.
Общие гарантии транзакций, повторов, дедупликации и сериализации описаны
в rules/integration-events.md и не повторяются в каждой карточке.

Распределение ответственности документации:

| Файл | Назначение |
| --- | --- |
| docs/ai/map.md | Модули, их ответственность и ссылка на реестр событий |
| docs/ai/events.md | Публичные факты и фактические подписки с назначением реакций |
| docs/ai/rules/integration-events.md | Правила разработки и гарантии доставки |
| src/<Module>/AGENTS.md | Особенности владельца и ссылки на его карточки |

При создании реестра заменить дублируемые перечни событий и подписок в карте
и модульных инструкциях ссылками на него, сохранив собственные инварианты
и ответственность модулей. Общие инструкции должны требовать чтения реестра
перед изменением публичного события или реакции на него.

Добавление, изменение и удаление публикации или подписки обновляет реестр
в том же изменении кода. Поддержка удалённых контрактов и сообщений предыдущих
экспериментов не требуется. При ревью сверяются контракт, место публикации, адаптер,
собственный сценарий и декларация events в config/dependencies.php.

## 10. Переход и критерии приёмки

Реализация разделена на [пять этапов](../plans/2026-10-26-module-events-implementation.md):

1. Контракт IntegrationEvent, два графа зависимостей и согласование AI-инструкций.
2. Нейтральный Outbox: append(object), отдельная схема, publisher-порт и relay.
3. Нейтральный Consumer: MessageIdentity/callback и собственная схема.
4. Адаптеры событий/Messenger и одновременное удаление старой доставки.
5. Приёмка PostgreSQL, проверка границ, реестр и эксплуатация.

Этапы 2 и 3 независимо тестируются в своих kernels, не зависят друг от друга
и не включают событийную композицию. Рабочий новый путь подключается только
на этапе 4. Удаляются прежние Shared/Application/Bus/Inbox и Outbox, их реализации,
IntegrationEventMessage/IntegrationEventHandler, inbox.bus/outbox.bus,
старый outbox transport и зависимые fixtures. Совместимости нет.

Приёмка:

- Outbox сохраняет plain DTO без marker/event-метаданных; Consumer принимает
  identity/callback без payload. У механизмов нет импортов IntegrationEvent/Messenger.
- Source rollback, отказ serialize/INSERT и невидимость снимка до commit
  проверяются отдельным PostgreSQL connection.
- Relay: блокировки, конкуренция, отказ publisher и failed/recovery без потери body.
- Адаптер Doctrine async: постановка в очередь и удаление Outbox атомарны.
- Publisher можно подставить без изменения Writer/relay; MessageBus и имя event.bus
  задаются только в Messenger-композиции.
- Преобразование source/eventId в namespace/message_id выполняется адаптером.
- Receipt и эффект callback атомарны, конкурирующие consumer не удваивают эффект.
- Полная цепочка проверяет commit A/rollback B, общие retry/failed, сбой до ACK,
  свежий Envelope без HandledStamp, ошибку ORM и штатный reset.
- Receipt, бизнесовый эффект и новое исходящее событие реакции атомарны.
- Нет handlers, выбор подписок при получении и отсутствие replay описаны явно.
- Архитектурные границы, новый dependencies.php и отсутствие старых API проверены.
- Реестр содержит только реальные события приложения; карта и инструкции актуальны.

Команды проверок и согласование подготовки test-БД определены
в [правилах проверок](../../ai/rules/checks.md).
SKIP PostgreSQL-приёмки не считается доказательством гарантий.

## Источники

- [Symfony 8 Messenger: шины, маршрутизация, транзакции и повторы](https://symfony.com/doc/8.0/messenger.html).
- [Transactional Outbox](https://microservices.io/patterns/data/transactional-outbox.html).
- [Idempotent Consumer](https://microservices.io/patterns/communication-style/idempotent-consumer.html).
- [PostgreSQL 16: SELECT и SKIP LOCKED](https://www.postgresql.org/docs/16/sql-select.html).
- [PHP: readable-свойства интерфейсов](https://www.php.net/manual/en/language.oop5.interfaces.php).
- [PHP: serialize и бинарный формат снимка](https://www.php.net/manual/en/function.serialize.php).
