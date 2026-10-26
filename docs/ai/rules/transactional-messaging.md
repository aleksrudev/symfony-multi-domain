# Transactional Outbox, Idempotent Consumer и надёжная доставка

Прочитай перед проектированием, изменением или ревью асинхронной доставки,
публикации событий, приёма внешних сообщений и отложенных внешних эффектов.
Границы слоёв — в [архитектуре](architecture.md), публичные факты —
в [правилах межмодульных событий](integration-events.md).

## Назначение и статус

Transactional Outbox сохраняет исходящее намерение атомарно с бизнесовыми
данными. Idempotent Consumer атомарно фиксирует отметку обработки и локальный
эффект. Это независимые инфраструктурные механизмы; их первое применение —
интеграционные события. Общая Application-шина сообщений для них не создаётся.

В main реализованы контракт метаданных IntegrationEvent и разделение графов
sync/events. Нейтральные Outbox и Consumer реализованы и проверяются в отдельных
test-kernels; в приложение они ещё не подключены. Messenger event.bus не реализована.
Последовательность подключения описана в [плане этапов](../../superpowers/plans/2026-10-26-module-events-implementation.md).
Текущие Shared/Application/Bus/Inbox и Outbox и композиция
Outbox → EventDispatcher → Inbox — заменяемый runtime, не образец для нового кода.
При подключении новой доставки удаляются его API/DTO/config/test paths целиком;
совместимые aliases, fallback и чтение форматов предыдущего эксперимента не нужны.

Межмодульно разрешены синхронный публичный интерфейс/HTTP и асинхронные
публичные события. Адресные async-команды другим модулям запрещены.
Переиспользуемость технического механизма не расширяет межмодульный протокол.

## Где применять

| Ситуация | Механизм | Адаптация владельца |
| --- | --- | --- |
| Публичное событие вместе с изменением состояния | Transactional Outbox | IntegrationEventDispatcher проверяет факт и вызывает нейтральный Writer |
| Реакция подписчика после доставки | Idempotent Consumer | Адаптер переводит source/eventId в MessageIdentity и вызывает свой сценарий |
| Отложенный HTTP/email/иная внешняя операция | Outbox при конкретной потребности | Адаптер доставки и стабильный ключ внешнего эффекта |
| Повторно доставляемый webhook/сообщение внешней системы | Idempotent Consumer при транзакционном локальном эффекте | Адаптер определяет namespace/message ID и момент подтверждения |
| Раннее подтверждение приёма до последующей обработки | Отдельный долговечный входной журнал, если требуется | Проектируется для конкретной интеграции; в текущие этапы не входит |
| Query, синхронный вызов, локальное доменное событие или запуск Scheduler | Не требуют паттернов автоматически | Требования определяет сценарий |

Новые внешние интеграции и полный Transactional Inbox заранее не создавай.
InboxBus удаляется, полноценный Inbox не используется как его автоматическая замена.
Обычная очередь или processed-ID журнал не являются всем Transactional Inbox.

## Outbox: исходящее намерение и бизнесовые данные

Целевой контракт в Shared Infrastructure:
`OutboxWriterInterface::append(object $message): void`.

1. В текущей default-транзакции немедленно сохранить самостоятельный снимок
   сериализуемого сообщения. Снимок не перечитывается из агрегата в relay.
2. Ошибка сериализации/INSERT прерывает source-операцию. При rollback
   не остаётся доступного к доставке намерения; при commit оно переживает остановку.
3. Отдельный relay после commit передаёт восстановленный object в
   `OutboxPublisherInterface::publish(object $message): void`.
4. Запись удаляется только после успешной передачи с учётом её транзакционной
   границы. Отказы повторяются и доступны для ручного восстановления.

Writer/relay не читают eventId/eventAt/source/type/schemaVersion, не импортируют
IntegrationEventInterface, Symfony Messenger или Envelope. Порт publisher
задаёт способ передачи через DI; transport/bus/headers не входят в общий API.
Собственный Envelope, универсальный router и новый codec registry не вводятся.

Технический снимок текущей реализации — base64 штатного PHP serialize объекта.
В shared.outbox_messages находятся body и техническое состояние доставки;
event_id/source и поля Messenger там не создаются. Outbox имеет собственную
миграцию и не требует таблицы Consumer. Бизнесовой ORM-модели Shared нет.

Запись после commit, буфер только в памяти, DispatchAfterCurrentBusStamp
и best-effort HTTP/брокер внутри source-транзакции не заменяют Outbox.
Writer не вызывает flush/commit. Не подавляй ошибку сохранения.

Для текущего Messenger-адаптера постановка в Doctrine async и удаление Outbox
атомарны на default connection. Это свойство композиции, а не универсальная
гарантия любого publisher. Внешняя передача может повториться после сбоя
между отправкой и фиксацией успеха. Failed Outbox не доказывает отсутствие
внешнего эффекта. Глобальный FIFO и порядок одного агрегата не предполагаются.

## Idempotent Consumer: отметка и локальный эффект

Контракт Consumer:
`consume(string $consumerId, MessageIdentity $identity, callable $reaction): void`,
где callback имеет тип `callable(): void`. MessageIdentity — readonly namespace/id
в Infrastructure/Messaging/IdempotentConsumer/Identity/. Интерфейс и
Doctrine-реализация находятся в соседнем Consumer/.
Все три идентификатора содержат 1–190 символов UTF-8 без NUL и не нормализуются.

Consumer не получает payload, IntegrationEvent или Envelope и не вызывает
CommandBus сам. Callback задаёт конкретную операцию; Consumer обеспечивает
уникальность и транзакционную границу.

- Ключ shared.processed_messages — `(consumer_id, namespace, message_id)`.
  Для события адаптер передаёт namespace = source и id = eventId.
- consumerId — устойчивый ID конкретной обработки, не имя модуля или PHP-класса.
  Разные реакции одного модуля имеют разные IDs.
- Конкуренцию защищает уникальный ключ и INSERT ... ON CONFLICT DO NOTHING,
  а не отдельный SELECT перед INSERT.
- Callback выполняется только после успешного INSERT; callback, flush и receipt
  коммитятся или откатываются вместе. Повтор после успеха callback пропускает.
- Выбранная Doctrine-реализация владеет внешней транзакцией обработки.
  Уже активная транзакция отклоняется; command.bus внутри callback использует
  тот же connection и не фиксирует receipt раньше внешнего commit.
- Doctrine-реализация делает flush/clear всего default UnitOfWork.
  Изменения реакции выполняются внутри callback; незавершённые изменения
  другого сценария на входе не изолируются и недопустимы. Callback не подменяет
  manager и не управляет внешним commit/rollback; вложенные транзакции завершает.
- После завершения/rollback очищается открытый UnitOfWork. На закрытом EM
  callback не запускается; восстановление между доставками принадлежит
  вызывающей инфраструктуре.
- Receipt не имеет автоматического TTL. Без отдельной политики допустимых
  повторов и восстановления очистка запрещена.
- Неизменность содержимого под одной identity — обязательство протокола/адаптера.
  Отдельный fingerprint-журнал базовой реализации не требуется.

У Consumer своя миграция и независимые тесты без Outbox/Messenger/IntegrationEvent.
Это Idempotent Consumer, а не долговечный приём всего Transactional Inbox.
Дубликаты разных IDs одной операции защищают смысловые инварианты владельца.

## Применение внутри событийного канала

Публичный вход — IntegrationEventDispatcherInterface с IntegrationEventInterface.
Dispatcher проверяет метаданные и вызывает Writer. MessengerOutboxPublisher
реализует нейтральный порт relay; DI внедряет ему event.bus.
Только транспортный адаптер зависит от MessageBusInterface.
Routing IntegrationEventInterface → async задаётся Messenger.

Адаптер подписчика находится в Infrastructure/Integration/<Owner>/,
регистрируется AsMessageHandler с bus event.bus и создаёт MessageIdentity
из метаданных факта. Callback вызывает собственный Command/Query.
Бизнесовые решения остаются в Application/Domain; промежуточных Inbox DTO нет.

На event.bus не добавляй общую doctrine_transaction: каждая реакция должна
иметь свой commit. Уже успешный A сохраняется при ошибке B, однако retry/failed
общие для события. Не обещай независимых очередей или бюджетов повторов.
HandledStamp не заменяет receipt: повтор свежего сообщения без штампа тоже безопасен.

Подписчики определяются при получении. Событие без handlers допустимо;
исторического replay для новых подписчиков нет. Точная конфигурация и проверки —
в [правилах событий](integration-events.md).

## Внешние интеграции и границы ответственности

Внешний формат переводит входной адаптер владельца. Если требуется ранний ACK
с дальнейшей обработкой, надо отдельно спроектировать сохранение входящего
снимка, восстановление pending и транзакционный эффект. Такой Inbox в текущую
работу не входит. При обработке до ACK после commit может быть достаточно
Idempotent Consumer и долговечного транспорта получателя.

Прикладной порт отражает возможность сценария; очереди, ACK и общие технические
API не раскрываются Application или публичным Contracts.
Транзакция БД не делает внешний HTTP/email exactly-once. Для такого эффекта
нужен стабильный idempotency key получателя либо сверка результата.

## Проверка решения

- Указаны конкретный сбой, владелец, identity, snapshot, транзакция и момент ACK.
- Outbox проверяется нейтральным DTO и подставным publisher; Consumer —
  identity/callback без события и транспорта.
- Проверены rollback, видимость после commit через другое PostgreSQL-соединение,
  конкуренция relay/consumer и восстановление failed текущего формата.
- Receipt и эффект атомарны, повтор после commit до ACK безопасен.
  ORM rollback не переносит чужой UnitOfWork в следующий callback.
- Отдельно проверяется композиция с Messenger: async INSERT/delete Outbox
  на одном connection и общие retry/failed нескольких handlers.
- Нет обещаний FIFO, сквозного exactly-once и независимых retry подписчиков.
- Старые API/config/test paths удаляются при переключении; совместимости нет.

Исходные определения: [Transactional Outbox](https://microservices.io/patterns/data/transactional-outbox.html),
[Idempotent Consumer](https://microservices.io/patterns/communication-style/idempotent-consumer.html).
Эти источники не требуют установки дополнительных библиотек.
