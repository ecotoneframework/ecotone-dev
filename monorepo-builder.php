<?php

declare(strict_types=1);

use Monorepo\SetCurrentMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\ComposerJsonManipulator\ValueObject\ComposerJsonSection;
use Symplify\MonorepoBuilder\Config\MBConfig;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\AddTagToChangelogReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushNextDevReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushTagReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetNextMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\TagVersionReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\UpdateBranchAliasReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\UpdateReplaceReleaseWorker;

return static function (MBConfig $containerConfigurator): void {
    $containerConfigurator->packageDirectories([__DIR__ . '/packages', __DIR__ . '/quickstart-examples']);
    $containerConfigurator->dataToAppend([
        ComposerJsonSection::AUTOLOAD_DEV => [
            'psr-4' => [
                "Tests\\Ecotone\\" => "tests",
                "IncorrectAttribute\\" => [
                    "tests\\AnnotationFinder\\Fixture\\Usage\\Attribute\\TestingNamespace\\IncorrectAttribute\\TestingNamespace"
                ],
            ],
        ],

        ComposerJsonSection::REQUIRE_DEV => [
            "behat/behat" => "^3.10",
            "friendsofphp/php-cs-fixer" => "^3.9",
            "php-coveralls/php-coveralls" => "^2.5",
            "phpstan/phpstan" => "^1.8",
            "phpunit/phpunit" => "^9.5",
            "symfony/expression-language" => "^6.0",
            "symplify/monorepo-builder" => "11.1.21"
        ],
    ]);
    $containerConfigurator->defaultBranch('main');

    $services = $containerConfigurator->services();
    # release workers - in order to execute
    $services->set(UpdateReplaceReleaseWorker::class);
    $services->set(SetCurrentMutualDependenciesReleaseWorker::class);
    $services->set(AddTagToChangelogReleaseWorker::class);
    $services->set(TagVersionReleaseWorker::class);
    $services->set(PushTagReleaseWorker::class);
    $services->set(SetNextMutualDependenciesReleaseWorker::class);
    $services->set(UpdateBranchAliasReleaseWorker::class);
    $services->set(PushNextDevReleaseWorker::class);
};