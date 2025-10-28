#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

$moduleDir = __DIR__ . '/../vendor/mollie/module-payment';
$outputDir = __DIR__ . '/../docs';

if (!is_dir($moduleDir)) {
    fwrite(STDERR, "❌ Mollie module not found at $moduleDir\n");
    exit(1);
}
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir));
$httpRegex = '#/v\\d+/(payments|orders|refunds)[/\\w:-]*#i';
$apiCalls = [];

foreach ($rii as $file) {
    if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
        $code = @file_get_contents($file->getPathname());
        if ($code && preg_match_all($httpRegex, $code, $m)) {
            foreach ($m[0] as $path) {
                $apiCalls[$path] = true;
            }
        }
    }
}

$apiList = array_keys($apiCalls);
sort($apiList);

$md  = "# Mollie Magento 2 — Auto-Generated API Calls\n\n";
$md .= "Generated automatically from the Mollie Magento 2 plugin source.\n\n";
$md .= "| Endpoint |\n|-----------|\n";
if ($apiList) {
    foreach ($apiList as $p) {
        $md .= "| `{$p}` |\n";
    }
} else {
    $md .= "| _No endpoints found (check workflow paths or regex)_ |\n";
}

file_put_contents($outputDir . '/index.md', $md);
echo "✅ Docs generated in $outputDir\n";

