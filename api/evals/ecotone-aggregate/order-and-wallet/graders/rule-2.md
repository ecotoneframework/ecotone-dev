---
type: llm
---
PASS if both `Order` and `Wallet` mark their identity property with `#[Identifier]` (from `Ecotone\Api\Attribute`), and every command handled on an existing instance (`AddProduct`, `CancelOrder`, `DepositMoney`, `CloseWallet`) has a property named exactly like that identifier property (e.g. `orderId`, `walletId`) or marks the property that holds it with `#[TargetIdentifier]`. FAIL if an aggregate has no `#[Identifier]`, or a command for an existing instance carries the id under a different name without `#[TargetIdentifier]` or a handler `identifierMapping`.
