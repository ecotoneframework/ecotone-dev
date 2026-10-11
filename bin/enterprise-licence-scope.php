<?php
/*
 * licence Apache-2.0
 */

return function (SplFileInfo $file): bool {
    if (! preg_match('/^\s*\*\s*(?:@licence|licence)\s+(Enterprise|Apache\-2\.0|MIT|BSD\-3\-Clause)\s*$/m', (string) file_get_contents($file->getRealPath()), $matches)) {
        return false;
    }

    return $matches[1] === 'Enterprise';
};
