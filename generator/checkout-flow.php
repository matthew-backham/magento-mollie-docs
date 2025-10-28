#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Magento × Mollie — Full Checkout Flow Generator (v3)
 *
 * Builds docs/checkout.md automatically with:
 *  - Magento events → Observers → Injected Services → Mollie API calls
 *  - Clickable GitHub source links
 *  - Coverage summary
 *  - Mermaid flow diagram
 */

function generateCheckoutDocs(string $moduleDir, string $outputDir): void
{
    $repoUrl = "https://github.com/mollie/magento2/blob/main/";

    if (!is_dir($moduleDir)) {
        fwrite(STDERR, "❌ Mollie module not found at $moduleDir\n");
        return;
    }
    if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);

    // -------------------------------------------------------------
    // 1️⃣ Scan for API usage across all PHP files (deep scan)
    // -------------------------------------------------------------
    $apiUsage = [];
    $httpRegex = '#/v\\d+/[\\w/:-]+#i';
    $sdkRegex = '#->\\s*(payments|orders|refunds|shipments|captures)\\s*->\\s*(create|get|update|cancel|list|capture|refund)\\s*\\(#i';
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

        if (preg_match_all($httpRegex, $code, $m)) {
            foreach ($m[0] as $ep) $endpoints[$ep] = true;
        }
        if (preg_match_all($sdkRegex, $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $sdkCalls[] = strtolower($x[1]) . '::' . strtolower($x[2]);
        }

        if ($endpoints || $sdkCalls) {
            $lines = substr_count($code, "\n");
            $apiUsage[$className] = [
                'file' => $path,
                'lines' => $lines,
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

        // Constructor-injected services
        if (preg_match('/function\\s+__construct\\s*\\(([^)]*)\\)/', $code, $ctor)) {
            if (preg_match_all('/([A-Z][A-Za-z0-9_\\\\]+)\\s+\\$([A-Za-z0-9_]+)/', $ctor[1], $deps, PREG_SET_ORDER)) {
                foreach ($deps as $d) {
                    $type = trim($d[1]);
                    $observerMap[$cls]['services'][] = $type;
                    // If service uses Mollie API, link it
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
    // 4️⃣ Coverage summary
    // -------------------------------------------------------------
    $totalEvents = count(array_unique(array_column($links, 'event')));
    $totalObservers = count($observerMap);
    $totalApiCalls = array_reduce($apiUsage, fn($c, $x) => $c + count($x['endpoints']), 0);
    $summary = "✓ **$totalEvents Magento events**\n" .
               "✓ **$totalObservers observers documented**\n" .
               "✓ **" . count($apiUsage) . " classes with Mollie API usage**\n" .
               "✓ **$totalApiCalls unique API endpoints** detected\n";

    // -------------------------------------------------------------
    // 5️⃣ Build Markdown output
    // -------------------------------------------------------------
    $md  = "# Magento × Mollie — Checkout Flow (Deep Analysis)\n\n";
    $md .= "_Generated automatically from the Mollie Magento 2 module source._\n\n";
    $md .= "## Coverage Summary\n\n" . $summary . "\n";
    $md .= "It maps **Magento events → Mollie observers → Services → Mollie API calls** with clickable source links.\n\n";

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

    // Diagram
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

    // Observer details
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

