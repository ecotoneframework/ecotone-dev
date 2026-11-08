<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Tests\Support\OpenedConnections;
use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\RespondingWhileWaitingTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class FreshDatabaseTransportTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;
    use RespondingWhileWaitingTrait;

    private string $freshDatabase = '';

    public function test_a_brand_new_database_transport_is_set_up_by_the_entrypoint_steps_and_processes_a_reply(): void
    {
        $this->configurePulseWithServices('orders');
        $this->useBrandNewTransportDatabase();
        $this->awaitRepliesFor(30);
        $client = static::createClient();
        $client->disableReboot();
        $application = new Application($client->getKernel());

        self::assertSame([['deduplication', 'pulse_transport', 'Yes', 'No'], ['message_queue', 'pulse_transport', 'Yes', 'No']], $this->missingOnTransport($application));
        (new CommandTester($application->find('pulse:setup')))->execute([]);
        (new CommandTester($application->find('ecotone:migration:database:setup')))->execute(['--initialize' => true]);
        self::assertSame([['deduplication', 'pulse_transport', 'Yes', 'Yes'], ['message_queue', 'pulse_transport', 'Yes', 'Yes']], $this->missingOnTransport($application));

        $this->respondWhileWaiting($client->getKernel(), ['orders' => $this->realService('orders')]);
        $client->request('POST', '/refresh');
        $crawler = $client->followRedirect();

        self::assertSame('connected', $crawler->filter('[data-service="orders"] [data-status]')->attr('data-status'));
    }

    protected function tearDown(): void
    {
        if ($this->freshDatabase !== '') {
            $this->administration()->executeStatement(sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $this->freshDatabase));
        }
        parent::tearDown();
    }

    /**
     * @return list<array{string, string, string, string}>
     */
    private function missingOnTransport(Application $application): array
    {
        $command = new CommandTester($application->find('ecotone:migration:database:setup'));
        $command->execute(['--connection' => 'pulse_transport']);
        $rows = [];
        foreach (explode("\n", $command->getDisplay()) as $line) {
            $cells = array_map('trim', explode('|', trim($line, " |\n")));
            if (count($cells) === 4 && preg_match('/^[a-z_]+$/', $cells[0]) === 1 && in_array($cells[2], ['Yes', 'No'], true)) {
                $rows[] = [$cells[0], $cells[1], $cells[2], $cells[3]];
            }
        }
        sort($rows);

        return $rows;
    }

    private function useBrandNewTransportDatabase(): void
    {
        $this->freshDatabase = 'pulse_fresh_' . bin2hex(random_bytes(4));
        $this->administration()->executeStatement('CREATE DATABASE ' . $this->freshDatabase);
        $dsn = (string) preg_replace('#/[^/?]+\?#', '/' . $this->freshDatabase . '?', $_SERVER['PULSE_TRANSPORT_DSN']);
        $_SERVER['PULSE_TRANSPORT_DSN'] = $_ENV['PULSE_TRANSPORT_DSN'] = $dsn;
        putenv('PULSE_TRANSPORT_DSN=' . $dsn);
    }

    private function administration(): \Doctrine\DBAL\Connection
    {
        return OpenedConnections::factory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16')->createContext()->getDbalConnection();
    }
}
