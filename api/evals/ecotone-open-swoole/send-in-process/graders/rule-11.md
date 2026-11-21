---
type: llm
---
PASS if the answer puts #[Environment(['prod'])] on the production #[ServiceContext] method, uses bootstrapFlowTesting(environment: 'test') with one SimpleMessageChannelBuilder::createQueueChannel('notifications') registration, and calls run('notifications') on the flow test support after sending. Equivalent names are fine. FAIL if it just says replace the registration without saying how to exclude it, or registers both builders together.

These three conditions may appear in separate production and test code blocks, with imports or fully qualified names and either order of bootstrap named arguments. Either attribute order is valid. Grade the exclusion, single queue registration and flow run; do not fail because the explanation also includes production configuration.
