#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Checkout Flow generator for Mollie Magento 2
 *
 * Produces docs/checkout.md with:
 * - Magento events (from etc/events.xml inside the Mollie module)
 * - Linked Observer classes
 * - Detected Mollie API usage (endpoints and SDK calls)
 * - A Mermaid flow diagram: Magento Event ➜ Observer ➜ Mollie API
 *
 * Assumes the Mollie module is cloned to: vendor/mollie/module-payment
 * (exactly like your existing generate-docs.php script expects)
 */

function generateCheckoutDocs(string $moduleDir, string $outputDir): void
{
    if (!is_dir($moduleDir)) {
        fwrite(STDERR, "❌ Mollie module not found at $moduleDir\n");
        return;
    }
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    // 1) Find every etc/events.xml in the module
    $eventsXmlFiles = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir));
    foreach ($rii as $file) {
        if ($file->isFile() && strtolower($file->getFilename()) === 'events.xml' && strpos($file->getPathname(), DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR) !== false) {
            $eventsXmlFiles[] = $file->getPathname();
        }
    }

    // 2) Parse events and observers
    $links = []; // each: ['event'=>..., 'observer'=>..., 'class'=>..., 'file'=>...]
    foreach ($eventsXmlFiles as $xmlPath) {
        $xml = @simplexml_load_file($xmlPath);
        if (!$xml) { continue; }

        // Support both <config><event> and <events><event>
        $eventNodes = [];
        if (isset($xml->event)) $eventNodes = $xml->event;
        if (isset($xml->events->event)) $eventNodes = $xml->events->event;

        foreach ($eventNodes as $eventNode) {
            $eventName = (string)($eventNode['name'] ?? '');
            foreach ($eventNode->observer as $observer) {
                $instance = (string)($observer['instance'] ?? '');
                if ($instance === '') continue;

                // Resolve class file path (PSR-4 style)
                $classFile = $moduleDir . '/' . str_replace('\\', '/', $instance) . '.php';
                $links[] = [
                    'event'    => $eventName,
                    'observer' => (string)($observer['name'] ?? basename(str_replace('\\', '/', $instance))),
                    'class'    => $instance,
                    'file'     => file_exists($classFile) ? $classFile : null,
                ];
            }
        }
    }

    // 3) Filter to likely checkout-related events (keep all, but flag likely)
    $checkoutHints = [
        'checkout', 'place_order', 'submit_all', 'sales_order', 'payment', 'quote', 'cart'
    ];

    // 4) Analyze observers for Mollie API usage
    $httpRegex   = '#/v\\d+/[\\w/:-]+#i';
    $sdkRegex    = '#->\\s*(payments|orders|refunds)\\s*->\\s*(create|get|update|cancel|list|capture|refund)\\s*\\(#i';
    $byObserver  = []; // key: class => ['events'=>[], 'sdk'=>[], 'endpoints'=>[]]

    foreach ($links as $row) {
        $cls  = $row['class'];
        $file = $row['file'];
        if (!isset($byObserver[$cls])) {
            $byObserver[$cls] = [
                'events'    => [],
                'sdk'       => [],
                'endpoints' => [],
                'file'      => $file,
            ];
        }
        if ($row['event']) {
            $byObserver[$cls]['events'][$row['event']] = true;
        }
        if ($file && is_file($file)) {
            $code = @file_get_contents($file) ?: '';
            // Endpoints seen in code (strings like /v2/orders/…)
            if (preg_match_all($httpRegex, $code, $m1)) {
                foreach ($m1[0] as $ep) { $byObserver[$cls]['endpoints'][$ep] = true; }
            }
            // SDK chained calls like ->orders->create( … )
            if (preg_match_all($sdkRegex, $code, $m2, PREG_SET_ORDER)) {
                foreach ($m2 as $match) {
                    $byObserver[$cls]['sdk'][] = strtolower($match[1]).'::'.strtolower($match[2]);
                }
            }
        }
    }

    // 5) Build Mermaid flow: Event -> Observer -> Endpoint
    $diagram = "```mermaid\nflowchart TD\n";
    $nodeIds = [];
    $makeId = function(string $label) use (&$nodeIds): string {
        $id = preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($label));
        if (isset($nodeIds[$id])) { $nodeIds[$id]++; $id .= '_'.$nodeIds[$id]; }
        else { $nodeIds[$id] = 1; }
        return $id;
    };

    $edges = [];
    foreach ($byObserver as $class => $data) {
        $observerId = $makeId($class);
        $diagram .= "  $observerId([\"$class\"]):::observer\n";
        // For each event that triggers this observer
        foreach (array_keys($data['events']) as $eventName) {
            $eventId = $makeId($eventName);
            $diagram .= "  $eventId([\"Magento event: <br/><code>$eventName</code>\"]):::event\n";
            $edges["$eventId->$observerId"] = true;
        }
        // For each endpoint this observer touches
        $endpoints = array_keys($data['endpoints']);
        foreach ($endpoints as $ep) {
            $epId = $makeId($ep);
            $diagram .= "  $epId([\"Mollie API: <br/><code>$ep</code>\"]):::api\n";
            $edges["$observerId->$epId"] = true;
        }
        // If no endpoint strings were found but SDK calls exist, still show a generic API node
        if (empty($data['endpoints']) && !empty($data['sdk'])) {
            $apiSummary = implode(', ', array_unique($data['sdk']));
            $apiNodeLbl = "Mollie SDK: ".$apiSummary;
            $apiId = $makeId($apiSummary);
            $diagram .= "  $apiId([\"$apiNodeLbl\"]):::api\n";
            $edges["$observerId->$apiId"] = true;
        }
    }
    foreach (array_keys($edges) as $edge) {
        $diagram .= "  $edge\n";
    }
    $diagram .= "classDef event fill:#eef6ff,stroke:#3b82f6,color:#111;\n";
    $diagram .= "classDef observer fill:#fef3c7,stroke:#f59e0b,color:#111;\n";
    $diagram .= "classDef api fill:#ecfdf5,stroke:#10b981,color:#111;\n";
    $diagram .= "```\n";

    // 6) Make a tidy Markdown page
    $md  = "# Magento × Mollie — Checkout Flow\n\n";
    $md .= "This page is generated from the Mollie Magento 2 module source. ";
    $md .= "It maps **Magento events → Mollie observers → Mollie API calls** detected in code.\n\n";

    // Short list of likely checkout events up top
    $md .= "## Likely checkout-related events\n\n";
    $md .= "| Event | Observer(s) |\n|---|---|\n";
    $allEventsToObservers = [];
    foreach ($byObserver as $class => $data) {
        foreach (array_keys($data['events']) as $ev) {
            $allEventsToObservers[$ev][] = $class;
        }
    }
    ksort($allEventsToObservers);
    foreach ($allEventsToObservers as $ev => $classes) {
        $isCheckout = false;
        foreach ($checkoutHints as $hint) {
            if (stripos($ev, $hint) !== false) { $isCheckout = true; break; }
        }
        if ($isCheckout) {
            $md .= "| `$ev` | ".implode('<br/>', array_map('htmlspecialchars', array_unique($classes)))." |\n";
        }
    }

    // Full map
    $md .= "\n## Full event → observer → API map\n\n";
    $md .= $diagram . "\n";

    // Detailed table for every observer
    $md .= "## Observer details\n\n";
    $md .= "| Observer class | Events | Mollie endpoints | SDK calls |\n|---|---|---|---|\n";
    ksort($byObserver);
    foreach ($byObserver as $class => $data) {
        $events = array_keys($data['events']);
        sort($events);
        $eps    = array_keys($data['endpoints']);
        sort($eps);
        $sdk    = array_unique($data['sdk']);
        sort($sdk);

        $md .= "| `$class` | ".
               ($events ? implode('<br/>', array_map(fn($e)=>"`$e`", $events)) : "_—_") . " | " .
               ($eps ? implode('<br/>', array_map(fn($e)=>"`$e`", $eps)) : "_—_") . " | " .
               ($sdk ? implode('<br/>', array_map('htmlspecialchars', $sdk)) : "_—_") . " |\n";
    }

    file_put_contents($outputDir . '/checkout.md', $md);
    echo "✅ Checkout flow generated at {$outputDir}/checkout.md\n";
}

