<?php
$named = json_decode(file_get_contents($argv[1]), true);
$free = [];
foreach (file($argv[2], FILE_IGNORE_NEW_LINES) as $l) { [$f, $c] = explode(' ', $l); $free["$f $c"] = true; }
$all = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('packages', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) { $p = $file->getPathname(); if (str_contains($p, '/vendor/') || !str_ends_with($p, '.php')) continue; $all[$p] = file_get_contents($p); }
$nsOf = fn ($src) => preg_match('/^namespace\s+([^;]+);/m', $src, $m) ? $m[1] : '';
$nsMap = array_map($nsOf, $all);
$hits = 0;
foreach ($named as $f => $classes) {
    $ns = $nsMap[$f];
    foreach ($classes as $c => $_) {
        $fq = "$ns\\$c";
        $users = [];
        foreach ($all as $p => $src) {
            if ($p === $f || !str_contains($src, $c)) continue;
            if (str_contains($src, $fq) || ($nsMap[$p] === $ns && preg_match('/\b' . preg_quote($c, '/') . '\b/', $src))) $users[] = $p;
        }
        if ($users) { $hits++; echo (isset($free["$f $c"]) ? 'FREE ' : 'other ') . "$f $c <- " . implode(', ', $users) . "\n"; }
    }
}
echo "TOTAL $hits\n";
