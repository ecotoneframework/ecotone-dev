---
type: llm
---
PASS if `PlaceOrder` and `OpenWallet` are handled by `public static` `#[CommandHandler]` methods on the aggregates (Order's returning `self`, Wallet's returning an array of events), and `AddProduct`, `CancelOrder`, `DepositMoney` and `CloseWallet` are handled by non-static instance methods on the aggregates, with no handler or controller code loading or saving the aggregates through a repository. FAIL if a creation handler is an instance method or a constructor-based handler, or if a separate service fetches the aggregate from a repository, calls it and saves it.
