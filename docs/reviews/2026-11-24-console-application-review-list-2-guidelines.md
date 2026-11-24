# Guidelines review — dgafka/console-run at 4e410db3e (re-check 1, range 33b74cc59..HEAD)

Guidelines re-review: NOT PASSED (GL1, new)

NOT PASSED: GL1. I re-read git diff 33b74cc59..HEAD -- packages: 4e410db3e changes MessagingSystemContainer, SymfonyConsoleApplication, SymfonyConsoleCommand, EcotoneLiteConsoleTest, the new EcotoneLiteConsoleWithoutSymfonyConsoleTest and Symfony EcotoneExtension, and deletes MessagingEntrypointCommand; b575ae089 adds docs/reviews only. (1) All four earlier missing requirements are now covered. (2) The new test breaks one requirement that was covered, GL1; no other status changed.

GL1 — missing (new, was covered) — packages/Ecotone/tests/Lite/EcotoneLiteConsoleWithoutSymfonyConsoleTest.php:9,35 — the test imports and names Ecotone\Messaging\Config\ConfigurationException, a src/ class. Rule 10 allows no class outside Api/ in a test, and ConfigurationException has no Api/ counterpart. Fix: drop the use line and expectException(ConfigurationException::class) and keep expectExceptionMessage(...), which asserts the refusal on its own. The other option is to have the maintainer accept ConfigurationException as an Api type; it is named in 144 existing test files, including SymfonyConnectionWithoutDbalTest which this test copied, so it may deserve a known-deviations row. — skill: framework-testing 10 — deterministic-check candidate (non-Api Ecotone imports in tests, with a baseline for the existing files)

GL51 — covered (was missing) — EcotoneExtension.php:138-146 registers Ecotone\Messaging\Console\SymfonyConsoleCommand for each ConsoleCommandConfiguration (unserialized through a factory Definition), and MessagingEntrypointCommand is deleted with no references left in packages/. One Symfony Console adapter remains. The Laravel artisan adapter is pre-existing and untouched.

GL15 — covered (was missing) — MessagingSystemContainer.php:89-93 and SymfonyConsoleApplication.php:29 use named arguments, as do SymfonyConsoleCommand.php:32-38 addOption/addArgument (receivers are Symfony's Command, which no application implements). The only remaining positional call has 2 arguments.

GL23 — covered (was missing) — EcotoneLiteConsoleWithoutSymfonyConsoleTest::test_the_console_is_refused_when_symfony_console_is_not_installed hides Symfony\Component\Console from the autoloader in a separate process and asserts the full message.

GL4 — covered (was missing) — the fixture docblock is gone. The new tests test_running_a_console_command_from_the_script_arguments and test_the_console_is_refused_when_symfony_console_is_not_installed are snake_case with inline anonymous fixtures, no comments, and only the class licence docblock.

Unchanged, re-checked against the new commits:

GL2 — covered — the new argv test adds the no-input path, console() over $_SERVER['argv'].

GL3 — covered — #[RunTestsInSeparateProcesses] only isolates the autoloader change, as in the Symfony/Laravel/Tempest *WithoutDbalTest tests. No message is driven across processes, and the argv test restores the global in finally.

GL5, GL7, GL8, GL9, GL11, GL13, GL16, GL17, GL19, GL20, GL21, GL24, GL35, GL37, GL49 — covered — same evidence as the first review. The Symfony bundle now names the internal SymfonyConsoleCommand, which is framework code using framework code, and the constructor dependencies are still all required.

GL14 — covered — the new test file declares strict_types=1 and the class is final.

GL48 — covered — no .neon file in the range.

GL50 — covered — "refactor(console): ..." and "docs(reviews): ..." are conventional and scoped, and the refactor body explains each change.

GL6, GL10, GL12, GL18, GL22, GL25 to GL34, GL36, GL38 to GL42, GL44 to GL47 — not-apply — same reasons as the first review; the range adds no storage, header, interface, module, design, skill or eval case.

GL43 — not-apply — MessagingEntrypointCommand was an internal class of the bundle's DependencyInjection namespace, and the registered Symfony commands keep the same name, description, arguments, options, table output and exit code. Removing the class is the breaking-changes review's to judge.

What is left: GL1 is a one-line fix in the new test (or a maintainer decision to accept ConfigurationException), then a re-check.
