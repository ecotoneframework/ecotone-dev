<?php
/*
 * licence Apache-2.0
 */

return static function (string $monorepoDirectory): void {
    $requiredNotice = require __DIR__ . '/required-enterprise-licence-notice.php';
    $enterpriseFiles = require __DIR__ . '/enterprise-licence-files.php';
    $noticeBodyLines = static fn (string $notice): array => array_slice(explode("\n", $notice), 2, -2);
    $supersededNoticeLastLines = array_map(
        static fn (string $noticeFile): string => array_slice($noticeBodyLines(require $noticeFile), -1)[0],
        [__DIR__ . '/enterprise-licence-notice.php', __DIR__ . '/enterprise-licence-api-notice.php'],
    );

    foreach (iterator_to_array($enterpriseFiles($monorepoDirectory)) as $file) {
        $noticeFile = $requiredNotice($file);
        if ($noticeFile === null) {
            continue;
        }

        $notice = require $noticeFile;
        $noticeBody = implode("\n", $noticeBodyLines($notice)) . "\n";
        $lines = file($file->getRealPath());
        $markerLine = array_key_first(preg_grep('/^\s*\*\s*(@licence|licence)\s+Enterprise\s*$/', $lines));
        if ($markerLine === null || implode('', array_slice($lines, $markerLine + 1, substr_count($noticeBody, "\n"))) === $noticeBody) {
            continue;
        }

        $supersededNoticeEnd = null;
        for ($index = $markerLine + 1; $index < count($lines) && trim($lines[$index]) !== '*/'; $index++) {
            if (in_array(rtrim($lines[$index], "\r\n"), $supersededNoticeLastLines, true)) {
                $supersededNoticeEnd = $index;
            }
        }

        $lineAfterMarker = trim($lines[$markerLine + 1] ?? '');
        $lineBeforeMarker = trim($lines[$markerLine - 1] ?? '');
        if ($supersededNoticeEnd !== null) {
            array_splice($lines, $markerLine + 1, $supersededNoticeEnd - $markerLine, [$noticeBody]);
        } elseif ($lineAfterMarker === '*/' && in_array($lineBeforeMarker, ['/*', '/**'], true)) {
            array_splice($lines, $markerLine - 1, 3, [$notice]);
        } else {
            array_splice($lines, $markerLine + 1, 0, [$noticeBody . (in_array($lineAfterMarker, ['*/', '*'], true) ? '' : " *\n")]);
        }
        file_put_contents($file->getRealPath(), implode('', $lines));
    }
};
