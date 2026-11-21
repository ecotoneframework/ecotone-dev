---
type: llm
---
PASS if all three statements below are true of the answer; FAIL if any is false. Check each one separately; they may
appear in separate production and test code blocks, with imports or fully qualified names, in any order of attributes
and of named arguments. Equivalent names are fine.

1. The production #[ServiceContext] method returning the coroutine message channel carries an #[Environment]
   attribute that lists the environments running on Swoole or OpenSwoole and leaves out only the test environment,
   for example #[Environment(['prod', 'dev'])] or Laravel's #[Environment(['production', 'local'])].
2. The test uses bootstrapFlowTesting(environment: 'test') and registers
   SimpleMessageChannelBuilder::createQueueChannel('notifications') exactly once.
3. The test calls run('notifications') on the flow test support after sending.

Also FAIL if the answer:
- only says to replace the registration in tests without saying how the production one is excluded;
- registers the coroutine builder and the queue builder together in one bootstrap;
- limits the production registration to #[Environment(['prod'])] alone without saying that every other environment
  running on Swoole or OpenSwoole must be listed too.

Do not fail because the answer also explains production configuration.
