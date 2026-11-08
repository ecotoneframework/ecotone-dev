---
type: llm
---
PASS if `PlaceOrder` has exactly one `#[CommandHandler]` and the stock reservation and the e-mail are each `#[EventHandler]` methods reacting to `OrderWasPlaced` (in InventoryService and CustomerNotifier). FAIL if a second `#[CommandHandler]` for `PlaceOrder` is added (e.g. in InventoryService), if `GetOrderStatus` has more than one query handler, or if the reactions are done by calling the services directly from the command handler instead of handling the event.
