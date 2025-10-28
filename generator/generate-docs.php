#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

// Path where the Mollie Magento 2 plugin will be cloned by CI
$moduleDir = __DIR__ . '/../vendor/mollie/module-payment';
// Output directory for generated docs
$outputDir = __DIR__ . '/../docs';

if (!is_dir($moduleDir)) {
    fwrite(STDERR, "❌ Mollie module not found at $moduleDir\n");
    exit(1);
}
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// Scan PHP files and collect Mollie API endpoints used by the plugin
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir));
$httpRegex = '#/v\\d+/[\\w/:-]+#i'; // broadened to catch more endpoints like orders-api/...
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

// Write a simple Markdown homepage
$md  = "# Mollie Magento 2 — Auto-Generated API Calls\n\n";
$md .= "Generated automatically from the Mollie Magento 2 plugin source.\n\n";
$md .= "| Endpoint |\n|-----------|\n";
if ($apiList) {
    foreach ($apiList as $p) {
        // Make each endpoint clickable to Mollie reference docs
        $url = 'https://docs.mollie.com/reference' . $p;
        $md .= "| [`{$p}`]({$url}) |\n";
    }
} else {
    $md .= "| _No endpoints found (check workflow paths or regex)_ |\n";
}
file_put_contents($outputDir . '/index.md', $md);
echo "✅ Docs generated in $outputDir\n";

// =========================
// NEW: generate checkout flow
// =========================
require_once __DIR__ . '/checkout-flow.php';
generateCheckoutDocs($moduleDir, $outputDir);

