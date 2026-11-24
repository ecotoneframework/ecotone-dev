# Behaviour review — dgafka/console-run at 33b74cc59 (range main...HEAD)

Behaviour review 33b74cc59: FAIL (BH8)

I reviewed the behaviour of 33b74cc59 (git diff main...HEAD) and decided every BH requirement. I ran EcotoneLiteConsoleTest in the container (7/7 green, 22 assertions) and ran console() read-only inline with the default argv/stdout input. Result: FAIL, NOT PASSED: BH8, and BH8 is the only gap.

Problem: an Ecotone Lite app needs a Symfony-style console. It must list the registered commands with descriptions and run e.g. 'php ecotone.php ecotone:run async' with arguments and options, through exit(EcotoneLite::bootstrap(...)->console()). Trace: ConfiguredMessagingSystem::console() / FlowTestSupport::console() -> MessagingSystemContainer::console() (it now gets the ConsoleCommandConfiguration list instead of only the names) -> SymfonyConsoleApplication::create (autoExit off) -> one SymfonyConsoleCommand per configuration (name, description, args and options mapped as in Symfony's MessagingEntrypointCommand) -> the ConsoleCommandRunner gateway inside DelegatingConsoleWriter::executeWith -> the result set is printed as a table, and the exit code is returned.

BH8 missing: packages/Ecotone/tests/Lite/EcotoneLiteConsoleTest.php:192. Every test passes an ArrayInput. Nothing calls console() with no arguments, which is the exact form the reporter's script uses (input read from $_SERVER['argv'], output to ConsoleOutput). A regression in the null-input default (e.g. the two arguments no longer passed to run() as null) would stay green. My manual argv run works: ecotone:list exit 0, an unknown endpoint exit 102, a missing argument exit 1. Test to add: test_running_a_console_command_from_the_script_arguments. It sets $_SERVER['argv'] = ['ecotone.php','app:orders:ship','123'], calls $ecotone->console() with no arguments, then asserts exit 0 and shipped === ['123'], and restores argv. Skill: framework-testing.

BH1 covered: the trace above reaches the outcome. Listing with descriptions: test_listing_console_commands_with_their_descriptions and test_every_listed_console_command_has_a_description. Running with arguments and options: test_running_a_console_command_with_arguments_and_options. ecotone:run async: test_running_an_asynchronous_consumer_from_the_console. Exit code: test_a_failing_console_command_ends_with_a_failure_exit_code. 'Add descriptions if missing' needed no code change, because every framework command already has one. The json-list test checks this for core only. By reading, the Dbal, EventSourcing, Gathering, DeadLetter and Flow commands also pass descriptions.

BH2 covered: per the brief, all 7 tests were red on the base with 'Call to undefined method ...console()'. The test and the change are in the same commit, so the handed-over red run is the proof. Every test needs console(), so none passes either way.

BH3 covered: the only entry point is the console command. The user commands, ecotone:run and ecotone:list, and the flow-testing bootstrap are each tested. Bus, gateway and backfill cannot reach console().

BH4 not-apply: no storage and no engine.

BH5 covered: test_a_failing_console_command_ends_with_a_failure_exit_code (non-zero exit, message printed).

BH6 not-apply: no message is redelivered.

BH7 not-apply: a new Application is built per call and no shared state is written.

BH9 not-apply: nothing in console() can be configured wrongly. The guard for a missing symfony/console is correct by reading (class_exists runs before any Symfony class loads). It cannot be tested in a tree where symfony/console is a require-dev dependency.

BH10 covered: synchronous commands in the argument and output tests, asynchronous consumption in test_running_an_asynchronous_consumer_from_the_console.

BH11 not-apply: nothing depends on time.

BH12 covered: the tests use only the Api classes (EcotoneLite, ConfiguredMessagingSystem, FlowTestSupport, attributes, ConsoleWriter, ConsoleCommandResultSet) and Symfony's public ArrayInput/BufferedOutput. There is no reflection and no SQL.

BH13 not-apply: no skill or eval files changed.

BH14 covered: asynchronous channels and consumers (the ecotone:run test), ConsoleWriter output and the result-set table (test_console_command_output_is_printed_to_the_console), and flow testing (test_running_a_console_command_of_a_flow_tested_application). Interceptors and transactions around console commands use the same ConsoleCommandRunner gateway path as the Symfony bundle. Aggregates, sagas, event sourcing, distribution and Enterprise are not related.

BH15 not-apply: no new service state. It reuses DelegatingConsoleWriter::executeWith exactly as the Symfony bundle does, around a single CLI command.

BH16 not-apply: no resource is acquired.

BH17 not-apply: no read-wait-write.

BH18 not-apply: no new I/O client.

BH19 not-apply: no new endpoint or adapter, since ecotone:run reuses the existing consumers.

BH20 not-apply: nothing is copied to a child execution.

BH21 not-apply: no coroutine test was added.

BH22 not-apply: no message flow or flow feature changes. Commands go through the existing console-command gateway and handlers.

Left: add the BH8 test, then re-review BH8.
