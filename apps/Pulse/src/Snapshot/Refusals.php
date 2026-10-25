<?php

declare(strict_types=1);

namespace App\Snapshot;

final readonly class Refusals
{
    /**
     * @param list<ServiceSnapshot> $scopes
     * @param list<string>|null $onlyScopes
     * @return list<array{scope: string, message: string}>
     */
    public static function of(array $scopes, string $requestKind, ?array $onlyScopes = null): array
    {
        $refusals = [];
        foreach ($scopes as $scope) {
            $message = $scope->refusalOf($requestKind);
            if ($message !== null && ($onlyScopes === null || in_array($scope->scope(), $onlyScopes, true) || in_array($scope->service, $onlyScopes, true))) {
                $refusals[] = ['scope' => $scope->scope(), 'message' => $message];
            }
        }

        return $refusals;
    }
}
