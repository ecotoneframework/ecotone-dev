<?php

declare(strict_types=1);

use App\EventProjecting\PartitionedProjection\Domain\Command\CreateWallet;
use App\EventProjecting\PartitionedProjection\Domain\Command\DebitWallet;
use App\EventProjecting\PartitionedProjection\Domain\Command\CreditWallet;
use App\EventProjecting\PartitionedProjection\ReadModel\WalletBalanceProjection;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

require __DIR__ . "/vendor/autoload.php";

// ============================================
// ENTERPRISE LICENSE REQUIRED
// ============================================
// This example demonstrates Partitioned Projections, which is an Ecotone Enterprise feature.
//
// To run this example, you need an Enterprise License Key.
// Set the ECOTONE_LICENSE_KEY environment variable with your license key.
//
// For evaluation: Contact support@simplycodedsoftware.com for a trial key
// For production: Visit https://ecotone.tech
// ============================================

$licenseKey = getenv('ECOTONE_LICENSE_KEY') ?: null;

// For monorepo development only - use test license
if ($licenseKey === null && file_exists(__DIR__ . '/../../../packages/Ecotone/src/Test/LicenceTesting.php')) {
    require_once __DIR__ . '/../../../packages/Ecotone/src/Test/LicenceTesting.php';
    $licenseKey = \Ecotone\Test\LicenceTesting::VALID_LICENCE;
}

if ($licenseKey === null) {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                    ENTERPRISE LICENSE REQUIRED                           ║\n";
    echo "╠══════════════════════════════════════════════════════════════════════════╣\n";
    echo "║                                                                          ║\n";
    echo "║  Partitioned Projections are part of Ecotone Enterprise.                ║\n";
    echo "║                                                                          ║\n";
    echo "║  To run this example:                                                    ║\n";
    echo "║  1. Set ECOTONE_LICENSE_KEY environment variable                        ║\n";
    echo "║  2. Or contact support@simplycodedsoftware.com for a trial key          ║\n";
    echo "║                                                                          ║\n";
    echo "║  Production licenses: https://ecotone.tech                               ║\n";
    echo "║                                                                          ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    exit(1);
}

// Use PostgreSQL for event sourcing (SQLite is not supported by Prooph event store)
$dsn = getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
$connectionFactory = new DbalConnectionFactory($dsn);
$connection = $connectionFactory->establishConnection();

$messagingSystem = EcotoneLiteApplication::bootstrap(
    objectsToRegister: [
        DbalConnectionFactory::class => $connectionFactory,
        \Doctrine\DBAL\Connection::class => $connection
    ],
    serviceConfiguration: ServiceConfiguration::createWithDefaults()->withLicenceKey($licenseKey),
    pathToRootCatalog: __DIR__
);

echo "=== Partitioned Projection Example ===\n\n";

// Step 0: Delete projection to ensure clean state
echo "0. Cleaning up (deleting existing projection)...\n";
$messagingSystem->runConsoleCommand("ecotone:projection:delete", ["name" => "wallet_balance"]);
echo "   ✓ Projection deleted (clean state)\n\n";

// Step 1: Initialize projection (creates table)
echo "1. Initializing projection...\n";
$messagingSystem->runConsoleCommand("ecotone:projection:init", ["name" => "wallet_balance"]);
echo "   ✓ Projection initialized (table created)\n\n";

// Step 2: Create wallets
echo "2. Creating wallets...\n";
$wallet1 = Uuid::uuid4()->toString();
$wallet2 = Uuid::uuid4()->toString();

$messagingSystem->getCommandBus()->send(new CreateWallet($wallet1, 1000.00));
$messagingSystem->getCommandBus()->send(new CreateWallet($wallet2, 500.00));
echo "   ✓ Created wallet 1: $wallet1 with balance 1000.00\n";
echo "   ✓ Created wallet 2: $wallet2 with balance 500.00\n\n";

// Step 3: Perform transactions
echo "3. Performing transactions...\n";
$messagingSystem->getCommandBus()->send(new DebitWallet($wallet1, 100.00));
echo "   ✓ Debited 100.00 from wallet 1\n";
$messagingSystem->getCommandBus()->send(new CreditWallet($wallet1, 50.00));
echo "   ✓ Credited 50.00 to wallet 1\n";
$messagingSystem->getCommandBus()->send(new DebitWallet($wallet2, 200.00));
echo "   ✓ Debited 200.00 from wallet 2\n\n";

// Step 4: Run projection backfill (processes all partitions)
echo "4. Running projection backfill (processes all partitions)...\n";
$messagingSystem->runConsoleCommand('ecotone:projection:backfill', ['name' => WalletBalanceProjection::NAME]);
echo "   ✓ Projection backfilled (all wallet partitions processed)\n\n";

// Step 5: Query balances
echo "5. Querying wallet balances...\n";
$balance1 = $messagingSystem->getQueryBus()->sendWithRouting('wallet.getBalance', $wallet1);
Assert::assertEquals(950.00, $balance1, "Wallet 1 balance should be 950.00");
echo "   ✓ Wallet 1 balance: $balance1 (expected: 950.00)\n";

$balance2 = $messagingSystem->getQueryBus()->sendWithRouting('wallet.getBalance', $wallet2);
Assert::assertEquals(300.00, $balance2, "Wallet 2 balance should be 300.00");
echo "   ✓ Wallet 2 balance: $balance2 (expected: 300.00)\n\n";

// Step 6: Query detailed statistics
echo "6. Querying wallet statistics...\n";
$stats1 = $messagingSystem->getQueryBus()->sendWithRouting('wallet.getStatistics', $wallet1);
Assert::assertEquals([
    'balance' => 950.00,
    'total_debits' => 100.00,
    'total_credits' => 50.00,
    'transaction_count' => 2,
], $stats1);
echo "   ✓ Wallet 1 stats: " . json_encode($stats1) . "\n";

$stats2 = $messagingSystem->getQueryBus()->sendWithRouting('wallet.getStatistics', $wallet2);
Assert::assertEquals([
    'balance' => 300.00,
    'total_debits' => 200.00,
    'total_credits' => 0.00,
    'transaction_count' => 1,
], $stats2);
echo "   ✓ Wallet 2 stats: " . json_encode($stats2) . "\n\n";

echo "=== Example completed successfully! ===\n";

