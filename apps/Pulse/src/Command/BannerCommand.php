<?php

declare(strict_types=1);

namespace App\Command;

use App\Settings\Connection;
use App\Settings\PulseSettings;
use App\Settings\ServiceRoute;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pulse:banner', description: 'Prints the three lines the container logs at start: transport, services, UI port and health paths')]
final class BannerCommand extends Command
{
    public function __construct(private readonly PulseSettings $settings)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = getenv('PULSE_HTTP_PORT') ?: '8080';

        $output->writeln('Pulse: transport ' . implode(', ', array_map(static fn (Connection $connection) => $connection->name . '=' . strtolower($connection->transport->name), $this->settings->connections)));
        $output->writeln('Pulse: services ' . implode(', ', array_map(static fn (ServiceRoute $route) => sprintf('%s (%s%s)', $route->service, $route->destination, $route->connection === Connection::DEFAULT ? '' : '@' . $route->connection), $this->settings->routes)));
        $output->writeln(sprintf('Pulse: UI on port %s, liveness /healthz, readiness /readyz', $port));

        return Command::SUCCESS;
    }
}
