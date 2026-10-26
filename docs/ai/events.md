# Реестр публичных межмодульных событий

## Содержание

- [Ordering](#ordering)
  - [ordering.order_submitted / v1](#orderingorder_submitted--v1)

## Ordering

### ordering.order_submitted / v1

**Источник:** `Ordering`

**Статус:** active

**Контракт:** [OrderSubmittedEvent.php](../../src/Ordering/Presentation/Contracts/Event/OrderSubmittedEvent.php)

**Публикация:** [PublishOrderSubmittedEventListener.php](../../src/Ordering/Infrastructure/Messaging/EventListener/PublishOrderSubmittedEventListener.php)

**Факт:** Заказ успешно оформлен и переведён в статус ожидания проверки наличия (`pending_stock`) с фиксацией состава, снимков цен товаров, итоговой суммы и валюты.

**Когда возникает:** При успешном выполнении команды оформления заказа `SubmitOrderCommand` и публикации внутреннего доменного события `OrderSubmittedEvent`.

**Данные:** Идентификатор заказа (`orderId`), идентификатор покупателя (`buyerId`), список позиций (`items`: `productId`, `quantity`, `productName`, `priceAmount`), сумма заказа в копейках (`totalAmount`) и валюта (`currency`). Точные типы и поля — в контракте.

#### Подписчики

| ID реакции | Модуль | Назначение | Адаптер → сценарий |
| --- | --- | --- | --- |
| inventory.reserve-stock-on-order-submitted | Inventory | Резервирование позиций заказа на складе | [OrderSubmittedReservationConsumer.php](../../src/Inventory/Infrastructure/Integration/Ordering/OrderSubmittedReservationConsumer.php) → [ReserveStockCommand.php](../../src/Inventory/Application/Command/ReserveStock/ReserveStockCommand.php) |

#### Особенности реакций

- `inventory.reserve-stock-on-order-submitted`: Идемпотентность обеспечивается общим `IdempotentConsumerInterface` по составному ключу `MessageIdentity(source, eventId)`. Обработка создаёт запись резерва `Reservation` в статусе `confirmed` или `rejected` и обновляет `reserved` у `StockPosition`.

#### Проверки процесса

- [OrderSubmissionOutboxDeliveryTest.php](../../tests/Integration/Ordering/OrderSubmissionOutboxDeliveryTest.php)
