---
type: llm
---
PASS if the image pipeline's entry point is a `#[CommandHandler(outputChannelName: '<resize channel>')]` for `ProcessImage`, and resize and upload are `#[InternalHandler(inputChannelName: ...)]` methods whose input channels match the previous step's `outputChannelName` (resize forwarding to upload). FAIL if the steps are all `#[CommandHandler]`s, are chained by calling each other directly or via the command bus, use `#[ServiceActivator]`, or the channel names do not line up.
