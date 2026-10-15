<?php
/*
 * licence Apache-2.0
 */

return static function (string $monorepoDirectory): array {
    $requiredNotice = require __DIR__ . '/required-enterprise-licence-notice.php';
    $enterpriseFiles = require __DIR__ . '/enterprise-licence-files.php';

    $violations = [];
    foreach ($enterpriseFiles($monorepoDirectory) as $file) {
        $noticeFile = $requiredNotice($file);
        if ($noticeFile === null) {
            continue;
        }

        $notice = require $noticeFile;
        $noticeBody = implode("\n", array_slice(explode("\n", $notice), 2, -2)) . "\n";
        $lines = file($file->getRealPath());
        $markerLine = array_key_first(preg_grep('/^\s*\*\s*(@licence|licence)\s+Enterprise\s*$/', $lines));
        if ($markerLine !== null && implode('', array_slice($lines, $markerLine + 1, substr_count($noticeBody, "\n"))) === $noticeBody) {
            continue;
        }

        $isApiNotice = basename($noticeFile) === 'enterprise-licence-api-notice.php';
        $violations[] = 'Incomplete Enterprise licence notice in file: ' . $file->getRealPath() . "\n"
            . ($isApiNotice
                ? "The file is marked `licence Enterprise` inside a package's `Api/` directory, but the marker line is not followed by the one-sentence Enterprise notice. `Api/` is the public surface loaded into an agent's context, so it carries one sentence pointing at LICENSE-ENTERPRISE, not the full notice that `src/` carries.\n"
                : "The file is marked `licence Enterprise`, but the marker line is not followed by the full Enterprise notice, so a reader of this file alone is not told that the code is proprietary, needs a licence, and may not be copied.\n")
            . 'Run `php bin/add-enterprise-licence.php`, which rewrites the notice of every Enterprise file to the form its directory requires, or put the text of `bin/' . basename($noticeFile) . "` in place of the header by hand. The notice has to follow the first `licence Enterprise` line unchanged:\n"
            . $notice;
    }

    return $violations;
};
