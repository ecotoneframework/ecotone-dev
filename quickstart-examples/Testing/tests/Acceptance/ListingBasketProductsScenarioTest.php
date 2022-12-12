<?php

declare(strict_types=1);

namespace Test\App\Acceptance;

use App\Testing\Domain\Product\Command\AddProduct;
use App\Testing\Domain\Product\Product;
use App\Testing\Domain\ShoppingBasket\Basket;
use App\Testing\Domain\ShoppingBasket\Command\AddProductToBasket;
use App\Testing\Domain\ShoppingBasket\Event\OrderWasPlaced;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasAddedToBasket;
use App\Testing\Domain\ShoppingBasket\ProductService;
use App\Testing\Domain\User\Command\RegisterUser;
use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\PhoneNumber;
use App\Testing\Domain\User\User;
use App\Testing\Domain\Verification\Command\VerifyEmail;
use App\Testing\Domain\Verification\Command\VerifyPhoneNumber;
use App\Testing\Domain\Verification\TokenGenerator;
use App\Testing\Domain\Verification\VerificationProcess;
use App\Testing\Domain\Verification\VerificationSender;
use App\Testing\Domain\Verification\VerificationToken;
use App\Testing\Infrastructure\Converter\EmailConverter;
use App\Testing\Infrastructure\Converter\PhoneNumberConverter;
use App\Testing\Infrastructure\Converter\UuidConverter;
use App\Testing\Infrastructure\MessagingConfiguration;
use App\Testing\ReadModel\CurrentBasketProjection;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\App\Fixture\StubTokenGenerator;

final class ListingBasketProductsScenarioTest extends TestCase
{
    public function test_listing_products_added_to_basket_after_success_user_registration()
    {
        $userId = Uuid::uuid4();
        $productId = Uuid::uuid4();
        $productPrice = 500;
        $emailToken = "123";
        $phoneNumberToken = "12345";

        $this->assertEquals(
            [$productId->toString() => $productPrice],
            $this->getTestSupport($emailToken, $phoneNumberToken)
                ->sendCommand(new AddProduct($productId, "Milk", $productPrice))
                ->sendCommand(new RegisterUser($userId, "John Snow", Email::create('test@wp.pl'), PhoneNumber::create('148518518518')))
                ->sendCommand(new VerifyEmail($userId, VerificationToken::from($emailToken)))
                ->sendCommand(new VerifyPhoneNumber($userId, VerificationToken::from($phoneNumberToken)))
                ->sendCommand(new AddProductToBasket($userId, $productId))
                ->sendQueryWithRouting(CurrentBasketProjection::GET_CURRENT_BASKET_QUERY, $userId)
        );
    }

    private function getTestSupport(string $emailToken, $phoneNumberToken): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            [User::class, Basket::class, VerificationProcess::class, Product::class, CurrentBasketProjection::class, VerificationSender::class, ProductService::class],
            [new CurrentBasketProjection(), new EmailConverter(), new PhoneNumberConverter(), new UuidConverter(), TokenGenerator::class => new StubTokenGenerator([$emailToken, $phoneNumberToken]), new VerificationSender()],
            configuration: ServiceConfiguration::createWithDefaults()
                // Loading converters, so they can be used for events
                ->withNamespaces(["App\Testing\Infrastructure\Converter"])
                ->withSkippedModulePackageNames([])
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(MessagingConfiguration::ASYNCHRONOUS_MESSAGES, true),
                    PollingMetadata::create(MessagingConfiguration::ASYNCHRONOUS_MESSAGES)->withTestingSetup()
                ]),
            pathToRootCatalog: __DIR__, // can be ignored, needed for running inside ecotone-dev monorepo
        );
    }
}