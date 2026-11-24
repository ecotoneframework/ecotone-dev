# Breaking changes review — dgafka/console-run at 33b74cc59 (range main...HEAD)

Breaking-changes review of 33b74cc59: PASSED

I reviewed git diff main...HEAD (33b74cc59, 9 files) against the Breaking changes section of review-list.md. PASSED, no requirement missing. Release: latest tag 2.0.0-beta.10, dev-main alias 2.0.x-dev, so this ships in a 2.0 pre-release, which is a major (B1).

BC1 not-apply: nothing written to a table, queue, outbox, dead letter or event store changes. The only constructor change is to the internal MessagingSystemContainer, which now takes ConsoleCommandConfiguration[] instead of string[] (MessagingSystemConfiguration.php:1241). Those objects implement DefinedObject, and that is compiled container cache, not deployment storage.

BC2 not-apply: no stored form changes.

BC3 covered: (a) Api/Messaging/ConfiguredMessagingSystem.php:44 adds console(?InputInterface = null, ?OutputInterface = null): int to an interface. That breaks only a userland implementer, the release is a major, and the interface's move into Api is already a 2.0 break (upgrade-2.0.md section 13e). Both in-repo implementers are updated: MessagingSystemContainer and ConfiguredMessagingSystemWithTestSupport. (b) Api/Lite/Test/FlowTestSupport.php:533 adds a method to a final class, which is safe. (c) SymfonyConsoleApplication and SymfonyConsoleCommand are new internal classes. Nothing is removed or renamed, no type is narrowed and no required parameter is added. symfony/console stays a suggest dependency, and console() refuses with a ConfigurationException naming composer require symfony/console when it is missing.

BC4 not-apply: no default changes. runConsoleCommand and its not-registered message read the same.

BC5 not-apply: no console command name or option changes. The registered commands are only exposed through a new symfony Application.

BC6 not-apply: purely additive, so existing code observes nothing new. No upgrade entry is needed because upgrade-2.0.md is 1.x to 2.0 only and console() is new API.

BC7 not-apply: no behaviour of existing calls changes, and console() is opt-in by calling it.

BC8 not-apply: nothing written to a shared queue or table.

Out of my section, for the behaviour and DX reviews: the 'add description if missing' part of the request is not touched by this diff.
