<?php
/**
 * Compare two browser fingerprints and highlight differences
 */

if ($argc < 3) {
    echo "Usage: php compare-fingerprints.php <fingerprint1.json> <fingerprint2.json>\n";
    echo "Example: php compare-fingerprints.php fingerprint-mac.json fingerprint-server.json\n";
    exit(1);
}

$file1 = $argv[1];
$file2 = $argv[2];

if (!file_exists($file1)) {
    echo "ERROR: File not found: $file1\n";
    exit(1);
}

if (!file_exists($file2)) {
    echo "ERROR: File not found: $file2\n";
    exit(1);
}

$fp1 = json_decode(file_get_contents($file1), true);
$fp2 = json_decode(file_get_contents($file2), true);

if (!$fp1 || !$fp2) {
    echo "ERROR: Could not parse JSON files\n";
    exit(1);
}

echo "========================================\n";
echo "BROWSER FINGERPRINT COMPARISON\n";
echo "========================================\n\n";

echo "File 1: $file1\n";
echo "File 2: $file2\n\n";

// Suspicious difference scores (0-10, 10 = most suspicious)
$suspicionScores = [
    'webgl.unmaskedRenderer' => 10,  // Software vs real GPU
    'navigator.platform' => 9,        // Linux vs Mac
    'screen.width' => 7,              // Unusual resolutions
    'screen.height' => 7,
    'fonts.available' => 8,           // Font differences
    'canvas.hash' => 9,               // Canvas fingerprint
    'navigator.hardwareConcurrency' => 5,
    'navigator.deviceMemory' => 5,
    'timezone.timezone' => 6,
    'battery' => 3,
];

$differences = [];
$totalSuspicion = 0;

// Compare recursively
function compareRecursive($path, $val1, $val2, &$differences, &$totalSuspicion, $suspicionScores) {
    if (is_array($val1) && is_array($val2)) {
        $allKeys = array_unique(array_merge(array_keys($val1), array_keys($val2)));
        foreach ($allKeys as $key) {
            $newPath = $path ? "$path.$key" : $key;
            $v1 = $val1[$key] ?? null;
            $v2 = $val2[$key] ?? null;
            compareRecursive($newPath, $v1, $v2, $differences, $totalSuspicion, $suspicionScores);
        }
    } else {
        // Convert to string for comparison
        $str1 = is_array($val1) ? json_encode($val1) : (string)$val1;
        $str2 = is_array($val2) ? json_encode($val2) : (string)$val2;

        if ($str1 !== $str2) {
            $suspicion = 1; // Default suspicion
            foreach ($suspicionScores as $pattern => $score) {
                if (strpos($path, $pattern) !== false) {
                    $suspicion = $score;
                    break;
                }
            }

            $differences[] = [
                'path' => $path,
                'value1' => $str1,
                'value2' => $str2,
                'suspicion' => $suspicion
            ];

            $totalSuspicion += $suspicion;
        }
    }
}

compareRecursive('', $fp1, $fp2, $differences, $totalSuspicion, $suspicionScores);

// Sort by suspicion score (highest first)
usort($differences, function($a, $b) {
    return $b['suspicion'] - $a['suspicion'];
});

echo "========================================\n";
echo "DIFFERENCES FOUND: " . count($differences) . "\n";
echo "TOTAL SUSPICION SCORE: $totalSuspicion\n";
echo "========================================\n\n";

// Display differences
foreach ($differences as $diff) {
    $suspicionLevel = $diff['suspicion'] >= 8 ? 'CRITICAL' : ($diff['suspicion'] >= 5 ? 'HIGH' : 'MEDIUM');
    $color = $diff['suspicion'] >= 8 ? "\033[1;31m" : ($diff['suspicion'] >= 5 ? "\033[1;33m" : "\033[1;36m");
    $reset = "\033[0m";

    echo "{$color}[$suspicionLevel] {$diff['path']}{$reset}\n";
    echo "  Score: {$diff['suspicion']}/10\n";
    echo "  File 1: " . substr($diff['value1'], 0, 100) . (strlen($diff['value1']) > 100 ? '...' : '') . "\n";
    echo "  File 2: " . substr($diff['value2'], 0, 100) . (strlen($diff['value2']) > 100 ? '...' : '') . "\n";
    echo "\n";
}

// Summary
echo "========================================\n";
echo "SUMMARY\n";
echo "========================================\n\n";

$critical = array_filter($differences, fn($d) => $d['suspicion'] >= 8);
$high = array_filter($differences, fn($d) => $d['suspicion'] >= 5 && $d['suspicion'] < 8);
$medium = array_filter($differences, fn($d) => $d['suspicion'] < 5);

echo "CRITICAL differences: " . count($critical) . " (Very likely to trigger bot detection)\n";
echo "HIGH differences: " . count($high) . " (Likely to trigger bot detection)\n";
echo "MEDIUM differences: " . count($medium) . " (May contribute to bot detection)\n\n";

if (count($critical) > 0) {
    echo "\033[1;31mMost suspicious differences:\033[0m\n";
    foreach (array_slice($critical, 0, 5) as $diff) {
        echo "  - {$diff['path']}\n";
    }
}

echo "\n";
