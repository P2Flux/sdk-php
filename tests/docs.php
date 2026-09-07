<?php

declare(strict_types=1);

/**
 * Checks the README and docs/ against the code they document.
 *
 *   php tests/docs.php
 *
 * Every PHP snippet must parse, every method and class named in prose must exist, every client
 * option must be one the client accepts, and no page may still advertise a stale package name or
 * an install route that no longer applies.
 */

require __DIR__ . '/../src/P2FluxException.php';
require __DIR__ . '/../src/ChargeResult.php';
require __DIR__ . '/../src/P2FluxClient.php';
require __DIR__ . '/../src/CurlTransport.php';

use P2Flux\ChargeResult;
use P2Flux\P2FluxClient;

$root = dirname(__DIR__);
$failures = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "  ok    {$label}\n";
        return;
    }
    $failures++;
    echo "  FAIL  {$label}  {$detail}\n";
}

$pages = array_merge(
    [$root . '/README.md'],
    glob($root . '/docs/*.md') ?: [],
    glob($root . '/docs/*/*.md') ?: [],
    glob($root . '/examples/*/README.md') ?: []
);
check('pages found', count($pages) > 12, (string) count($pages));

$version = (string) (json_decode((string) file_get_contents($root . '/package.json'), true)['version'] ?? '');
check('package.json carries a version', $version !== '');

$publicMethods = array_map(
    static fn (ReflectionMethod $m): string => $m->getName(),
    (new ReflectionClass(P2FluxClient::class))->getMethods(ReflectionMethod::IS_PUBLIC)
);
// Prose may name an internal helper when explaining behaviour; a documented CALL must be public.
$methods = array_map(
    static fn (ReflectionMethod $m): string => $m->getName(),
    (new ReflectionClass(P2FluxClient::class))->getMethods()
);
$resultProperties = array_map(
    static fn (ReflectionProperty $p): string => $p->getName(),
    (new ReflectionClass(ChargeResult::class))->getProperties(ReflectionProperty::IS_PUBLIC)
);
$classes = ['P2FluxClient', 'P2FluxException', 'ChargeResult', 'CurlTransport'];
$clientOptions = ['apiUrl', 'timeout', 'transport'];

$tmp = sys_get_temp_dir() . '/p2flux-docs-' . getmypid() . '.php';
register_shutdown_function(static function () use ($tmp): void {
    @unlink($tmp);
});

foreach ($pages as $page) {
    $name = ltrim(str_replace($root, '', $page), '/');
    $text = (string) file_get_contents($page);

    // --- every ```php fence parses -------------------------------------------------------
    preg_match_all('/```php\n(.*?)```/s', $text, $fences);
    foreach ($fences[1] as $index => $snippet) {
        /* Documentation snippets are fragments of different shapes: a whole file, a couple of
         * statements, the body of a method, a class member, a slice of a config array. Each shape
         * is valid PHP in its own context, so try the contexts in turn and accept the first that
         * parses - a snippet that parses in none of them is genuinely broken. */
        $wrappers = str_starts_with(ltrim($snippet), '<?php')
            ? [static fn (string $s): string => $s]
            : [
                static fn (string $s): string => "<?php\n" . $s . "\n",
                static fn (string $s): string => "<?php\nfunction p2fluxSnippet() {\n" . $s . "\n}\n",
                static fn (string $s): string => "<?php\nclass P2FluxSnippet {\n" . $s . "\n}\n",
                static fn (string $s): string => "<?php\nclass P2FluxSnippet { function m() {\n" . $s . "\n} }\n",
                static fn (string $s): string => "<?php\n\$p2fluxSnippet = [\n" . $s . "\n];\n",
                static fn (string $s): string => "<?php\n\$p2fluxSnippet\n" . $s . "\n;\n",
            ];

        $status = 1;
        $lint = [];
        foreach ($wrappers as $wrap) {
            file_put_contents($tmp, $wrap($snippet));
            $lint = [];
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $status);
            if ($status === 0) {
                break;
            }
        }
        check("{$name}: snippet " . ($index + 1) . ' parses', $status === 0, implode(' ', $lint));
    }

    // --- every $p2flux->method() named anywhere on the page exists -----------------------
    preg_match_all('/\$(?:p2flux|client)->([a-zA-Z]+)\(/', $text, $calls);
    foreach (array_unique($calls[1]) as $method) {
        check("{$name}: P2FluxClient::{$method}() is public", in_array($method, $publicMethods, true));
    }

    // --- method names mentioned in prose as `foo()` --------------------------------------
    preg_match_all('/`([a-z][a-zA-Z]+)\(/', $text, $prose);
    $known = array_merge($methods, [
        'charge', 'approve', 'revoke', 'transfer', 'wp_remote_post', 'wp_json_encode',
        'json_decode', 'is_wp_error', 'array_filter', 'in_array', 'rawurlencode', 'match',
        'function', 'fn', 'encrypt', 'decrypt',
    ]);
    foreach (array_unique($prose[1]) as $method) {
        check("{$name}: `{$method}()` is a real method", in_array($method, $known, true));
    }

    // --- every P2Flux\Class referenced exists --------------------------------------------
    preg_match_all('/P2Flux\\\\+([A-Z][A-Za-z]+)/', $text, $referenced);
    foreach (array_unique($referenced[1]) as $class) {
        check("{$name}: class P2Flux\\{$class} exists", in_array($class, $classes, true));
    }

    // --- ChargeResult properties -------------------------------------------------------
    preg_match_all('/\$(?:result|found)->([a-zA-Z]+)\b(?!\()/', $text, $properties);
    foreach (array_unique($properties[1]) as $property) {
        check("{$name}: ChargeResult::\${$property} exists", in_array($property, $resultProperties, true));
    }

    // --- client options ------------------------------------------------------------------
    if (preg_match('/new P2FluxClient\(\[(.*?)\]\)/s', $text, $construct) === 1) {
        preg_match_all("/'([a-zA-Z]+)'\s*=>/", $construct[1], $options);
        foreach (array_unique($options[1]) as $option) {
            check("{$name}: client option '{$option}' is accepted", in_array($option, $clientOptions, true));
        }
    }

    // --- no stale package name, no stale install route, no stale version -----------------
    check("{$name}: no stale package name", !str_contains($text, 'p2flux/p2flux-php'));
    check("{$name}: no \"not on Packagist\" claim", stripos($text, 'not on packagist') === false);

    /* Any 0.7.x that is not the version this repository ships is stale. Derived, so a release
     * never has to remember to update a list here. */
    preg_match_all('/v?(0\.7\.\d+)\b/', $text, $versions);
    $stale = array_values(array_unique(array_filter(
        $versions[1],
        static fn (string $found): bool => $found !== $version
    )));
    check("{$name}: no stale version string", $stale === [], implode(', ', $stale));

    /* Claims this product does not support. Targeted phrases, not sentence parsing: each one is
     * something a reader would act on, and none of them can be true of P2Flux. */
    foreach ([
        'webhook secret', 'webhook signature', 'verify the webhook', 'register a webhook',
        'webhook url', 'webhook endpoint', 'configure a webhook', 'webhook handler',
        'your api key', 'apikey', "'api_key'", 'authorization: bearer', 'x-api-key',
        'gas-free', 'gas free', 'free transaction', 'no network fee',
    ] as $forbidden) {
        check("{$name}: no \"{$forbidden}\" claim", stripos($text, $forbidden) === false);
    }

    // --- relative links resolve ----------------------------------------------------------
    preg_match_all('/\]\((?!https?:|#)([^)#]+)(?:#[^)]*)?\)/', $text, $links);
    foreach (array_unique($links[1]) as $link) {
        $target = realpath(dirname($page) . '/' . $link);
        check("{$name}: link {$link} resolves", $target !== false && str_starts_with($target, $root));
    }
}

// --- the two facts a developer must not be left to guess ---------------------------------

foreach ([
    'README.md' => 'no webhooks',
    'docs/payment-flow.md' => 'no webhooks',
    'docs/getting-started.md' => 'no API key',
] as $page => $phrase) {
    $text = (string) file_get_contents($root . '/' . $page);
    check("{$page} states \"{$phrase}\"", stripos($text, $phrase) !== false);
}

// --- the install command the README promises is the package composer.json declares -------

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
check('composer.json name is p2flux/sdk-php', ($composer['name'] ?? '') === 'p2flux/sdk-php');
check(
    'README install command matches the package name',
    str_contains((string) file_get_contents($root . '/README.md'), 'composer require ' . $composer['name'])
);
check('PSR-4 root maps the shipped namespace', ($composer['autoload']['psr-4']['P2Flux\\'] ?? '') === 'src/');
check('no runtime dependency beyond php and ext-json', array_keys($composer['require'] ?? []) === ['php', 'ext-json']);

echo $failures === 0 ? "\ndocs: all checks passed\n" : "\ndocs: {$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
