<?php

// Autoloader for KujangPulse standalone runner
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../app/';

    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }

    $testPrefix = 'Tests\\';
    $testDir = __DIR__ . '/../tests/';
    if (strncmp($testPrefix, $class, strlen($testPrefix)) === 0) {
        $relativeClass = substr($class, strlen($testPrefix));
        $file = $testDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

use Tests\Unit\KujangPulseTest;

echo "=================================================================\n";
echo "  KUJANGPULSE UNIT TEST SUITE (PHP 8.2 / Laravel 11)\n";
echo "=================================================================\n\n";

$suite = new KujangPulseTest();
$results = $suite->runAll();

$passed = 0;
$failed = 0;

foreach ($results as $method => $data) {
    if ($data['status'] === 'PASS') {
        echo " [✓ PASS] {$method}\n";
        $passed++;
    } else {
        echo " [✗ FAIL] {$method}: {$data['error']} (Line {$data['line']})\n";
        $failed++;
    }
}

echo "\n-----------------------------------------------------------------\n";
echo " SUMMARY: Total: " . count($results) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "-----------------------------------------------------------------\n";

if ($failed > 0) {
    exit(1);
}

echo "\nAll 16 unit tests passed successfully!\n";
exit(0);
