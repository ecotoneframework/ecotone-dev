<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Storage\InternalDatabase;
use App\Storage\InternalTables;
use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class SnapshotStore
{
    public function __construct(private InternalDatabase $database)
    {
    }

    public function read(ServiceScope $scope): ServiceRecord
    {
        return $this->readFrom($this->database->connection(), $scope);
    }

    /**
     * @param Closure(ServiceRecord): ServiceRecord $change
     */
    public function update(ServiceScope $scope, Closure $change): ServiceRecord
    {
        return $this->database->inTransaction(function (Connection $connection) use ($scope, $change): ServiceRecord {
            $record = $change($this->readFrom($connection, $scope));
            $this->write($connection, $scope, $record);

            return $record;
        });
    }

    public function sections(ServiceScope $scope): SectionedSnapshot
    {
        $sections = [];
        foreach ($this->database->connection()->fetchAllKeyValue('SELECT section, content FROM ' . InternalTables::SERVICE_SECTION . ' WHERE service = ? AND tenant = ?', [$scope->service, $scope->tenantColumn()]) as $section => $content) {
            $sections[$section] = static fn (): mixed => json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        }

        return new SectionedSnapshot($sections);
    }

    /**
     * @return array<string, string>
     */
    public function digests(ServiceScope $scope): array
    {
        return $this->database->connection()->fetchAllKeyValue('SELECT section, digest FROM ' . InternalTables::SERVICE_SECTION . ' WHERE service = ? AND tenant = ? AND digest IS NOT NULL', [$scope->service, $scope->tenantColumn()]);
    }

    /**
     * @param array<string, mixed> $reply
     */
    public function receive(ServiceScope $scope, array $reply, DateTimeImmutable $at): void
    {
        $this->database->inTransaction(function (Connection $connection) use ($scope, $reply, $at): void {
            $deadLetters = array_key_exists('deadLetters', $reply) ? $this->contentOf($connection, $scope, 'deadLetters') : null;
            $record = $this->readFrom($connection, $scope)->received(reply: $reply, deadLetterTotalBefore: is_array($deadLetters) && is_int($deadLetters['total'] ?? null) ? $deadLetters['total'] : null, at: $at);
            $this->storeSections($connection, $scope, $reply);
            $this->write($connection, $scope, $record);
        });
    }

    /**
     * @param array<string, mixed> $reply
     */
    private function storeSections(Connection $connection, ServiceScope $scope, array $reply): void
    {
        $digests = is_array($reply['digests'] ?? null) ? array_filter($reply['digests'], is_string(...)) : [];
        $partial = ($reply['partial'] ?? false) === true;
        $failed = is_array($reply['sectionErrors'] ?? null) ? $reply['sectionErrors'] : [];
        if ($partial) {
            $earlier = $this->contentOf($connection, $scope, 'sectionErrors');
            $failed = [...array_diff_key(is_array($earlier) ? $earlier : [], $digests), ...$failed];
        }
        $sections = array_diff_key($reply, ['digests' => true, 'partial' => true, 'sectionErrors' => true]) + ($failed === [] ? [] : ['sectionErrors' => $failed]);

        $where = ' WHERE service = ? AND tenant = ?';
        $of = [$scope->service, $scope->tenantColumn()];
        foreach ($connection->fetchAllKeyValue('SELECT section, digest FROM ' . InternalTables::SERVICE_SECTION . $where, $of) as $stored => $digest) {
            $carriedInTheReply = array_key_exists($stored, $sections);
            $reportedUnchanged = array_key_exists($stored, $digests) && ! array_key_exists($stored, $failed);
            $leftAloneByAPartialReply = $partial && $digest !== null && ! array_key_exists($stored, $failed);
            if (! $carriedInTheReply && ! $reportedUnchanged && ! $leftAloneByAPartialReply) {
                $connection->executeStatement('DELETE FROM ' . InternalTables::SERVICE_SECTION . $where . ' AND section = ?', [...$of, $stored]);
            }
        }
        foreach ($sections as $section => $content) {
            $connection->executeStatement(
                'INSERT INTO ' . InternalTables::SERVICE_SECTION . ' (service, tenant, section, digest, content) VALUES (?, ?, ?, ?, ?) ON CONFLICT(service, tenant, section) DO UPDATE SET digest = excluded.digest, content = excluded.content',
                [...$of, (string) $section, $digests[$section] ?? null, json_encode($content, JSON_THROW_ON_ERROR)]
            );
        }
    }

    private function contentOf(Connection $connection, ServiceScope $scope, string $section): mixed
    {
        $content = $connection->fetchOne('SELECT content FROM ' . InternalTables::SERVICE_SECTION . ' WHERE service = ? AND tenant = ? AND section = ?', [$scope->service, $scope->tenantColumn(), $section]);

        return is_string($content) ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private function write(Connection $connection, ServiceScope $scope, ServiceRecord $record): void
    {
        $connection->executeStatement(
            'INSERT INTO ' . InternalTables::SERVICE_RECORD . ' (service, tenant, record) VALUES (?, ?, ?) ON CONFLICT(service, tenant) DO UPDATE SET record = excluded.record',
            [$scope->service, $scope->tenantColumn(), json_encode($record->toArray(), JSON_THROW_ON_ERROR)]
        );
    }

    private function readFrom(Connection $connection, ServiceScope $scope): ServiceRecord
    {
        $stored = $connection->fetchOne('SELECT record FROM ' . InternalTables::SERVICE_RECORD . ' WHERE service = ? AND tenant = ?', [$scope->service, $scope->tenantColumn()]);

        return is_string($stored) ? ServiceRecord::fromArray(json_decode($stored, true, 512, JSON_THROW_ON_ERROR)) : ServiceRecord::empty();
    }
}
