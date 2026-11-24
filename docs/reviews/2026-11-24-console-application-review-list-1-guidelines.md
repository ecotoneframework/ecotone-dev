# Guidelines review — dgafka/console-run at 33b74cc59 (range main...HEAD)

Guidelines review: NOT PASSED (GL4, GL15, GL23, GL51)

NOT PASSED: GL4, GL15, GL23, GL51. I read all 9 changed files of main...HEAD (commit 33b74cc59, which adds ConfiguredMessagingSystem::console() and FlowTestSupport::console(), new src/Messaging/Console/SymfonyConsoleApplication.php and SymfonyConsoleCommand.php, MessagingSystemContainer now holding ConsoleCommandConfiguration[], composer suggest text, and tests/Lite/EcotoneLiteConsoleTest.php) and decided every Guidelines requirement against the cited rules; four requirements are missing, listed first.

GL51 — missing — packages/Ecotone/src/Messaging/Console/SymfonyConsoleCommand.php:26-60 — configure() and execute() repeat packages/Symfony/DependencyInjection/MessagingEntrypointCommand.php:40-79 line for line (parameters mapped to InputOption/InputArgument, DelegatingConsoleWriter::executeWith, ResultSet rendered as a table). That is a second Symfony adapter for something the framework already does, which the rule forbids even without an if. Fix: keep one adapter. Either make the Symfony bundle register SymfonyConsoleCommand (MessagingEntrypointCommand becomes a thin subclass or is removed), or have the new class reuse the existing mapping. — skill: framework-service-design 22

GL15 — missing — MessagingSystemContainer.php:89-93 SymfonyConsoleApplication::create(...) and SymfonyConsoleApplication.php:~30 new SymfonyConsoleCommand(...) each pass three positional arguments to Ecotone-only final classes. Fix: use named arguments (consoleCommands:, consoleCommandRunner:, consoleWriter:). Optional: SymfonyConsoleCommand.php:32-40 addOption/addArgument (5 and 4 args) can be named too. — skill: framework-service-design 18 — deterministic-check candidate (a phpstan rule counting positional args >=3)

GL23 — missing — SymfonyConsoleApplication.php:23 — the "symfony/console is not installed" ConfigurationException names the problem, the reason and both ways out, but no test asserts its text. Fix: add a #[RunTestsInSeparateProcesses] test that hides symfony/console from the autoloader, as packages/Symfony/tests/phpunit/SymfonyConnectionWithoutDbalTest.php does for Dbal, and assert the message. — skill: framework-error-handling 1, framework-testing 10

GL4 — missing — tests/Lite/EcotoneLiteConsoleTest.php:75-77 — a `/** @param string[] $tags */` docblock on a fixture method. Only the class licence marker is allowed, and the console parameter's array-ness comes from the native `array` type (ConsoleCommandModule:130-134). Fix: delete the docblock. — skill: framework-testing 11 — deterministic-check candidate (docblock in tests other than the class marker)

GL1 — covered — EcotoneLiteConsoleTest uses only Ecotone\Api types, inline fixtures and Symfony ArrayInput/BufferedOutput (the types console() takes). No src class, SQL or reflection. Six tests use EcotoneLite::bootstrap() because bootstrap()->console() is the entry point under test, and test_running_a_console_command_of_a_flow_tested_application covers bootstrapFlowTesting.

GL2 — covered — both entry points have tests: ConfiguredMessagingSystem::console() (tests 1-6) and FlowTestSupport::console() (test 7).

GL3 — covered — test_running_an_asynchronous_consumer_from_the_console consumes in-process through the ecotone:run command under test with --finishWhenNoMessages on an in-memory queue. No fork, process, socket or sleep loop.

GL5 — covered — same test: sendWithRouting to an #[Asynchronous('async')] handler, then the channel is run (via ecotone:run async); no direct channel send or receive.

GL7 — covered — SymfonyConsoleCommand::__construct and MessagingSystemContainer::__construct take only required parameters. The ?InputInterface/?OutputInterface defaults on console() are call arguments, not service dependencies.

GL8 — covered — SymfonyConsoleApplication is a static factory with no state. SymfonyConsoleCommand is built per console() call and is not a container service. MessagingSystemContainer::$consoleCommands is written only in the constructor and in the pre-existing replaceWith().

GL9 — covered — console() runs the application and returns the exit code, named for the action. create() is a factory that changes nothing.

GL11 — covered — MessagingSystemConfiguration.php:1241 passes array_values($this->consoleCommands) into the Definition. ConsoleCommandConfiguration and ConsoleCommandParameter are DefinedObject.

GL13 — covered — MessagingSystemContainer::console() is a single step (create the application, run it). No SQL.

GL14 — covered — both new class files declare strict_types=1 and are final.

GL16 — covered — the only new docblocks in src are generics (@param ConsoleCommandConfiguration[]) and licence markers.

GL17 — covered — one word, console: console(), SymfonyConsoleApplication/SymfonyConsoleCommand, $consoleCommands, and the composer suggest text; no synonym.

GL19 — covered — the API an application calls is console() on Api/Messaging/ConfiguredMessagingSystem.php:44 and Api/Lite/Test/FlowTestSupport.php:533. The adapters are internal in src/.

GL20 — covered — the new public signatures use only Symfony Console types and int. No plumbing was added under Api/.

GL21 — covered — SymfonyConsoleCommand.php:33 names each option $parameter->getName() verbatim. The tests use --carrier, --express, --tags and --finishWhenNoMessages.

GL24 — covered — the missing-symfony/console refusal is a ConfigurationException thrown on the console() call itself, the first point where the need is known (an application may never call it), and it never yields an empty result.

GL35 — covered — SymfonyConsoleCommand::execute only calls the ConsoleCommandRunner gateway. It opens no transaction.

GL37 — covered — both new src files carry licence Apache-2.0 and have no licence branch.

GL48 — covered — phpstan-baseline.neon is not in the diff.

GL49 — covered — every name used resolves in the tree: ConfiguredMessagingSystem::console(), runConsoleCommand(), SymfonyConsoleWriter, ecotone:run, ecotone:list and --finishWhenNoMessages.

GL50 — covered — "feat: run the registered console commands as a console application with console()" matches P1's regex (scope optional, like the recent main subjects), and the body explains the change.

GL6 — not-apply — no test depends on time.

GL10 — not-apply — no interface was added; console() is added to the existing ConfiguredMessagingSystem.

GL12 — not-apply — no class metadata is read.

GL18 — not-apply — no Api/ value class was added or changed; only methods on an interface and on FlowTestSupport.

GL22 — not-apply — no message header was added.

GL25, GL26 — not-apply — the one new exception has static text and checks an installed package, not handler signatures or attribute placement.

GL27 — not-apply — no attribute, configuration or gateway was added; console() wraps the already-registered #[ConsoleCommand]s.

GL28 to GL34, GL36 — not-apply — the change reads and writes no storage and uses no connection.

GL38 to GL40 — not-apply — no module, package or module test was added.

GL41, GL42 — not-apply — no design or spec was changed.

GL43 — not-apply — new opt-in API; nothing a 1.x application relies on behaves differently, and no spec names the change. The new interface method is the breaking-changes review's to judge.

GL44 to GL47 — not-apply — no rule, skill or eval case was changed.

What is left: the implementing session covers GL51, GL15, GL23 and GL4, then this review runs again.
