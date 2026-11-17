<?php

declare(strict_types=1);

/*
 * Fails when code uses a translation key that locale/en.yml doesn't have, or en.yml has a key no code
 * uses. Keys built at runtime (`audience.${audience}`) match as patterns.
 *
 * Run: php tests/locale-check.php
 */

require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$prefix = 'ernestdefoe-glimpse';

/** @param array<string, mixed> $tree */
function flatten(array $tree, string $path = ''): array
{
    $keys = [];
    foreach ($tree as $key => $value) {
        $full = $path === '' ? (string) $key : $path.'.'.$key;
        $keys = array_merge($keys, is_array($value) ? flatten($value, $full) : [$full]);
    }

    return $keys;
}

$yaml = Yaml::parseFile("$root/locale/en.yml");
$defined = flatten($yaml[$prefix] ?? []);

$used = [];
$sources = [
    // forum/admin helpers prepend their area: t('bar.title') is forum.bar.title.
    'js/src/forum' => ['pattern' => '/\btx?\(\s*[\'`]([^\'`]+)[\'`]/', 'area' => 'forum.'],
    'js/src/admin' => ['pattern' => '/\bt\(\s*[\'`]([^\'`]+)[\'`]/', 'area' => 'admin.'],
];
foreach ($sources as $dir => $source) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir"));
    foreach ($files as $file) {
        if (! preg_match('/\.tsx?$/', (string) $file)) {
            continue;
        }
        $code = (string) file_get_contents((string) $file);
        preg_match_all($source['pattern'], $code, $m);
        foreach ($m[1] as $key) {
            $used[] = $source['area'].$key;
        }
        // t(condition ? 'a' : 'b') and t(`a.${x}`) with a ternary inside: every literal in the call.
        preg_match_all('/\btx?\(\s*[^\n?]*?\?\s*\'([^\']+)\'\s*:\s*\'([^\']+)\'/', $code, $ternary);
        foreach (array_merge($ternary[1], $ternary[2]) as $key) {
            $used[] = $source['area'].$key;
        }
        // Keys written out in full anywhere (widgets, admin option groups).
        preg_match_all('/'.$prefix.'\.([a-z_.]+[a-z_])/', $code, $full);
        foreach ($full[1] as $key) {
            $used[] = $key;
        }
    }
}
// Admin option groups built from lists: options([...], 'placement') etc.
$admin = (string) file_get_contents("$root/js/src/admin/extend.ts");
preg_match_all("/options\((.*?),\s*'([a-z_]+)'\)/", $admin, $groups, PREG_SET_ORDER);
foreach ($groups as [, $lists, $group]) {
    // Only the literals inside [...] lists; installed('…') ids are not option keys.
    preg_match_all('/\[([^\]]*)\]/', $lists, $arrays);
    foreach ($arrays[1] as $list) {
        preg_match_all("/'([a-z_]+)'/", $list, $items);
        foreach ($items[1] as $item) {
            $used[] = "admin.$group.$item";
        }
    }
}
foreach (['parley', 'messages'] as $channel) {
    $used[] = "admin.reply.$channel";
}

// PHP and views.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/src")) as $file) {
    if (str_ends_with((string) $file, '.php')) {
        $code = (string) file_get_contents((string) $file);
        preg_match_all('/'.$prefix.'\.([a-z_.]+[a-z_])/', $code, $m);
        $used = array_merge($used, $m[1]);
        // trans('ernestdefoe-glimpse.api.'.$key): the keys passed to fail().
        if (str_contains($code, "'$prefix.api.'.")) {
            preg_match_all("/fail\(\s*'(?:[a-z]+'\s*,\s*')?([a-z_]+)'/", $code, $api);
            foreach ($api[1] as $key) {
                $used[] = "api.$key";
            }
        }
    }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/views")) as $file) {
    if (str_ends_with((string) $file, '.php')) {
        preg_match_all('/'.$prefix.'\.([a-z_.]+[a-z_])/', (string) file_get_contents((string) $file), $m);
        $used = array_merge($used, $m[1]);
    }
}

// A key prefix concatenated in code ('ernestdefoe-glimpse.api.'.$key) is not a key itself.
$used = array_values(array_unique(array_filter($used, fn (string $key): bool => str_contains($key, '.'))));
$toRegex = fn (string $key): string => '/^'.str_replace('\*', '[a-zA-Z0-9_]+', preg_quote(preg_replace('/\$\{[^}]+\}/', '*', $key) ?? $key, '/')).'$/';

$missing = [];
foreach ($used as $key) {
    $regex = $toRegex($key);
    if (! array_filter($defined, fn (string $d): bool => preg_match($regex, $d) === 1)) {
        $missing[] = $key;
    }
}

$unused = [];
foreach ($defined as $key) {
    if (! array_filter($used, fn (string $u): bool => preg_match($toRegex($u), $key) === 1)) {
        $unused[] = $key;
    }
}

foreach ($missing as $key) {
    fwrite(STDERR, "Missing from en.yml: $prefix.$key\n");
}
foreach ($unused as $key) {
    fwrite(STDERR, "Not used anywhere: $prefix.$key\n");
}
echo count($used).' keys used, '.count($defined)." defined.\n";
exit($missing || $unused ? 1 : 0);
