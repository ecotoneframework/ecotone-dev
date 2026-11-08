<?php
/*
 * licence Apache-2.0
 */

declare(strict_types=1);

const AGENTS_MD_MAX_CHARACTERS = 200;
const AGENTS_MD_POINTER = '.agents/skills/framework-orientation';
const SKILL_DESCRIPTION_MAX_CHARACTERS = 1024;
const RULE_HEADING = '/^### ([A-Z]?[0-9]+[a-z]?)\. (\S[^\n]*)$/';

const DETERMINISTIC_CHECKS = [
    'agent-skills' => '.github/workflows/agent-skills.yml: php bin/check-agent-skills.php',
    'licence-header' => '.github/workflows/file-licence.yml: php bin/check-licence.php',
    'suite-parity' => '.github/workflows/test-monorepo.yml: php bin/check-suite-parity.php',
    'phpstan' => 'composer tests:phpstan in every package CI run',
    'contribution-terms' => '.github/workflows/contribution-check.yml',
];

const SKILL_ROOTS = [
    'framework' => ['skills' => '.agents/skills', 'evals' => '.agents/evals'],
    'public' => ['skills' => 'api/skills', 'evals' => 'api/evals'],
];

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');
$violations = [];
$graderReferences = [];

checkAgentsMd($root, $violations);
checkSymlinks($root, $violations);
foreach (SKILL_ROOTS as $audience => $paths) {
    $skillDirectories = glob($root . '/' . $paths['skills'] . '/*', GLOB_ONLYDIR) ?: [];
    if ($skillDirectories === []) {
        $violations[] = "{$paths['skills']} holds no skill.\nThe directory is what agents load; empty, they work with no project rules. Restore the skills from git.";
    }
    foreach ($skillDirectories as $skillDirectory) {
        checkSkill(
            root: $root,
            audience: $audience,
            skillDirectory: $skillDirectory,
            evalsDirectory: $root . '/' . $paths['evals'],
            violations: $violations,
            graderReferences: $graderReferences,
        );
    }
    checkEvalCases($root, $root . '/' . $paths['evals'], $graderReferences, $violations);
}
checkMarketplace($root, $violations);
checkPublicSkillImports($root, $violations);

if ($violations !== []) {
    fwrite(STDERR, implode("\n\n", $violations) . "\n\n" . count($violations) . " agent skill violation(s).\n");
    exit(1);
}

echo "Agent skills are valid.\n";

function checkAgentsMd(string $root, array &$violations): void
{
    if (! is_file($root . '/AGENTS.md')) {
        $violations[] = "AGENTS.md is missing.\nEvery agent starts from it, and CLAUDE.md links to it. Restore it with a pointer to " . AGENTS_MD_POINTER . ' (git checkout -- AGENTS.md).';
        return;
    }
    $content = file_get_contents($root . '/AGENTS.md');
    $length = mb_strlen($content);
    if ($length > AGENTS_MD_MAX_CHARACTERS) {
        $violations[] = sprintf(
            "AGENTS.md is %d characters, over the %d-character limit.\nEvery agent loads AGENTS.md on every task, so anything in it costs context on work it is irrelevant to.\nMove the instruction into the .agents/skills/ skill whose 'Applies when' covers it, or create one (see .agents/skills/framework-agent-tooling).",
            $length,
            AGENTS_MD_MAX_CHARACTERS,
        );
    }
    if (! str_contains($content, AGENTS_MD_POINTER)) {
        $violations[] = "AGENTS.md does not point to " . AGENTS_MD_POINTER . ".\nIt is the only instruction every agent loads, and its one job is to send the agent to the skill that maps work to rules. Add the path back.";
    }
}

function checkSymlinks(string $root, array &$violations): void
{
    $expected = ['CLAUDE.md' => 'AGENTS.md', '.claude/skills' => '../.agents/skills'];
    foreach ($expected as $link => $target) {
        $path = $root . '/' . $link;
        if (! is_link($path) || readlink($path) !== $target || ! file_exists($path)) {
            $violations[] = sprintf(
                "%s must be a symlink to an existing %s.\nClaude Code reads %s while other agents read the target; a copy drifts from it, and a dangling link loads nothing.\nRecreate it: rm -rf %s && ln -s %s %s",
                $link, $target, $link, $link, $target, $link,
            );
        }
    }
}

function checkSkill(string $root, string $audience, string $skillDirectory, string $evalsDirectory, array &$violations, array &$graderReferences): void
{
    $skillName = basename($skillDirectory);
    $skillFile = $skillDirectory . '/SKILL.md';
    $relative = relativePath($root, $skillFile);
    if (! is_file($skillFile)) {
        $violations[] = "{$relative} is missing.\nA skill directory without SKILL.md is never loaded. Add SKILL.md or remove the directory.";
        return;
    }

    $content = str_replace("\r\n", "\n", file_get_contents($skillFile));
    if (! preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $content, $parts)) {
        $violations[] = "{$relative} has no YAML frontmatter.\nAgents choose a skill by its frontmatter 'name' and 'description'. Start the file with ---, name: {$skillName}, description: ..., ---.";
        return;
    }
    [$frontmatter, $body] = [$parts[1], $parts[2]];

    if (! preg_match('/^name:\s*(\S+)\s*$/m', $frontmatter, $name) || $name[1] !== $skillName) {
        $violations[] = "{$relative} frontmatter 'name' must be '{$skillName}'.\nThe name is how the skill is addressed and must equal its directory name. Set: name: {$skillName}";
    }

    $description = frontmatterDescription($frontmatter);
    if ($description === '' || ! str_contains($description, 'Use when')) {
        $violations[] = "{$relative} description must state when it applies with a 'Use when ...' sentence.\nThe description is the only part an agent reads before deciding to load the skill.";
    }
    if (mb_strlen($description) > SKILL_DESCRIPTION_MAX_CHARACTERS) {
        $violations[] = sprintf("%s description is %d characters, over %d.\nAgents truncate longer descriptions. Shorten it and move detail into the body.", $relative, mb_strlen($description), SKILL_DESCRIPTION_MAX_CHARACTERS);
    }

    if (! preg_match('/^## Applies when\n+((?:- .+\n?)+)/m', $body)) {
        $violations[] = "{$relative} has no '## Applies when' section with at least one '- ' scenario.\nWithout it neither an agent nor a test can tell whether the skill should have been used.";
    }

    checkRules($root, $relative, $skillName, $body, $evalsDirectory, $violations, $graderReferences);
    checkLinks($root, $audience, $skillDirectory, $violations);
}

function frontmatterDescription(string $frontmatter): string
{
    if (! preg_match('/^description:\s*(.*?)(?=^\w[\w-]*:|\z)/ms', $frontmatter, $match)) {
        return '';
    }

    return trim((string) preg_replace('/\s+/', ' ', preg_replace('/^[>|]-?\s*/', '', trim($match[1]))));
}

function checkRules(string $root, string $relative, string $skillName, string $body, string $evalsDirectory, array &$violations, array &$graderReferences): void
{
    if (! preg_match('/^## Rules\n(.*?)(?=^## |\z)/ms', $body, $section)) {
        $violations[] = "{$relative} has no '## Rules' section.\nEvery skill states what it expects as numbered rules, so each one can be asserted by a check or an eval case.";
        return;
    }

    $rules = [];
    $current = null;
    foreach (explode("\n", $section[1]) as $line) {
        if (str_starts_with($line, '### ')) {
            if (! preg_match(RULE_HEADING, $line, $heading)) {
                $violations[] = "{$relative} heading '{$line}' inside '## Rules' is not a rule heading.\nWrite it as '### <id>. <title>', where <id> is a number with an optional one-letter prefix and suffix (12, 12a, P3). A heading in another shape would hide a rule from every check.";
                $current = null;
                continue;
            }
            $current = count($rules);
            $rules[] = ['id' => $heading[1], 'title' => $heading[2], 'body' => ''];
            continue;
        }
        if ($current !== null) {
            $rules[$current]['body'] .= $line . "\n";
        }
    }
    if ($rules === []) {
        $violations[] = "{$relative} '## Rules' holds no rule.\nWrite each rule as '### <id>. <title>' followed by '- **Expected:**' and '- **Verified by:**' lines.";
    }

    $seen = [];
    foreach ($rules as ['id' => $id, 'title' => $title, 'body' => $ruleBody]) {
        $where = "{$relative} rule {$id} ({$title})";
        if (isset($seen[$id])) {
            $violations[] = "{$where} reuses id {$id}.\nA rule id names one rule, so a grader and a review finding can cite it. Give it the next free id.";
        }
        $seen[$id] = true;

        if (! preg_match('/^- \*\*Expected:\*\* \S/m', $ruleBody)) {
            $violations[] = "{$where} has no '- **Expected:**' line.\nState the observable outcome a reviewer or a grader can assert, e.g. 'the constructor parameter is required and non-nullable'.";
        }
        if (! preg_match('/^- \*\*Verified by:\*\* (.+)$/m', $ruleBody, $verifiedBy)) {
            $violations[] = "{$where} has no '- **Verified by:**' line.\nName the deterministic check (`check:<id>`) or eval case (`eval:<case>`) that asserts it. Prefer a check: see framework-agent-tooling rule A1.";
            continue;
        }

        preg_match_all('/`([a-z]+):([\w-]+)`/', $verifiedBy[1], $verifiers, PREG_SET_ORDER);
        if ($verifiers === []) {
            $violations[] = "{$where} 'Verified by' names no `check:<id>` or `eval:<case>`.\nWrite the verifier in backticks, e.g. - **Verified by:** `eval:add-dependency` or `check:phpstan`.";
        }
        foreach ($verifiers as [, $kind, $target]) {
            if ($kind === 'check') {
                if (! array_key_exists($target, DETERMINISTIC_CHECKS)) {
                    $violations[] = sprintf(
                        "%s names unknown check `check:%s`.\nKnown checks: %s. Register a new deterministic check in DETERMINISTIC_CHECKS in bin/check-agent-skills.php together with the CI step that runs it.",
                        $where, $target, implode(', ', array_keys(DETERMINISTIC_CHECKS)),
                    );
                }
                continue;
            }
            if ($kind !== 'eval') {
                $violations[] = "{$where} names `{$kind}:{$target}`, an unknown verifier kind.\nOnly `check:<id>` (a deterministic check CI runs) and `eval:<case>` (an eval case with a grader per rule) verify a rule.";
                continue;
            }
            $graderFile = sprintf('rule-%s.md', strtolower($id));
            $graderReferences[$skillName][$target][$graderFile] = true;
            $grader = sprintf('%s/%s/%s/graders/%s', $evalsDirectory, $skillName, $target, $graderFile);
            if (! is_file($grader)) {
                $violations[] = sprintf(
                    "%s names `eval:%s`, but %s does not exist.\nEach rule an eval case verifies has its own grader named after the rule, so a failing score points at one rule. Create the grader, or point 'Verified by' at the case that has it.",
                    $where, $target, relativePath($root, $grader),
                );
            }
        }
    }
}

function checkLinks(string $root, string $audience, string $skillDirectory, array &$violations): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($skillDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'md') {
            continue;
        }
        $relative = relativePath($root, $file->getPathname());
        $content = file_get_contents($file->getPathname());
        $prose = preg_replace('/^```.*?^```/ms', '', $content);

        preg_match_all('/\]\(<?([^)\s#>]*)(#[^)\s>]*)?>?(?:\s+"[^"]*")?\)/', $prose, $links, PREG_SET_ORDER);
        foreach ($links as $link) {
            [$path, $anchor] = [$link[1], $link[2] ?? ''];
            if (preg_match('#^(https?:|mailto:)#', $path)) {
                continue;
            }
            $resolved = $path === '' ? $file->getPathname() : realpath(dirname($file->getPathname()) . '/' . $path);
            if ($resolved === false) {
                $violations[] = "{$relative} links to {$path}, which does not exist.\nFix the path, or remove the link if the target was deleted.";
                continue;
            }
            if ($anchor !== '' && str_ends_with($resolved, '.md') && ! in_array(substr($anchor, 1), headingAnchors($resolved), true)) {
                $violations[] = "{$relative} links to {$path}{$anchor}, but no heading in " . relativePath($root, $resolved) . " produces that anchor.\nThe section moved or was renamed. Point the link at the file that now holds it.";
            }
            if ($audience === 'public' && ! str_starts_with($resolved, $root . '/api/')) {
                $violations[] = "{$relative} links to {$path}, outside api/.\nPublic skills are split to ecotoneframework/skills and installed into applications, where repository files do not exist. Link to https://docs.ecotone.tech instead.";
            }
            if ($audience === 'framework' && str_starts_with($resolved, $root . '/docs/')) {
                $violations[] = "{$relative} links to {$path} under docs/.\nFramework agent instructions live in .agents/skills so only the relevant ones load. Move the content into the skill's references/.";
            }
        }

        preg_match_all('#`((?:docs|bin|\.agents|api|\.github)/[\w./-]+\.(?:md|php|yml))`#', $prose, $paths);
        foreach (array_unique($paths[1]) as $path) {
            if (! str_starts_with($path, 'docs/superpowers/') && ! file_exists($root . '/' . $path)) {
                $violations[] = "{$relative} names `{$path}`, which does not exist.\nThe file moved or was deleted; an agent following the instruction finds nothing. Name its current path.";
            }
        }

        if ($audience === 'public' && preg_match('/\.agents\/|ecotone-dev|agents\.md|coding-conventions|dev-workflow|packages\/\w+\/(?:src|tests)\//i', $content, $leak)) {
            $violations[] = "{$relative} mentions '{$leak[0]}'.\nPublic skills teach Ecotone users their own application code; repository-internal paths and conventions belong in .agents/skills.";
        }
    }
}

function headingAnchors(string $markdownFile): array
{
    preg_match_all('/^#{1,6} (.+)$/m', preg_replace('/^```.*?^```/ms', '', file_get_contents($markdownFile)), $headings);
    $anchors = [];
    foreach ($headings[1] as $heading) {
        $slug = preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower(trim($heading)));
        $anchor = str_replace(' ', '-', $slug);
        $candidate = $anchor;
        for ($suffix = 1; in_array($candidate, $anchors, true); $suffix++) {
            $candidate = $anchor . '-' . $suffix;
        }
        $anchors[] = $candidate;
    }

    return $anchors;
}

function checkEvalCases(string $root, string $evalsDirectory, array $graderReferences, array &$violations): void
{
    if (! is_dir($evalsDirectory)) {
        return;
    }
    foreach (glob($evalsDirectory . '/*/*', GLOB_ONLYDIR) as $caseDirectory) {
        $skillName = basename(dirname($caseDirectory));
        $caseName = basename($caseDirectory);
        if ($skillName === 'results') {
            continue;
        }
        $relative = relativePath($root, $caseDirectory);
        if (! is_file($caseDirectory . '/prompt.md')) {
            $violations[] = "{$relative} has no prompt.md.\nAn eval case is a prompt plus graders; claude plugin eval skips a directory without one.";
        }
        if (! is_file($caseDirectory . '/graders/skill-loaded.md')) {
            $violations[] = "{$relative} has no graders/skill-loaded.md.\nIt proves the skill is found from its description; without it a case can pass on the model's own knowledge. Add a tool_used grader for the Skill tool matching '{$skillName}'.";
        }
        if (! is_dir(dirname($evalsDirectory) . '/skills/' . $skillName)) {
            $violations[] = "{$relative} is grouped under a skill that does not exist.\nPlace a case under evals/<skill-name>/<case>/, matching the skill it verifies.";
            continue;
        }
        if (! isset($graderReferences[$skillName][$caseName])) {
            $violations[] = "{$relative} is not named by any rule of {$skillName}.\nA case no rule cites verifies nothing the skill states. Point a rule's 'Verified by' at `eval:{$caseName}`, or delete the case.";
            continue;
        }
        foreach (glob($caseDirectory . '/graders/rule-*.md') as $grader) {
            if (! isset($graderReferences[$skillName][$caseName][basename($grader)])) {
                $violations[] = relativePath($root, $grader) . " grades a rule that does not cite `eval:{$caseName}`.\nThe rule was renumbered or removed. Rename the grader to the rule's current id, or delete it.";
            }
        }
    }
}

function checkMarketplace(string $root, array &$violations): void
{
    $pluginRoot = $root . '/api';
    $marketplace = json_decode((string) @file_get_contents($pluginRoot . '/.claude-plugin/marketplace.json'), true);
    $listed = [];
    foreach ($marketplace['plugins'] ?? [] as $plugin) {
        foreach ($plugin['skills'] ?? [] as $skill) {
            $listed[] = (string) realpath($pluginRoot . '/' . ($plugin['source'] ?? './') . '/' . $skill);
        }
    }
    $present = array_map('realpath', glob($pluginRoot . '/skills/*', GLOB_ONLYDIR) ?: []);
    sort($listed);
    sort($present);
    if ($listed !== $present) {
        $violations[] = sprintf(
            "api/.claude-plugin/marketplace.json lists skills [%s] but api/skills holds [%s].\nA skill missing from the marketplace is never installed by users; a listed one that is missing breaks the install. Make the 'skills' list match the directories, as ./skills/<name>.",
            implode(', ', array_map(fn (string $path) => $path === '' ? '<missing>' : basename($path), $listed)),
            implode(', ', array_map('basename', $present)),
        );
    }
}

function checkPublicSkillImports(string $root, array &$violations): void
{
    $namespaces = [];
    foreach (glob($root . '/packages/*/composer.json') as $composerFile) {
        $composer = json_decode(file_get_contents($composerFile), true);
        foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $directories) {
            foreach ((array) $directories as $directory) {
                $namespaces[$prefix][] = dirname($composerFile) . '/' . rtrim($directory, '/');
            }
        }
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/api/skills', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'md') {
            continue;
        }
        foreach (array_unique(referencedEcotoneClasses(file_get_contents($file->getPathname()))) as $class) {
            if (! classResolves($class, $namespaces)) {
                $violations[] = sprintf(
                    "%s references %s, which does not exist in packages/.\nA user who pastes the sample gets a class-not-found error. Verify the name against the tree; upgrade/namespace-map-2.0.csv maps 1.x names to 2.0.",
                    relativePath($root, $file->getPathname()), $class,
                );
            }
        }
    }
}

function referencedEcotoneClasses(string $markdown): array
{
    $classes = [];
    preg_match_all('/^\s*use (Ecotone\\\\[\w\\\\]+)(?: as \w+)?;/m', $markdown, $imports);
    array_push($classes, ...$imports[1]);

    preg_match_all('/^\s*use (Ecotone\\\\[\w\\\\]+)\\\\\{([^}]+)\};/m', $markdown, $groups, PREG_SET_ORDER);
    foreach ($groups as [, $prefix, $members]) {
        foreach (explode(',', $members) as $member) {
            $classes[] = $prefix . '\\' . trim(preg_replace('/\s+as\s+\w+$/', '', trim($member)));
        }
    }

    preg_match_all('/(?<![\w\\\\])\\\\(Ecotone\\\\[\w\\\\]*[A-Z]\w*)(?=::class|\(|\]|\s|$)/m', $markdown, $qualified);
    array_push($classes, ...$qualified[1]);

    return $classes;
}

function classResolves(string $class, array $namespaces): bool
{
    foreach ($namespaces as $prefix => $directories) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }
        foreach ($directories as $directory) {
            if (is_file($directory . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) {
                return true;
            }
        }
    }

    return false;
}

function relativePath(string $root, string $path): string
{
    return ltrim(substr($path, strlen($root)), '/');
}
