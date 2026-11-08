---
type: llm
---
PASS if both applications set a distinct service name with `ServiceConfiguration::createWithDefaults()->withServiceName('...')` returned from a `#[ServiceContext]` method, and the `targetServiceName` used when sending `ReserveStock` equals the inventory service's name exactly. FAIL if either application leaves the default service name, sets it only as a `serviceName` key in Symfony YAML, or the target name does not match.
