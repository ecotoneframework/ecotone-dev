---
type: llm
---
PASS if the `#[ServiceContext]` configuration class has no constructor parameters (it does not inject `ParameterBagInterface` or any other service) and is not wired in `services.yaml`; reading the parameter, if shown, happens through a `#[ServiceContext]` method parameter (e.g. `#[ConfigurationVariable('...')]`) or the user is told the constructor injection will not work. FAIL if the configuration class takes `ParameterBagInterface` or another service in its constructor.
