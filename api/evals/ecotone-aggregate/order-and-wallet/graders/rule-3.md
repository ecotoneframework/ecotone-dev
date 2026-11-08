---
type: llm
---
PASS if `Wallet` is an `#[EventSourcingAggregate]` whose command handlers only return arrays of event objects (or throw for the closed-wallet case) without assigning properties, and whose `#[Apply]` methods hold every state change, including assigning the wallet id in the `#[Apply]` for the wallet-opened event; `#[Apply]` methods have no side effects. FAIL if a Wallet command handler writes `$this->...` state, if no `#[Apply]` assigns the identifier, or if an `#[Apply]` method sends messages, calls services or throws business errors.
