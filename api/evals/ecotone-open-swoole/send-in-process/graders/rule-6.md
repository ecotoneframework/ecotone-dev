---
type: llm
---
PASS if the answer registers CoroutineMessageChannelBuilder::create('notifications')->withCoroutines(4) through #[ServiceContext], marks the target handler #[Asynchronous] with that same channel, and shows a sender using Ecotone\Api\Gateway\CommandBus. A different consistent channel name is fine. FAIL if it starts ecotone:run for a channel registered with CoroutineMessageChannelBuilder, configures its concurrency on PollingMetadata, or writes its own coroutine dispatcher.
