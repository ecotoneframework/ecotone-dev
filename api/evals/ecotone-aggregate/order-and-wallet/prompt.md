---
description: "Writing a state-stored Order aggregate that publishes an event and an event-sourced Wallet aggregate"
tags: [ecotone-aggregate]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Symfony app with Ecotone 2.0 and want two aggregates.

1. `Order`, stored as current state (Doctrine or the document store, I'll configure that later). It is created by a `PlaceOrder` command with an order id, a customer id and a list of product ids. It can then receive `AddProduct` (adds one product id) and `CancelOrder`. When an order is cancelled, other parts of the app must be told through an `OrderWasCancelled` event so they can refund the customer.
2. `Wallet`, event-sourced. `OpenWallet` creates it for a wallet id and an owner; `DepositMoney` adds an amount; a deposit on a closed wallet must be rejected. `CloseWallet` closes it.

Write the aggregate classes, the command and event classes, and a short example of how a controller sends `CancelOrder` and `DepositMoney`. Don't ask questions.
