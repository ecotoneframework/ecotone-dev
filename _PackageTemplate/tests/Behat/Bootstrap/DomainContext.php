<?php

namespace Test\Ecotone\_PackageTemplate\Behat\Bootstrap;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Setup;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Dbal\Recoverability\DbalDeadLetter;
use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLiteConfiguration;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\QueryBus;
use Enqueue\Dbal\DbalConnectionFactory;
use InvalidArgumentException;

use function json_decode;
use function json_encode;

use PHPUnit\Framework\Assert;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor\AddMetadataInterceptor;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\ErrorConfigurationContext;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\OrderGateway;
use Test\Ecotone\Dbal\Fixture\Deduplication\ChannelConfiguration;
use Test\Ecotone\Dbal\Fixture\Deduplication\Converter;
use Test\Ecotone\Dbal\Fixture\Deduplication\OrderPlaced;
use Test\Ecotone\Dbal\Fixture\Deduplication\OrderSubscriber;
use Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate\PersonJsonConverter;
use Test\Ecotone\Dbal\Fixture\ORM\RegisterPerson;
use Test\Ecotone\Dbal\Fixture\Transaction\OrderService;

class DomainContext extends TestCase implements Context
{

}
