---
type: llm
---
PASS if the answer puts an #[Environment] attribute on the production #[ServiceContext] method that lists the environments running on Swoole or OpenSwoole (for example #[Environment(['prod', 'dev'])], or Laravel's #[Environment(['production', 'local'])]) and leaves out only the test environment, uses bootstrapFlowTesting(environment: 'test') with one SimpleMessageChannelBuilder::createQueueChannel('notifications') registration, and calls run('notifications') on the flow test support after sending. Equivalent names are fine. FAIL if it just says replace the registration without saying how to exclude it, registers both builders together, or limits the production registration to #[Environment(['prod'])] alone without saying that every other environment running on Swoole or OpenSwoole must be listed too.

These three conditions may appear in separate production and test code blocks, with imports or fully qualified names and either order of bootstrap named arguments. Either attribute order is valid. Grade the exclusion, single queue registration and flow run; do not fail because the explanation also includes production configuration.
