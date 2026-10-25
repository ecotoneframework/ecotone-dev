<?php

declare(strict_types=1);

use Ecotone\Api\Dbal\DbalConnectionFactory;

require __DIR__ . '/bootstrap.php';

$connection = (new DbalConnectionFactory(demoDsn('orders')))->createContext()->getDbalConnection();
$connection->executeStatement('CREATE TABLE IF NOT EXISTS demo_seed (seeded_at TIMESTAMP NOT NULL)');
if ((int) $connection->fetchOne('SELECT COUNT(*) FROM demo_seed') > 0) {
    echo "already seeded\n";
    exit(0);
}
$connection->executeStatement('INSERT INTO demo_seed (seeded_at) VALUES (CURRENT_TIMESTAMP)');

$bus = bootDemoService('orders')->getCommandBus();
$count = (int) ($argv[1] ?? 12);
for ($number = 1; $number <= $count; $number++) {
    $bus->sendWithRouting('order.place', [
        'orderId' => 'ord-' . (1000 + $number),
        'sku' => $number % 5 === 0 ? 'OUT-OF-STOCK' : 'SKU-' . $number,
        'card' => $number % 4 === 0 ? 'DECLINED' : 'OK',
    ]);
}
echo "seeded $count orders\n";
