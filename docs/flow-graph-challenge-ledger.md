# Message flow graph challenge ledger

Base: `ff2adf7b2`; baseline: 18 tests, 85 assertions.

Scope decisions: production and tests stay in `packages/Ecotone`; this required ledger is the explicit exception. The supplied brief remains untouched. The host has no PHP executable (host fixer exits 127), so style uses the brief's container command with explicit flow paths; no unrestricted fixer run is used. Tests exercise `bootstrapFlowTesting()->getMessageFlowGraph()` or the console. Source inference is conservative, does not execute application code, and keeps inferred edges marked. Named fixtures are used only where PHP requires names (message types, interfaces, traits, inheritance, attribute references). Each row's commit contains its tests and decision; hashes are filled by the following increment to avoid self-referential commit hashes.

| Case | Outcome | Tests | Decision / evidence | Commit |
| --- | --- | --- | --- | --- |
| E1 static factories and fresh aggregate instances | fixed | event_static_factory_is_shown_as_published; aggregate_static_helper_event_is_shown_as_recorded_on_the_fresh_instance | External event factories were seen; local self:: helper lost its event (0 edges). Follow local calls with a recursion guard and retain recordThat attribution. new static creates the aggregate without a spurious message. | `fix(messaging): infer events created by aggregate helpers` |
