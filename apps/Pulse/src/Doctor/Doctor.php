<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Settings\Connection;
use App\Settings\PulseSettings;
use App\Settings\ServiceRoute;
use App\Settings\Transport;
use App\Snapshot\Snapshots;
use App\Storage\InternalDatabase;
use App\Storage\InternalTables;
use Throwable;

final readonly class Doctor
{
    public function __construct(
        private PulseSettings $settings,
        private BrokerProbe $probe,
        private InternalDatabase $internalDatabase,
        private Snapshots $snapshots,
    ) {
    }

    /**
     * @return list<DoctorCheck>
     */
    public function diagnose(): array
    {
        $checks = [DoctorCheck::ok('settings', sprintf(
            "Pulse is '%s', %d service(s): %s",
            $this->settings->serviceName,
            count($this->settings->routes),
            implode(', ', array_map(static fn (ServiceRoute $route) => $route->service . ($route->connection === Connection::DEFAULT ? '' : '@' . $route->connection), $this->settings->routes))
        ))];

        foreach ($this->settings->connections as $connection) {
            array_push($checks, ...$this->connectionChecks($connection));
        }

        $checks[] = $this->internalDatabase();
        $checks[] = DoctorCheck::ok('licence', 'accepted, Pulse started with an Ecotone Enterprise licence');
        $checks[] = DoctorCheck::info('reply channels', $this->replyChannels());
        $checks[] = $this->tenants();

        return $checks;
    }

    /**
     * @return array{checks: list<DoctorCheck>, skipped: bool}
     */
    public function diagnoseService(string $service): array
    {
        $route = $this->settings->routes[$service];
        $connection = $this->settings->connections[$route->connection];
        $deadline = microtime(true) + $this->settings->doctorBudgetSeconds;
        if ($this->settings->doctorBudgetSeconds === 0) {
            return ['checks' => [], 'skipped' => true];
        }

        $checks = $this->connectionChecks($connection, $service, $deadline);
        $skipped = microtime(true) > $deadline;

        return ['checks' => [...$checks, DoctorCheck::ok('licence', 'accepted, Pulse started with an Ecotone Enterprise licence')], 'skipped' => $skipped];
    }

    /**
     * @return list<DoctorCheck>
     */
    private function connectionChecks(Connection $connection, ?string $onlyService = null, ?float $deadline = null): array
    {
        $variable = $connection->environmentVariable;
        $checks = [];

        $endpoints = $this->probe->endpoints($connection);
        foreach ($endpoints as [$host, $port]) {
            $timestamp = microtime(true);
            $socket = @fsockopen($host, $port, $errorNumber, $error, 3);
            $milliseconds = (int) round((microtime(true) - $timestamp) * 1000);
            if ($socket === false) {
                return [DoctorCheck::fail('transport ' . $connection->name, sprintf('cannot connect to %s:%d (%s)', $host, $port, $error), sprintf('Check the host and port in %s, and that the broker is on the same network as this container.', $variable))];
            }
            fclose($socket);
            $checks[] = DoctorCheck::ok('transport ' . $connection->name, sprintf('reachable at %s:%d in %d ms', $host, $port, $milliseconds));
        }

        if ($deadline !== null && microtime(true) > $deadline) {
            return $checks;
        }

        $problem = $this->probe->loginProblem($connection);
        if ($problem !== null) {
            $checks[] = DoctorCheck::fail('login ' . $connection->name, $problem, $connection->transport === Transport::Dbal ? sprintf('Check the user, password and database name in %s.', $variable) : sprintf('Check the user and password in %s.', $variable));

            return $checks;
        }
        $checks[] = DoctorCheck::ok('login ' . $connection->name, 'accepted');

        foreach ($this->settings->routes as $route) {
            if ($deadline !== null && microtime(true) > $deadline) {
                break;
            }
            if ($route->connection === $connection->name && ($onlyService === null || $route->service === $onlyService)) {
                $checks[] = $this->serviceCheck($connection, $route);
            }
        }

        return $checks;
    }

    private function serviceCheck(Connection $connection, ServiceRoute $route): DoctorCheck
    {
        $name = 'service ' . $route->service;
        $kind = $connection->transport->destinationKind();

        $exists = $this->probe->destinationExists($connection, $route);
        if ($exists === null) {
            return DoctorCheck::info($name, sprintf("%s '%s' cannot be checked on a %s transport, it is created by the first message", $kind, $route->destination, $connection->transport === Transport::Dbal ? 'database' : 'Redis'));
        }
        if ($exists) {
            return DoctorCheck::ok($name, sprintf("%s '%s' exists", $kind, $route->destination));
        }

        $override = $connection->transport->hasDestinationOfItsOwn()
            ? sprintf('when its %s is named differently, set it in PULSE_SERVICES as %s:channel#%s_name.', $kind, $route->service, $kind)
            : sprintf('the channel is the %s, so name it in PULSE_SERVICES as %s:%s_name.', $kind, $route->service, $kind);

        return DoctorCheck::fail(
            $name,
            sprintf("%s '%s' does not exist on connection %s", $kind, $route->destination, $connection->name),
            sprintf("The service has not started on this broker yet, or it consumes another %s. Check that it registers %s::create('%s') and runs ecotone:run for it; %s", $kind, $connection->transport->channelBuilder(), $route->channel, $override)
        );
    }

    private function internalDatabase(): DoctorCheck
    {
        try {
            $this->internalDatabase->connection()->fetchOne('SELECT COUNT(*) FROM ' . InternalTables::HEARTBEAT);
            $this->internalDatabase->inTransaction(static fn (): null => null);
        } catch (Throwable $exception) {
            return DoctorCheck::fail('internal database', $exception->getMessage(), sprintf('Run "bin/console pulse:setup", and make %s writable by the user Pulse runs as.', dirname($this->settings->internalDatabasePath)));
        }

        return DoctorCheck::ok('internal database', 'writable at ' . $this->settings->internalDatabasePath);
    }

    private function tenants(): DoctorCheck
    {
        $discovered = $this->snapshots->discoveredTenants();
        $lines = [];
        foreach ($this->settings->routes as $route) {
            $narrowed = $route->tenants === null ? '' : ' (narrowed to ' . implode(', ', $route->tenants) . ')';
            $lines[] = isset($discovered[$route->service])
                ? sprintf('%s advertises %s%s', $route->service, implode(', ', $discovered[$route->service]), $narrowed)
                : sprintf('%s advertises no tenants yet%s', $route->service, $narrowed);
        }

        return DoctorCheck::info('tenants', implode('; ', $lines) . '. A multi-tenant service advertises its tenants in its snapshot; Pulse needs no tenant configuration.');
    }

    private function replyChannels(): string
    {
        $channels = [];
        foreach ($this->settings->connections as $connection) {
            $channels[] = sprintf("connection %s: %s '%s'", $connection->name, $connection->transport->destinationKind(), $connection->replyDestination);
        }

        return sprintf("Pulse reads replies from %s. A service maps the Pulse service '%s' to that channel, which PulseConfiguration does on its own when it uses the same builder and connection as its request channel.", implode('; ', $channels), $this->settings->serviceName);
    }
}
