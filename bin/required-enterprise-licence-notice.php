<?php
/*
 * licence Apache-2.0
 */

return static function (SplFileInfo $file): ?string {
    if (! preg_match('/^\s*\*\s*(?:@licence|licence)\s+(Enterprise|Apache\-2\.0|MIT|BSD\-3\-Clause)\s*$/m', (string) file_get_contents($file->getRealPath()), $matches) || $matches[1] !== 'Enterprise') {
        return null;
    }

    return preg_match('#/packages/[^/]+/Api/#', str_replace('\\', '/', $file->getRealPath())) === 1
        ? __DIR__ . '/enterprise-licence-api-notice.php'
        : __DIR__ . '/enterprise-licence-notice.php';
};
