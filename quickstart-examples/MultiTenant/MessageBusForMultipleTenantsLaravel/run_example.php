<?php

use App\MultiTenant\Application\RegisterPerson;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Assert;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Http\Kernel;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
runMigrationForTenants();

//Config::set('database.default', 'tenant_a');
$commandBus = $app->get(CommandBus::class);
$queryBus = $app->get(QueryBus::class);

echo "Running demo:\n";

$commandBus->send(new RegisterPerson(1, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterPerson(2, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterPerson(2, "John Doe"), metadata: ['tenant' => 'tenant_b']);

Assert::assertSame(
    [1,2],
    $queryBus->sendWithRouting('person.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
);

Assert::assertSame(
    [2],
    $queryBus->sendWithRouting('person.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
);