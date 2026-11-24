---
type: llm
---
PASS if the answer splits the import with `#[Splitter]` (with `gatherChannelName`) stacked on the command handler, puts the row handler and the `#[Gathered]` summary handler on two different `CoroutineMessageChannelBuilder` channels, registers the summary handler's channel without `withCoroutines()` or with `withCoroutines(1)`, and says that channel handles one message at a time while the rows still run at once on the other channel. FAIL if the row handler and the summary handler share one coroutine message channel, if the summary handler's channel is given more than one coroutine, or if the answer consumes either channel with `ecotone:run`.
