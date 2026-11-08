---
type: llm
---
PASS if the error channel name used in `ErrorHandlerConfiguration` (e.g. `'errorChannel'`) is also wired so consumers send failures there: via `ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('<same name>')` or a `PollingMetadata::create('<endpointId>')->withErrorChannelName('<same name>')`. FAIL if the default error channel is set as a `defaultErrorChannel` key in Symfony YAML instead (refused in 2.0), if `ErrorHandlerConfiguration` is defined but nothing routes consumer failures to its error channel, or the two names differ.
