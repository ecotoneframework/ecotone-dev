<?php

declare(strict_types=1);

namespace Ecotone\Modelling\Config\DatabaseTransaction;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;

/**
 * licence Apache-2.0
 */
final class AsynchronousHandlersWithoutTransaction
{
    /**
     * @return AnnotatedFinding[]
     */
    public static function in(AnnotationFinder $annotationFinder): array
    {
        return array_values(array_filter(
            $annotationFinder->findAnnotatedMethods(Asynchronous::class),
            static fn (AnnotatedFinding $annotatedMethod): bool => $annotatedMethod->hasMethodAnnotation(WithoutDatabaseTransaction::class)
                || $annotatedMethod->hasClassAnnotation(WithoutDatabaseTransaction::class)
                || array_filter(
                    $annotatedMethod->getMethodAnnotationsWithType(Asynchronous::class),
                    static fn (Asynchronous $asynchronous): bool => array_filter($asynchronous->asynchronousExecution, static fn (object $attribute): bool => $attribute instanceof WithoutDatabaseTransaction) !== [],
                ) !== [],
        ));
    }
}
