<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$orders = bootDemoService('orders');
$bus = $orders->getCommandBus();
$count = (int) ($argv[1] ?? 12);
for ($number = 1; $number <= $count; $number++) {
    $bus->sendWithRouting('order.place', [
        'orderId' => 'ord-' . (1000 + $number),
        'sku' => $number % 5 === 0 ? 'OUT-OF-STOCK' : 'SKU-' . $number,
        'card' => $number % 4 === 0 ? 'DECLINED' : 'OK',
    ]);
}
echo "seeded $count orders\n";
