# Breaking changes review — dgafka/console-run at 4e410db3e (re-check 1, range 33b74cc59..HEAD)

Breaking-changes re-review of 4e410db3e: PASSED

I re-read git diff 33b74cc59..HEAD -- packages (4e410db3e) against the Breaking changes section. PASSED, no requirement missing. (1) None was missing at 33b74cc59, so there was nothing to re-cover. (2) The new commits change no status. Release is still 2.0.0-beta.10 / 2.0.x-dev, a major.

BC1 not-apply: still no table, queue, outbox, dead letter or event store change. The Symfony bundle's serialize/unserialize of ConsoleCommandConfiguration (EcotoneExtension.php:139-143) lives in the compiled Symfony container, which is cache, not stored data.

BC2 not-apply: no stored form changes.

BC3 covered: the new commits only add named arguments to internal calls (MessagingSystemContainer.php:90-92, SymfonyConsoleApplication.php:29, SymfonyConsoleCommand.php:33-38). They also delete Ecotone\SymfonyBundle\DependencyInjection\MessagingEntrypointCommand. That class sits outside Api, and upgrade-2.0.md:1866 declares classes outside Api @internal, so the deletion is not a public-surface break. The release is a major in any case, and the class has no other references in packages or quickstart-examples. Api/ is untouched since 33b74cc59.

BC4 not-apply: no default changes. The Symfony option and argument defaults map the same as in MessagingEntrypointCommand.

BC5 not-apply, still: every Symfony console command keeps its name, description, arguments, options, value modes and defaults. They are mapped line for line as before, keep the same container id (the channel name) and the same console.command tag with the command name. Exit code 0 equals self::SUCCESS.

BC6 not-apply: the only observable difference in Symfony is that a table is printed only when the result is a ConsoleCommandResultSet. The old code did if ($result) and then read ->columnHeaders, so a truthy non-result-set return crashed with an error before and is now ignored. That turns a crash into no output, not a change existing working code relies on, so it needs no upgrade entry.

BC7 not-apply: there is no new default, and console() stays opt-in.

BC8 not-apply: nothing written to a shared queue or table.

Nothing is left for my section.
