# Межмодульные события: общий план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Делегирование — только по отдельному указанию пользователя.

**Goal:** Создать независимые Transactional Outbox и Idempotent Consumer и применить их для событийной доставки через Messenger event.bus.

**Architecture:** OutboxWriter принимает object и через relay передаёт снимок в нейтральный порт доставки. Idempotent Consumer принимает MessageIdentity и callback. IntegrationEventDispatcher, адаптер подписчика и Messenger publisher связывают эти механизмы с публичными событиями; только эта композиция знает event.bus.

**Tech Stack:** Symfony 8, PHP >= 8.4.17, Doctrine ORM 3 / DBAL 4, PostgreSQL 16, PSR Clock, Symfony Messenger с Doctrine transport, PHPUnit, Deptrac, PHPStan.

**Spec:** [Актуализированное архитектурное решение](../specs/2026-10-24-module-events-design.md).

## Global Constraints

- Работа непосредственно в main; автоматические коммиты, push и создание worktree не выполнять.
- Одно приложение, одна БД, один default connection и один EntityManager; сохраняются схемы модулей и запрет доступа к чужим бизнесовым таблицам.
- Межмодульно: синхронные публичный интерфейс/HTTP и асинхронные события. Адресные async-команды не вводятся.
- InboxBus, InboxMessageInterface и inbox.bus удаляются; Transactional Inbox заранее не создаётся.
- Outbox и Consumer находятся в Infrastructure. Их контракты и механизмы не импортируют IntegrationEventInterface, Symfony Messenger или Envelope.
- Общий Application MessageBus/MessageInterface и универсальная маршрутизация под будущие сценарии не создаются.
- Новые зависимости/брокеры и совместимость предыдущих экспериментов не требуются.
- До создания/применения каждой миграции и действий с окружением получить согласование по AGENTS.md. Этап 1 не меняет схему БД и окружение.
- Перед изменениями читать карту, инструкции владельцев и профильные правила. Применять SOLID, GRASP и KISS.

## Review Focus

1. Повтор после commit до ACK не меняет эффект: нейтральная identity на этапе 3, полная доставка на этапе 5.
2. Успешный A и упавший B имеют независимые committed-эффекты при общих retry/failed события: этапы 4–5.
3. Ошибка ORM не переносит чужой UnitOfWork в следующий callback: этап 3 и worker-проверка этапа 5.
4. Outbox работает с plain DTO и подставным publisher без event.bus, Consumer — без payload/Outbox: самостоятельные тесты этапов 2–3.
5. Конкуренция и восстановление не теряют снимок/receipt; смена адаптера не меняет общие контракты: этапы 2–5.

## Этапы и зависимости

| № | План | Результат | Зависимость |
| --- | --- | --- | --- |
| 1 | [Контракты, правила и зависимости](2026-10-26-module-events-stage-1-contracts-and-rules.md) | Контракт публичного события, два графа зависимостей, правила независимости механизмов | Нет |
| 2 | [Transactional Outbox](2026-10-26-module-events-stage-2-transactional-outbox.md) | append(object), нейтральный publisher, собственные storage/migration/relay-тесты | 1; согласование своей миграции |
| 3 | [Idempotent Consumer](2026-10-26-module-events-stage-3-idempotent-consumer.md) | MessageIdentity + callback, собственные storage/migration/тесты | 1; согласование своей миграции; не зависит от 2 |
| 4 | [Композиция с Messenger и удаление старого пути](2026-10-26-module-events-stage-4-messenger-cutover.md) | Единственная рабочая цепочка публичных событий через адаптеры нейтральных механизмов | 2, 3 |
| 5 | [Приёмка, эксплуатация и реестр](2026-10-26-module-events-stage-5-acceptance-and-operations.md) | Сценарии сбоев, проверка границ, инструкции и фактический реестр | 4 |

Этапы 2 и 3 независимо проверяются в отдельных test-kernels.
До этапа 4 их не подключать параллельно текущей публикации.
Этап 4 одним изменением активирует новую цепочку и удаляет заменённую;
промежуточные компоненты не являются готовым переключением runtime.

## Технические решения плана

- `OutboxWriterInterface::append(object $message): void`; Writer и relay не читают метаданные события.
- `OutboxPublisherInterface::publish(object $message): void` — нейтральный порт relay. Сейчас DI связывает его с Messenger-адаптером, которому передаёт event.bus.
- В Outbox хранится только base64 PHP-снимок объекта и техническое состояние доставки. Полей source/event_id/bus/transport/headers нет. Собственные Envelope, serializer registry и выбор каналов в публичном API не вводятся.
- Consumer принимает `consume(string $consumerId, MessageIdentity $identity, callable $reaction): void`. Identity содержит namespace и id.
- Таблицы и миграции раздельные: shared.outbox_messages; shared.processed_messages с ключом (consumer_id, namespace, message_id).
- IntegrationEventDispatcher валидирует публичные метаданные и вызывает append($event). Адаптер подписчика переводит source/eventId в MessageIdentity и вызывает собственный сценарий через Consumer.
- Только Messenger-адаптер зависит от MessageBusInterface. Envelope и transport serializer остаются деталями Messenger; контрактам механизмов они не нужны.
- Relay использует FOR UPDATE SKIP LOCKED. Атомарность отправки и удаления с Doctrine async на том же connection — свойство текущего адаптера; для внешней отправки возможны повторы.
- Метаданные IntegrationEventInterface — читаемые свойства PHP 8.4 eventId/eventAt/source/type/schemaVersion. Их правила принадлежат событийной композиции.
- Retry/failed общие для события; собственных Delivery/очередей на подписчика и исторического replay нет.
- FIFO и порядок одного агрегата не обещаются. Receipt не имеет TTL; безопасная очистка требует отдельного решения.

## Порядок выполнения

- [x] Выполнить этап 1, отличая выбранную архитектуру от фактического runtime.
- [ ] Для каждого компонента выполнить свой цикл RED → GREEN; перед действиями со схемой получить требуемое согласование.
- [x] Проверить этап 2 без IntegrationEvent/Consumer/Messenger (прошёл ревью пользователя).
- [x] Проверить этап 3 без объекта сообщения/Outbox/Messenger (прошёл ревью пользователя).
- [x] Связать компоненты адаптерами на этапе 4 и удалить старые API/config/fixtures (прошёл ревью пользователя).
- [ ] Выполнить приёмку этапа 5 и сверить карту модулей.

## Граница этой задачи

Этап 1 закоммичен после ревью: контракт метаданных, архитектурный анализатор,
fixtures/тесты и AI-инструкции. Этап 2 закоммичен после ревью: f983064.
Этап 3 закоммичен после ревью: 7c0fc7c. Idempotent Consumer имеет отдельный
IdempotentConsumerKernel, собственную миграцию и PostgreSQL-тесты без Outbox/Messenger.
Этап 4 реализован и прошёл ревью пользователя.
Создание/применение каждой миграции к своей test-БД отдельно согласованы пользователем;
pcntl для сигналов согласован и добавлен на этапе 2.
Событийная доставка и регистрация переключены в коде. Make/Compose используют relay;
процессы не перезапускались. Отдельная Messaging test-БД подготовлена с согласования.
После этапа пользователь делает ревью; коммит и переход дальше — после согласования. Новые бизнес-модули, внешний HTTP/email, брокеры
и перенос демо из другой ветки в объём не входят. Возможность переиспользования
подтверждается нейтральными fixtures, а не добавлением рабочих функций на будущее.
