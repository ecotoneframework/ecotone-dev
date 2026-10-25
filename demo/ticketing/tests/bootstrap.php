<?php

declare(strict_types=1);

use Ecotone\Test\LicenceTesting;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($_SERVER['ECOTONE_LICENCE_KEY'] ?? getenv('ECOTONE_LICENCE_KEY')) == false) {
    $_SERVER['ECOTONE_LICENCE_KEY'] = $_ENV['ECOTONE_LICENCE_KEY'] = LicenceTesting::VALID_LICENCE;
    putenv('ECOTONE_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);
}

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
