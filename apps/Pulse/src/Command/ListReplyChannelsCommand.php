<?php

declare(strict_types=1);

namespace App\Command;

use App\Settings\PulseSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pulse:reply-channels', description: 'Prints, one per line, the reply channel Pulse consumes on every connection')]
final class ListReplyChannelsCommand extends Command
{
    public function __construct(private readonly PulseSettings $settings)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (array_keys($this->settings->replyChannels()) as $channel) {
            $output->writeln($channel);
        }

        return Command::SUCCESS;
    }
}
