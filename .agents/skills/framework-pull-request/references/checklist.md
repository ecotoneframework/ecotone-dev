## Before opening a pull request

1. The new or changed test passes: `docker compose exec -T app vendor/bin/phpunit --no-coverage --filter test_name`
2. The changed package's full CI passes: `docker compose exec app bash -lc "cd packages/<PackageName> && composer install && composer tests:ci"`
3. A Core change also runs the packages downstream of it — everything depends on `packages/Ecotone`
4. `php bin/check-licence.php`, `php bin/check-suite-parity.php` and `php bin/check-agent-skills.php` are clean; a changed skill also ran `bin/test-agent-skills`
5. `vendor/bin/php-cs-fixer fix` on the host, with nothing outside your change rewritten
6. `vendor/bin/phpstan` is clean
7. Anything touching event sourcing, the event store, DBAL, transaction wrapping or DDL ran on **MySQL and
   MariaDB** too, not only the PostgreSQL default. They implicitly commit on DDL
   ([rule 16](../../framework-storage-and-transactions/references/rules.md#16-no-table-is-created-on-the-message-path)), so nothing a PostgreSQL or
   SQLite run does will show it, and the omission has cost two whole worktrees weeks apart —
   `implement-mysql-mariadb-green` (merged at `22fe48dbc`) and `implement-dbal-mysql-green` (`57c076084`,
   `9ae4e3fd3`), both chasing the same engine-specific failure
8. The 8.2 floor still holds, if the change could care: `docker compose exec app8_2 ...`
9. `Monorepo/*/Symfony/config/reference.php` needs no check — it is gitignored and untracked
