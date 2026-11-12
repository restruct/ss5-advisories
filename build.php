<?php

/**
 * Builds the static Composer repository in public/ that carries security advisories for the
 * restruct CMS 5 backport forks.
 *
 * Composer merges the advisories of every repository a project uses, so a feed cannot overrule
 * Packagist's. Projects therefore exclude the forked (and mirrored) package names from Packagist
 * and get their advisories from here instead. This script copies Packagist's advisories for those
 * packages and, for each advisory triaged in advisories.json, narrows the affected range so the
 * fork's patched tags (and nothing else) drop out of it:
 *
 *   - "fixed-in": every fork tag of the form <upstream version>-pN at or above it carries the fix.
 *     The range becomes the original range minus those tags; every upstream release stays exactly
 *     as affected as before, including later upstream releases on the same line.
 *   - "affected": the range is replaced (an advisory that does not apply to a version line).
 *
 * Anything not triaged is passed through unchanged, so it keeps blocking and reporting as it would
 * from Packagist, and is listed in public/untriaged.json for the workflow to raise as an issue.
 *
 * Each rewritten range is checked against every upstream release on Packagist and every patched
 * fork tag before anything is written; a mismatch stops the build.
 *
 * Usage: php build.php [base-url]
 *   base-url  where public/ is served, default https://restruct.github.io/ss5-advisories
 * Environment:
 *   GITHUB_TOKEN  optional, raises the GitHub API rate limit for reading fork tags
 *   FORK_TAGS     optional JSON {"<package>": ["<tag>", ...]} used instead of the GitHub API (local testing)
 */

require __DIR__ . '/vendor/autoload.php';

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

$baseUrl = rtrim($argv[1] ?? 'https://restruct.github.io/ss5-advisories', '/');
$config = json_decode(file_get_contents(__DIR__ . '/advisories.json'), true, 512, JSON_THROW_ON_ERROR);
$forks = $config['forks'];
$mirrored = array_diff_key($config['mirrored'], ['_comment' => true]);
$triage = $config['advisories'];
$lines = array_diff_key($config['lines'], ['_comment' => true]);
$packages = array_merge(array_keys($forks), array_keys($mirrored));
$out = __DIR__ . '/public';

function fetchJson(string $url, array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'header' => array_merge(['User-Agent: restruct-ss5-advisories'], $headers),
        'ignore_errors' => true,
    ]]);
    $body = file_get_contents($url, false, $context);
    $status = (int) explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
    if ($body === false || $status !== 200) {
        throw new RuntimeException("GET $url failed with status $status");
    }
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Fork tags that are patched builds: <upstream version>-pN.
 */
function forkPatchTags(string $package, string $repo): array
{
    if ($override = getenv('FORK_TAGS')) {
        return json_decode($override, true, 512, JSON_THROW_ON_ERROR)[$package] ?? [];
    }
    $headers = getenv('GITHUB_TOKEN') ? ['Authorization: Bearer ' . getenv('GITHUB_TOKEN')] : [];
    $tags = [];
    for ($page = 1; ; $page++) {
        $batch = fetchJson("https://api.github.com/repos/$repo/tags?per_page=100&page=$page", $headers);
        if (!$batch) {
            break;
        }
        foreach ($batch as $tag) {
            if (preg_match('/^\d+\.\d+\.\d+-p\d+$/', $tag['name'])) {
                $tags[] = $tag['name'];
            }
        }
    }
    return $tags;
}

/**
 * Upstream release versions of a package on Packagist (stable and pre-release, no dev branches).
 */
function upstreamVersions(array $p2, string $package): array
{
    $versions = [];
    foreach ($p2['packages'][$package] ?? [] as $release) {
        # Packagist's minified format leaves out keys that did not change, but always has "version"
        if (isset($release['version'])) {
            $versions[] = $release['version'];
        }
    }
    return $versions;
}

/**
 * The newest stable upstream release on a version line such as "5.4", or null if there is none.
 */
function newestOnLine(array $versions, string $line): ?string
{
    $onLine = array_filter($versions, fn ($v) => preg_match('/^v?' . preg_quote($line, '/') . '\.\d+$/', $v));
    if (!$onLine) {
        return null;
    }
    usort($onLine, fn ($a, $b) => version_compare(ltrim($a, 'v'), ltrim($b, 'v')));
    return end($onLine);
}

/**
 * The original range minus the patched tags at or above $fixedIn.
 *
 * Patched tags are grouped by the upstream version they are built on. For bases b1 < b2 < ... < bn
 * whose lowest patched tags are p1 ... pn, everything below p1 stays affected, then each later base
 * from bi up to pi, and every upstream release after bn. Composer has no negation, so the result is
 * the original range's alternatives ANDed with each of those parts.
 */
function rewriteRange(string $original, array $patchTags, string $fixedIn): string
{
    $parser = new VersionParser();
    $fixedNorm = $parser->normalize($fixedIn);
    $lowestPerBase = [];
    foreach ($patchTags as $tag) {
        if (version_compare($parser->normalize($tag), $fixedNorm, '<')) {
            continue;
        }
        $base = preg_replace('/-p\d+$/', '', $tag);
        if (!isset($lowestPerBase[$base]) || version_compare($parser->normalize($tag), $parser->normalize($lowestPerBase[$base]), '<')) {
            $lowestPerBase[$base] = $tag;
        }
    }
    if (!$lowestPerBase) {
        throw new RuntimeException("no fork tag at or above $fixedIn");
    }
    uksort($lowestPerBase, fn ($a, $b) => version_compare($a, $b));
    $parts = [];
    $first = true;
    foreach ($lowestPerBase as $base => $lowest) {
        $parts[] = $first ? "<$lowest" : ">=$base,<$lowest";
        $first = false;
    }
    [$major, $minor, $patch] = explode('.', array_key_last($lowestPerBase));
    $parts[] = '>=' . $major . '.' . $minor . '.' . ((int) $patch + 1);

    $alternatives = [];
    foreach (preg_split('/\s*\|\|?\s*/', trim($original)) as $orig) {
        foreach ($parts as $part) {
            $alternatives[] = "$orig,$part";
        }
    }
    return implode(' || ', $alternatives);
}

/**
 * Stop the build unless the rewritten range treats every upstream release exactly as the original
 * did and leaves out every patched tag at or above $fixedIn.
 */
function verifyRewrite(string $id, string $original, string $rewritten, array $upstream, array $patchTags, ?string $fixedIn): void
{
    $parser = new VersionParser();
    foreach ($upstream as $version) {
        if (preg_match('/-p\d+$/', $version)) {
            continue;
        }
        if (Semver::satisfies($version, $original) !== Semver::satisfies($version, $rewritten)) {
            throw new RuntimeException("$id: upstream $version changed affectedness under '$rewritten'");
        }
    }
    foreach ($patchTags as $tag) {
        $patched = $fixedIn !== null
            && version_compare($parser->normalize($tag), $parser->normalize($fixedIn), '>=');
        if ($patched && Semver::satisfies($tag, $rewritten)) {
            throw new RuntimeException("$id: patched tag $tag is still affected under '$rewritten'");
        }
        if (!$patched && $fixedIn !== null && Semver::satisfies($tag, $original) !== Semver::satisfies($tag, $rewritten)) {
            throw new RuntimeException("$id: unpatched tag $tag changed affectedness under '$rewritten'");
        }
    }
}

$query = implode('&', array_map(fn ($p) => 'packages[]=' . urlencode($p), $packages));
$packagistAdvisories = fetchJson("https://packagist.org/api/security-advisories/?$query")['advisories'];

@mkdir("$out/p2/silverstripe", 0777, true);
$report = ['built' => gmdate('c'), 'rewritten' => [], 'untriaged' => [], 'stale-triage' => []];
$seen = [];

foreach ($packages as $package) {
    $p2 = fetchJson("https://repo.packagist.org/p2/$package.json");
    $upstream = upstreamVersions($p2, $package);
    $patchTags = isset($forks[$package]) ? forkPatchTags($package, $forks[$package]) : [];

    $advisories = [];
    foreach ($packagistAdvisories[$package] ?? [] as $advisory) {
        $id = $advisory['advisoryId'];
        $seen[$id] = true;
        $entry = $triage[$id] ?? null;
        if ($entry === null || $entry['package'] !== $package) {
            # Passed through unchanged either way; only one that reaches what a CMS 5 site installs
            # (the newest upstream release on the line, or a patched tag) needs triage
            $candidates = array_merge($patchTags, array_filter([newestOnLine($upstream, $lines[$package])]));
            $relevant = (bool) array_filter($candidates, fn ($v) => Semver::satisfies($v, $advisory['affectedVersions']));
            if ($relevant) {
                $report['untriaged'][] = ['package' => $package, 'advisoryId' => $id, 'title' => $advisory['title'],
                    'affectedVersions' => $advisory['affectedVersions'], 'link' => $advisory['link'] ?? null];
            }
            $advisories[] = $advisory;
            continue;
        }
        $original = $advisory['affectedVersions'];
        if (isset($entry['fixed-in'])) {
            $rewritten = rewriteRange($original, $patchTags, $entry['fixed-in']);
            verifyRewrite($id, $original, $rewritten, $upstream, $patchTags, $entry['fixed-in']);
        } else {
            $rewritten = $entry['affected'];
            # A replaced range may only narrow the original: no upstream release may become affected
            foreach ($upstream as $version) {
                if (Semver::satisfies($version, $rewritten) && !Semver::satisfies($version, $original)) {
                    throw new RuntimeException("$id: '$rewritten' marks $version affected, the original does not");
                }
            }
        }
        $advisory['affectedVersions'] = $rewritten;
        $advisories[] = $advisory;
        $report['rewritten'][] = ['package' => $package, 'advisoryId' => $id, 'from' => $original, 'to' => $rewritten];
    }

    if (isset($mirrored[$package])) {
        # Serve Packagist's own metadata (same dist URLs) with the rewritten advisories
        $p2['security-advisories'] = $advisories;
        file_put_contents("$out/p2/$package.json", json_encode($p2, JSON_UNESCAPED_SLASHES));
        $dev = fetchJson("https://repo.packagist.org/p2/$package~dev.json");
        file_put_contents("$out/p2/$package~dev.json", json_encode($dev, JSON_UNESCAPED_SLASHES));
    } else {
        # Forked packages come from the fork's vcs repository; the feed only carries advisories
        $doc = ['packages' => [$package => []], 'security-advisories' => $advisories];
        file_put_contents("$out/p2/$package.json", json_encode($doc, JSON_UNESCAPED_SLASHES));
        file_put_contents("$out/p2/$package~dev.json", json_encode(['packages' => [$package => []]], JSON_UNESCAPED_SLASHES));
    }
}

foreach ($triage as $id => $entry) {
    if (!isset($seen[$id])) {
        $report['stale-triage'][] = $id;
    }
}

file_put_contents("$out/packages.json", json_encode([
    'packages' => [],
    'metadata-url' => "$baseUrl/p2/%package%.json",
    'available-packages' => $packages,
    'security-advisories' => ['metadata' => true, 'api-url' => null],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
file_put_contents("$out/report.json", json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
file_put_contents("$out/untriaged.json", json_encode($report['untriaged'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
# A person who opens the feed URL in a browser gets a page instead of a 404; Composer only reads packages.json.
file_put_contents("$out/index.html", <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ss5-advisories</title>
</head>
<body>
<h1>ss5-advisories</h1>
<p>This is a Composer repository, not a website: Composer reads <a href="packages.json">packages.json</a>.</p>
<p>It carries the security advisories for the Silverstripe CMS 5 security forks, with the patched tags taken out of the
affected ranges. How to use it in a project:
<a href="https://github.com/restruct/ss5-advisories#use-it-in-a-project">README, "Use it in a project"</a>.</p>
<p>Build report: <a href="report.json">report.json</a>.</p>
</body>
</html>

HTML);

printf("rewritten: %d, untriaged: %d, stale triage: %d\n", count($report['rewritten']), count($report['untriaged']), count($report['stale-triage']));
foreach ($report['rewritten'] as $r) {
    printf("  %s %s: %s  ->  %s\n", $r['package'], $r['advisoryId'], $r['from'], $r['to']);
}
foreach ($report['untriaged'] as $u) {
    printf("  UNTRIAGED %s %s %s (%s)\n", $u['package'], $u['advisoryId'], $u['title'], $u['affectedVersions']);
}
foreach ($report['stale-triage'] as $id) {
    printf("  STALE triage entry %s: not in Packagist's advisories any more\n", $id);
}
