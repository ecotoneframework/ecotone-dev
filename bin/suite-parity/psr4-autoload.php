<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

return static function (string $monorepoDirectory): array {
    $readComposer = static fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $normalizePath = static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/');
    $psr4Paths = static fn (array $composer, string $section, string $namespace): array => array_map(
        $normalizePath,
        (array) ($composer[$section]['psr-4'][$namespace] ?? [])
    );
    $jsonEntry = static fn (string $namespace, string $path): string => json_encode($namespace, JSON_UNESCAPED_SLASHES) . ': ' . json_encode($path, JSON_UNESCAPED_SLASHES);

    $rootComposer = $readComposer($monorepoDirectory . '/composer.json');
    $packageComposers = [];
    foreach (glob($monorepoDirectory . '/packages/*/composer.json') as $packageComposerPath) {
        $packageComposers['packages/' . basename(dirname($packageComposerPath))] = $readComposer($packageComposerPath);
    }

    $divergences = [];
    foreach ($packageComposers as $packageDirectory => $packageComposer) {
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ($packageComposer[$section]['psr-4'] ?? [] as $namespace => $paths) {
                foreach ((array) $paths as $path) {
                    $expectedRootPath = $packageDirectory . '/' . $normalizePath($path);
                    if (in_array($expectedRootPath, $psr4Paths($rootComposer, $section, $namespace), true)) {
                        continue;
                    }

                    $divergences[] = "psr-4 autoload: {$packageDirectory}/composer.json maps {$jsonEntry($namespace, $path)} in \"{$section}\", "
                        . "but the root composer.json does not map that namespace to {$expectedRootPath}.\n"
                        . '   The root full suite loads classes through the root autoloader only, so these classes are missing there and every test touching them errors '
                        . "(a ReflectionException from the annotation finder, or a LoaderLoadException from Symfony resource import).\n"
                        . "   Fix: add {$jsonEntry($namespace, $expectedRootPath)} to \"{$section}\".\"psr-4\" in composer.json, then run `composer dump-autoload`.";
                }
            }
        }
    }

    $resolvesInPackage = static function (array $packageComposer, string $packageDirectory, string $namespace, string $rootPath) use ($normalizePath): bool {
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ($packageComposer[$section]['psr-4'] ?? [] as $packageNamespace => $paths) {
                if (! str_starts_with($namespace, $packageNamespace)) {
                    continue;
                }
                $subNamespacePath = str_replace('\\', '/', substr($namespace, strlen($packageNamespace)));
                foreach ((array) $paths as $path) {
                    if ($normalizePath($packageDirectory . '/' . $normalizePath($path) . '/' . $subNamespacePath) === $rootPath) {
                        return true;
                    }
                }
            }
        }

        return false;
    };

    foreach (['autoload', 'autoload-dev'] as $section) {
        foreach ($rootComposer[$section]['psr-4'] ?? [] as $namespace => $paths) {
            foreach ((array) $paths as $path) {
                $rootPath = $normalizePath($path);
                if (! preg_match('#^(packages/[^/]+)/#', $rootPath, $matches) || ! is_dir($monorepoDirectory . '/' . $rootPath)) {
                    continue;
                }
                $packageDirectory = $matches[1];
                if (! isset($packageComposers[$packageDirectory]) || $resolvesInPackage($packageComposers[$packageDirectory], $packageDirectory, $namespace, $rootPath)) {
                    continue;
                }

                $packagePath = substr($rootPath, strlen($packageDirectory) + 1);
                $divergences[] = "psr-4 autoload: the root composer.json maps {$jsonEntry($namespace, $path)} in \"{$section}\", "
                    . "but {$packageDirectory}/composer.json does not resolve that namespace to {$packagePath}.\n"
                    . "   The package's own suite, and the split repository it is released as, load classes through the package autoloader only, so a test that passes in the root full suite fails there.\n"
                    . "   Fix: add {$jsonEntry($namespace, $packagePath)} to \"{$section}\".\"psr-4\" in {$packageDirectory}/composer.json, "
                    . 'or remove the entry from the root composer.json if nothing in the package uses it.';
            }
        }
    }

    return $divergences;
};
