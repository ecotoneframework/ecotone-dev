<?php

use App\Testing\Domain\Product\Command\AddProduct;
use App\Testing\Domain\ShoppingBasket\Command\AddProductToBasket;
use App\Testing\Domain\User\Command\RegisterUser;
use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\PhoneNumber;
use App\Testing\Domain\Verification\Command\VerifyEmail;
use App\Testing\Domain\Verification\Command\VerifyPhoneNumber;
use App\Testing\Domain\Verification\TokenGenerator;
use App\Testing\Domain\Verification\VerificationSender;
use App\Testing\Domain\Verification\VerificationToken;
use App\Testing\Infrastructure\Converter\EmailConverter;
use App\Testing\Infrastructure\Converter\PhoneNumberConverter;
use App\Testing\Infrastructure\Converter\UuidConverter;
use App\Testing\ReadModel\CurrentBasketProjection;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;
use Test\App\Fixture\StubTokenGenerator;

require __DIR__ . "/vendor/autoload.php";

$userId = Uuid::uuid4();
$productId = Uuid::uuid4();
$productPrice = 500;
$emailToken = "123";
$phoneNumberToken = "12345";

/** Production usage of tested code. Stores everything in database. */

$ecotoneLite = EcotoneLite::bootstrap(
    [],
    [
        new CurrentBasketProjection(), new EmailConverter(), new PhoneNumberConverter(),
        new UuidConverter(), TokenGenerator::class => new StubTokenGenerator([$emailToken, $phoneNumberToken]),
        new VerificationSender(), DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone')
    ],
    ServiceConfiguration::createWithDefaults()->withLoadCatalog("src"),
    pathToRootCatalog: __DIR__, // can be ignored, needed for running inside ecotone-dev monorepo
);

$commandBus = $ecotoneLite->getCommandBus();
$queryBus = $ecotoneLite->getQueryBus();

$commandBus->send(new AddProduct($productId, "Milk", $productPrice));
$commandBus->send(new RegisterUser($userId, "John Snow", Email::create('test@wp.pl'), PhoneNumber::create('148518518518')));
$commandBus->send(new VerifyEmail($userId, VerificationToken::from($emailToken)));
$commandBus->send(new VerifyPhoneNumber($userId, VerificationToken::from($phoneNumberToken)));
$commandBus->send(new AddProductToBasket($userId, $productId));

Assert::assertEquals(
    [$productId->toString() => $productPrice],
    $queryBus->sendWithRouting(CurrentBasketProjection::GET_CURRENT_BASKET_QUERY, $userId)
);

echo "Scenario succeeded.\n";
echo "\nRun tests by vendor/bin/phpunit\n";