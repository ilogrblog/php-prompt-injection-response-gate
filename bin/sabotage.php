<?php
declare(strict_types=1);

/**
 * Proves that each check can fail. Run it through bin/sabotage.sh, which runs it in a throwaway container.
 *
 * For every entry below it copies the repository inside the container, weakens ResponseGate.php in one
 * way, and expects check.php to fail while the weakened file still passes `php -l`. A copy that still
 * passes means a check is missing. The unchanged copy must pass first.
 */

// It writes weakened copies under /tmp, so it only runs in the container that bin/sabotage.sh starts.
if (getenv('SABOTAGE_IN_THROWAWAY_CONTAINER') !== '1') {
    fwrite(STDERR, "STOP  run ./bin/sabotage.sh instead; this file writes weakened copies and only runs in its container.\n");
    exit(2);
}

$source = $argv[1] ?? '/app';
$work = '/tmp/sabotage';

// Each entry: label => [text that must appear exactly once in ResponseGate.php, its weakened replacement].
$weakenings = [
    'Unicode tag block no longer rejected' => ['\x{E0000}-\x{E007F}', '\x{E0000}'],
    'Directional controls no longer rejected' => ['\x{202A}-\x{202E}', '\x{202A}'],
    'Zero-width space no longer rejected' => ['\x{200B}', '\x{200C}'],
    'Whitespace-only answer accepted' => ["preg_match('/\\A[\\p{Z}\\s]*\\z/u', \$response->answer) === 1", 'false'],
    'Answer text not escaped' => ["static fn(string \$text): string => htmlspecialchars(\n            \$text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'\n        )", 'static fn(string $text): string => $text'],
    'Extra keys allowed in the response' => ["\$keys !== ['answer', 'outcome', 'source_ids']", 'false'],
    'Response size limit removed' => ['strlen($json) > 32768', 'false'],
    'Abstain may carry model-written text' => ["\$response->answer !== '' || \$response->source_ids !== []", '$response->source_ids !== []'],
    'Duplicate citations allowed' => [' || isset($seen[$id])', ''],
    'Wrong-site manifest accepted' => ["(\$source['site_id'] ?? null) !== \$siteId", 'false'],
    'Evidence label site not matched to manifest' => ["(int) \$matches['site'] !== \$source['site_id']", 'false'],
    'Evidence label source not matched to manifest' => ["(int) \$matches['source'] !== \$source['source_id']", 'false'],
    'Evidence label revision not matched to manifest' => ["(int) \$matches['revision'] !== \$source['revision']", 'false'],
    'Evidence label start not anchored' => ["'/\\As(?<site>", "'/s(?<site>"],
    'Evidence label end not anchored' => ["c[1-9][0-9]*\\z/D'", "c[1-9][0-9]*/'"],
    'Unknown outcome accepted' => ["\$response->outcome !== 'answer'", 'false'],
    'Answer length limit removed' => ['strlen($response->answer) > 12000', 'false'],
    'Citation count limit removed' => ['count($response->source_ids) > 20', 'false'],
    'Answer without citations accepted' => ['count($response->source_ids) < 1', 'false'],
    'Answer without evidence accepted' => ["\n            || \$manifest === []", ''],
    'Unknown citation accepted' => ['!array_key_exists($id, $manifest) || ', ''],
    'Evidence entry limit removed' => ['count($manifest) > 20', 'false'],
    'Site ID below 1 accepted' => ['$siteId < 1 || ', ''],
    'Scan continues after unreadable evidence' => ["throw new RuntimeException('evidence_unreadable');", '$hasChanged = true; continue;'],
    'Truthy permission accepted' => ["(\$current['readable'] ?? null) !== true", '!$current[\'readable\']'],
    'Changed revision ignored' => ['$hasChanged = true;', '$hasChanged = false;'],
    'Only cited evidence rechecked' => ['foreach ($manifest as $source) {
            $current', 'foreach (array_intersect_key($manifest, $seen) as $source) {
            $current'],
];

function lintOk(string $file): bool
{
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $status);
    return $status === 0;
}

function freshCopy(string $source, string $work): void
{
    if (!is_dir($work)) {
        mkdir($work);
    }
    foreach (['ResponseGate.php', 'check.php'] as $file) {
        copy($source . '/' . $file, $work . '/' . $file);
    }
}

function checkPasses(string $work): bool
{
    exec('php ' . escapeshellarg($work . '/check.php') . ' 2>&1', $out, $status);
    return $status === 0;
}

freshCopy($source, $work);
if (!checkPasses($work)) {
    fwrite(STDERR, "STOP  the unchanged repository does not pass its own checks.\n");
    exit(2);
}
echo "PASS  unchanged copy: checks pass\n";

$original = file_get_contents($source . '/ResponseGate.php');
$missed = 0;
foreach ($weakenings as $label => [$from, $to]) {
    if (substr_count($original, $from) !== 1) {
        fwrite(STDERR, "STOP  the text for \"$label\" was not found exactly once; update bin/sabotage.php.\n");
        exit(2);
    }
    freshCopy($source, $work);
    file_put_contents($work . '/ResponseGate.php', str_replace($from, $to, $original));
    if (!lintOk($work . '/ResponseGate.php')) {
        fwrite(STDERR, "STOP  the weakened copy for \"$label\" does not lint; fix the weakening.\n");
        exit(2);
    }
    if (checkPasses($work)) {
        echo "FAIL  $label: the checks still pass, so a check is missing\n";
        ++$missed;
    } else {
        echo "PASS  $label: the checks fail, as they should\n";
    }
}

exit($missed === 0 ? 0 : 1);
