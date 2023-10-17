<?php

declare(strict_types=1);

namespace App\ReadModel;

use App\Domain\Product\Event\ProductWasAdded;
use App\Domain\Product\Event\ProductWasApproved;
use App\Domain\Product\Product;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * @link https://docs.ecotone.tech/modelling/event-sourcing/setting-up-projections
 */
// we provide the name of the projection and related aggregate to fetch events from
#[Projection("unapproved_product_list", Product::class)]
final class UnapprovedProductList
{
    const COLLECTION_NAME = 'unapproved_products';

    #[EventHandler]
    public function whenProductAdded(ProductWasAdded $event, DocumentStore $documentStore): void
    {
        /**
         * We are using Document Store, as it has In Memory implementation in tests.
         * Yet we could use in here any other implementation, like MongoDB, ElasticSearch, etc.
         */
        $documentStore->addDocument(
            self::COLLECTION_NAME,
            $event->productId->toString(),
            [
                'productId' => $event->productId->toString(),
                'name' => $event->name,
                'price' => $event->price->getAmount()
            ]
        );
    }

    #[EventHandler]
    public function whenProductApproved(ProductWasApproved $event, DocumentStore $documentStore): void
    {
        $documentStore->deleteDocument(
            self::COLLECTION_NAME,
            $event->productId->toString()
        );
    }

    #[QueryHandler("getUnapprovedProducts")]
    public function getUnapprovedProducts(#[Reference] DocumentStore $documentStore): array
    {
        return $documentStore->getAllDocuments(self::COLLECTION_NAME);
    }
}