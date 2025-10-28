#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Magento × Mollie — Checkout Flow Deep Generator (v3.3)
 * ------------------------------------------------------
 * Generates docs/checkout.md with:
 *  - Magento events → Observers → Injected Services → Mollie API calls
 *  - Clickable GitHub source links
 *  - Broader Mollie SDK + API call detection
 *  - Coverage summary + validation
 */

function generateCheckoutDocs(string $moduleDir, string $outputDir): void
{
    // ✅ Correct Mollie repo path (no more 404s)
    $repoUrl = "https://github.com/mollie/magento2/tree/master/src/Magento/Payment/";

    if (!is_dir($moduleDir)) {
        fwrite(STDERR, "❌ Mollie module not found at $moduleDir\n");
        exit(1);
    }
    if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);

    // -------------------------------------------------------------
    // 1️⃣ Scan for API usage across all PHP files
    // -------------------------------------------------------------
    $apiUsage = [];

    // Expanded regex patterns — real Mollie SDK and API calls
    $httpRegex = '#https?://api\.mollie\.com/v\\d+/[\\w/:-]+#i';
    $sdkRegex  = '#performHttpCall(ToFullUrl)?\\s*\\([^,]+,\\s*["\'](https?://api\.mollie\.com/)?v\\d+/[\\w/:-]+["\']#i';

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir));

    foreach ($rii as $file) {
        if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') continue;
        $path = $file->getPathname();
        $code = @file_get_contents($path);
        if (!$code) continue;

        if (!preg_match('/namespace\\s+([^;]+)/', $code, $ns)) continue;
        $namespace = trim($ns[1]);
        if (!preg_match('/class\\s+(\\w+)/', $code, $cls)) continue;
        $className = $namespace . '\\' . trim($cls[1]);

        $endpoints = [];
        $sdkCalls  = [];

        // Detect Mollie API endpoints and performHttpCall wrappers
        if (preg_match_all($httpRegex, $code, $m)) {
            foreach ($m[0] as $ep) $endpoints[$ep] = true;
        }
        if (preg_match_all($sdkRegex, $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $sdkCalls[] = 'performHttpCall: ' . $x[2] . 'v2/...';
        }

        // Optional: mark wrappers even if endpoint is dynamic
        if (strpos($code, 'mollieApiClient') !== false || strpos($code, 'performHttpCall') !== false) {
            $sdkCalls[] = 'custom Mollie SDK wrapper call';
        }

        if ($endpoints || $sdkCalls) {
            $apiUsage[$className] = [
                'file' => $path,
                'endpoints' => array_keys($endpoints),
                'sdk' => array_unique($sdkCalls),
            ];
        }
    }

    echo "✅ Indexed " . count($apiUsage) . " classes with Mollie API usage\n";

    // -------------------------------------------------------------
    // 2️⃣ Parse events.xml → events & observers
    // -------------------------------------------------------------
    $links = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir));
    foreach ($rii as $file) {
        if (!$file->isFile() || strtolower($file->getFilename()) !== 'events.xml') continue;
        $xml = @simplexml_load_file($file->getPathname());
        if (!$xml) continue;

        $events = $xml->event ?: ($xml->events->event ?? []);
        foreach ($events as $ev) {
            $name = (string)($ev['name'] ?? '');
            foreach ($ev->observer as $obs) {
                $instance = (string)($obs['instance'] ?? '');
                if (!$instance) continue;
                $links[] = [
                    'event' => $name,
                    'class' => $instance,
                    'file'  => $moduleDir . '/' . str_replace('\\', '/', $instance) . '.php'
                ];
            }
        }
    }

    echo "✅ Found " . count($links) . " Magento event→observer links\n";

    // -------------------------------------------------------------
    // 3️⃣ Map observers → services → API calls
    // -------------------------------------------------------------
    $observerMap = [];
    foreach ($links as $row) {
        $cls = $row['class'];
        $path = $row['file'];
        $observerMap[$cls] ??= [
            'events' => [],
            'services' => [],
            'api' => [],
            'sdk' => [],
        ];
        $observerMap[$cls]['events'][] = $row['event'];

        if (!file_exists($path)) continue;
        $code = @file_get_contents($path);
        if (!$code) continue;

        // Detect constructor-injected dependencies
        if (preg_match('/function\\s+__construct\\s*\\(([^)]*)\\)/', $code, $ctor)) {
            if (preg_match_all('/([A-Z][A-Za-z0-9_\\\\]+)\\s+\\$([A-Za-z0-9_]+)/', $ctor[1], $deps, PREG_SET_ORDER)) {
                foreach ($deps as $d) {
                    $type = trim($d[1]);
                    $observerMap[$cls]['services'][] = $type;
                    if (isset($apiUsage[$type])) {
                        $observerMap[$cls]['api'] = array_unique(array_merge(
                            $observerMap[$cls]['api'],
                            $apiUsage[$type]['endpoints']
                        ));
                        $observerMap[$cls]['sdk'] = array_unique(array_merge(
                            $observerMap[$cls]['sdk'],
                            $apiUsage[$type]['sdk']
                        ));
                    }
                }
            }
        }
    }

    // -------------------------------------------------------------
    // 4️⃣ Coverage summary + validation
    // -------------------------------------------------------------
    $totalEvents = count(array_unique(array_column($links, 'event')));
    $totalObservers = count($observerMap);
    $totalApiClasses = count($apiUsage);
    $totalEndpoints = array_reduce($apiUsage, fn($c, $x) => $c + count($x['endpoints']), 0);

    echo "📊 Coverage: $totalEvents events, $totalObservers observers, $totalApiClasses API classes, $totalEndpoints endpoints\n";

    if ($totalApiClasses === 0) {
        fwrite(STDERR, "❌ No API classes detected — check regex patterns.\n");
        exit(1);
    }

    // -------------------------------------------------------------
    // 5️⃣ Build Markdown
    // -------------------------------------------------------------
    $md  = "# Magento × Mollie — Checkout Flow (Deep Analysis)\n\n";
    $md .= "_Generated automatically from the Mollie Magento 2 module source._\n\n";
    $md .= "## Coverage Summary\n\n";
    $md .= "✓ **$totalEvents Magento events**\n";
    $md .= "✓ **$totalObservers observers documented**\n";
    $md .= "✓ **$totalApiClasses classes using Mollie API**\n";
    $md .= "✓ **$totalEndpoints unique API endpoints detected**\n\n";
    $md .= "It maps **Magento events → Observers → Services → Mollie API calls**.\n\n";

    // Table of events
    $md .= "## Checkout-related events\n\n";
    $md .= "| Event | Observer(s) |\n|---|---|\n";
    $eventToObserver = [];
    foreach ($observerMap as $cls => $info) {
        foreach ($info['events'] as $ev) $eventToObserver[$ev][] = $cls;
    }
    ksort($eventToObserver);
    foreach ($eventToObserver as $ev => $classes) {
        $md .= "| `$ev` | " . implode('<br/>', array_map('htmlspecialchars', $classes)) . " |\n";
    }

    // Mermaid diagram
    $md .= "\n## Full event → observer → API map\n\n";
    $md .= "```mermaid\nflowchart TD\n";
    $id = fn($s) => preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($s));
    foreach ($observerMap as $cls => $info) {
        $oid = $id($cls);
        $md .= "  $oid([\"$cls\"]):::observer\n";
        foreach ($info['events'] as $ev) {
            $eid = $id($ev);
            $md .= "  $eid([\"Magento event:<br/><code>$ev</code>\"]):::event\n";
            $md .= "  $eid --> $oid\n";
        }
        foreach ($info['api'] as $ep) {
            $aid = $id($ep);
            $md .= "  $aid([\"Mollie API:<br/><code>$ep</code>\"]):::api\n";
            $md .= "  $oid --> $aid\n";
        }
    }
    $md .= "classDef event fill:#eef6ff,stroke:#3b82f6,color:#111;\n";
    $md .= "classDef observer fill:#fef3c7,stroke:#f59e0b,color:#111;\n";
    $md .= "classDef api fill:#ecfdf5,stroke:#10b981,color:#111;\n";
    $md .= "```\n\n";

    // Observer details table
    $md .= "## Observer details\n\n";
    $md .= "| Observer class | Events | Services | Mollie endpoints | SDK calls |\n|---|---|---|---|---|\n";
    ksort($observerMap);
    foreach ($observerMap as $cls => $info) {
        $filePath = $moduleDir . '/' . str_replace('\\', '/', $cls) . '.php';
        $fileUrl = $repoUrl . str_replace($moduleDir . '/', '', $filePath);
        $md .= "| [`$cls`]($fileUrl) | "
             . implode('<br/>', $info['events'] ?: ['_—_']) . " | "
             . implode('<br/>', $info['services'] ?: ['_—_']) . " | "
             . implode('<br/>', $info['api'] ?: ['_—_']) . " | "
             . implode('<br/>', $info['sdk'] ?: ['_—_'])
             . " |\n";
    }

    file_put_contents("$outputDir/checkout.md", $md);
    echo "✅ Checkout flow written to $outputDir/checkout.md\n";
}

