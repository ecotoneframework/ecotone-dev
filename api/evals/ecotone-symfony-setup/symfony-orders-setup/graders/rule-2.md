---
type: llm
---
PASS if `config/packages/ecotone.yaml` only uses keys among `loadSrcNamespaces`, `namespaces`, `enabledModulePackageNames`, `licenceKey` and `test`, and the service name and error channel are set via `ServiceConfiguration::createWithDefaults()->withServiceName(...)->withDefaultErrorChannel('errorChannel')` returned from a `#[ServiceContext]` method. FAIL if keys such as `serviceName` or `defaultErrorChannel` are put into `ecotone.yaml`.
