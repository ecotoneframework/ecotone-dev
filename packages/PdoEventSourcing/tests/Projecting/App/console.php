<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\ProjectionRegistry;
use Enqueue\Dbal\DbalConnectionFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command\CancelOrder;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command\PlaceOrder;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command\ShipOrder;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Order;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\OrderListProjection;
use Test\Ecotone\EventSourcing\Projecting\App\Tooling\CommitOnUserInputInterceptor;
use Test\Ecotone\EventSourcing\Projecting\App\Tooling\WaitBeforeExecutingProjectionInterceptor;

$messagingSystem = require __DIR__ . '/app.php';

$app = new Application();

$app->setName('Order Management tool');

$app->register('stream:load')
    ->setDescription('Load data fixtures to database')
    ->addArgument('count', null, 'How many orders to generate', 1000)
    ->addOption('--start-from', '-s', InputOption::VALUE_OPTIONAL, 'Order number to start from', 1)
    ->addOption('--clean', '-c', null, 'Clean database before loading')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($messagingSystem) {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('clean')) {
            $io->section('Cleaning database');
            $eventStore = $messagingSystem->getGatewayByName(EventStore::class);
            if ($eventStore->hasStream(Order::STREAM_NAME)) {
                $eventStore->delete(Order::STREAM_NAME);
                $io->writeln('Event store cleaned');
            }
            $projection = $messagingSystem->getGatewayByName(ProjectionRegistry::class)->get(OrderListProjection::PROJECTION_NAME);
            $projection->delete();
            $io->success('Database cleaned');
        }

        $startFrom = (int)$input->getOption('start-from');
        $count = (int)$input->getArgument('count');
        $io->section("Loading $count orders");
        $io->progressStart($count);
        for ($i = $startFrom; $i < $count + $startFrom; $i++) {
            $orderId = 'order-' . $i;
            $messagingSystem->getCommandBus()->send(new PlaceOrder($orderId, 'Book', 2));
            $messagingSystem->getCommandBus()->send($i % 3 === 0 ? new CancelOrder($orderId, 'Out of stock') : new ShipOrder($orderId));
            $io->progressAdvance();
        }
        $io->progressFinish();
    });

$app->register('stream:add-gaps')
    ->setDescription('Add gaps to the stream')
    ->addOption('--gap-size', '-S', InputOption::VALUE_OPTIONAL, 'Size of each gap', 1)
    ->addOption('--transactions', '-T', InputOption::VALUE_OPTIONAL, 'How many transactional iterations', 1)
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($messagingSystem) {
        $io = new SymfonyStyle($input, $output);
        $connection = $messagingSystem->getGatewayByName(DbalConnectionFactory::class)->establishConnection();
        $eventStore = $messagingSystem->getGatewayByName(EventStore::class);

        $gapSize = (int)$input->getOption('gap-size');
        $transactions = (int)$input->getOption('transactions');
        for ($j = 0; $j < $transactions; $j++) {
            $connection->beginTransaction();
            for ($i = 0; $i < $gapSize; $i++) {
                $eventStore->appendTo(Order::STREAM_NAME, [
                    Event::createWithType('order-gap', [], ['_aggregate_type' => Order::AGGREGATE_TYPE, '_aggregate_id' => uniqid('order-gap-'), '_aggregate_version' => 0]),
                ]);
            }
            $connection->rollBack();
        }
        $io->success("Added $transactions gaps of size $gapSize to the stream");
    });

$app->register('projection:delete')
    ->setDescription('Delete projection data')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($messagingSystem) {
        $io = new SymfonyStyle($input, $output);
        $projection = $messagingSystem->getGatewayByName(ProjectionRegistry::class)->get(OrderListProjection::PROJECTION_NAME);
        $projection->delete();
        $io->success('Projection data deleted');
    });

$app->register('projection:backfill')
    ->setDescription('Trigger projection to rebuild data')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($messagingSystem) {
        $io = new SymfonyStyle($input, $output);
        $projection = $messagingSystem->getGatewayByName(ProjectionRegistry::class)->get(OrderListProjection::PROJECTION_NAME);
        $io->section('Triggering projection');
        $projection->backfill();
        $io->success('Projection backfilled');
    });

$app->register('order:place')
    ->setDescription('Place new order')
    ->addArgument('orderId', null, 'Order ID')
    ->addOption('--product', null, InputOption::VALUE_OPTIONAL, 'Product name', 'Book')
    ->addOption('--quantity', null, InputOption::VALUE_OPTIONAL, 'Quantity', 1)
    ->addOption('--fail', '-F', InputOption::VALUE_NONE, 'Simulate failure in projection handler')
    ->addOption('--manual-commit', '-C', InputOption::VALUE_NONE, 'Use manual transaction commit')
    ->addOption('--manual-projection', '-P', InputOption::VALUE_NONE, 'Use manual projection execution')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($messagingSystem) {
        $io = new SymfonyStyle($input, $output);

        $orderId = $input->getArgument('orderId');
        if ($input->getOption('manual-commit')) {
            $messagingSystem->getGatewayByName(CommitOnUserInputInterceptor::class)->enable();
        }
        if ($input->getOption('manual-projection')) {
            $messagingSystem->getGatewayByName(WaitBeforeExecutingProjectionInterceptor::class)->enable();
        }
        try {
            $messagingSystem->getCommandBus()->send(new PlaceOrder($orderId, (string)$input->getOption('product'), (int)$input->getOption('quantity'), (bool)$input->getOption('fail')));
        } catch (ConcurrencyException $exception) {
            $io->error("Error placing order: an order with ID $orderId already exists");
            return Command::FAILURE;
        }
        $io->success("Order $orderId placed");

        return Command::SUCCESS;
    });

$app->register('order:ship')
    ->setDescription('Ship existing order')
    ->addArgument('orderId', null, 'Order ID')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($messagingSystem) {
        $io = new SymfonyStyle($input, $output);

        $orderId = $input->getArgument('orderId');
        try {
            $messagingSystem->getCommandBus()->send(new ShipOrder($orderId));
        } catch (Throwable $exception) {
            $io->error('Error shipping order: ' . $exception->getMessage());
            return Command::FAILURE;
        }
        $io->success("Order $orderId shipped");
    });

$app->register('order:cancel')
    ->setDescription('Cancel existing order')
    ->addArgument('orderId', null, 'Order ID')
    ->addOption('--reason', '-R', InputOption::VALUE_OPTIONAL, 'Reason for cancellation', 'Customer request')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($messagingSystem) {
        $io = new SymfonyStyle($input, $output);

        $orderId = $input->getArgument('orderId');
        try {
            $messagingSystem->getCommandBus()->send(new CancelOrder($orderId, (string)$input->getOption('reason')));
        } catch (Throwable $exception) {
            $io->error('Error cancelling order: ' . $exception->getMessage());
            return Command::FAILURE;
        }
        $io->success("Order $orderId cancelled");
    });

$app->register('run')
    ->setDescription('Continuously update orders')
    ->addArgument('count', null, 'Maximum order number', 1000)
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($messagingSystem) {
        $io = new SymfonyStyle($input, $output);
        $io->section('Running order management tool');
        $count = (int)$input->getArgument('count');
        $io->writeln("Updating orders from 0 to $count");
        while (true) {
            $orderId = 'order-' . random_int(1, $count);
            try {
                if (random_int(0, 1) === 0) {
                    $messagingSystem->getCommandBus()->send(new CancelOrder($orderId, 'Customer request'));
                    $io->writeln("Cancelled order $orderId");
                } else {
                    $messagingSystem->getCommandBus()->send(new ShipOrder($orderId));
                    $io->writeln("Shipped order $orderId");
                }
            } catch (Throwable $exception) {
                $io->error("Error processing order $orderId: " . $exception->getMessage());
            }
            usleep(100000);
        }
    });


$app->run();
