<?php

declare(strict_types=1);

use App\Kernel;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($_SERVER['ECOTONE_LICENCE_KEY'] ?? getenv('ECOTONE_LICENCE_KEY')) == false) {
    $_SERVER['ECOTONE_LICENCE_KEY'] = $_ENV['ECOTONE_LICENCE_KEY'] = LicenceTesting::VALID_LICENCE;
    putenv('ECOTONE_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);
}

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel('test', true);
$application = new Application($kernel);
$application->setAutoExit(false);
foreach ([
    ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true],
    ['command' => 'messenger:setup-transports'],
    ['command' => 'ecotone:migration:database:setup', '--initialize' => true],
] as $command) {
    $output = new BufferedOutput();
    if ($application->run(new ArrayInput($command), $output) !== 0) {
        throw new RuntimeException(sprintf('The test bootstrap could not prepare the ticketing_test database: "%s" failed with the output below. Check that the postgres container of the demo is healthy, then rerun the tests.%s%s', $command['command'], PHP_EOL, $output->fetch()));
    }
}
$kernel->shutdown();
