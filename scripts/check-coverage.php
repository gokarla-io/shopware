#!/usr/bin/env php
<?php

/**
 * Check if code coverage meets minimum thresholds
 */

$coverageFile = __DIR__ . '/../coverage.xml';
$minLineCoverage = 100.0;
$minMethodCoverage = 100.0;

if (!file_exists($coverageFile)) {
    echo "Error: Coverage file not found at {$coverageFile}\n";
    echo "Run 'make coverage' first to generate coverage report.\n";
    exit(1);
}

$xml = simplexml_load_file($coverageFile);
if ($xml === false) {
    echo "Error: Failed to parse coverage XML file\n";
    exit(1);
}

// Get metrics from the project level
$metrics = $xml->project->metrics;
if (!$metrics) {
    echo "Error: Could not find metrics in coverage report\n";
    exit(1);
}

$totalStatements = (int)$metrics['statements'];
$coveredStatements = (int)$metrics['coveredstatements'];
$totalMethods = (int)$metrics['methods'];
$coveredMethods = (int)$metrics['coveredmethods'];

$lineCoverage = $totalStatements > 0 ? ($coveredStatements / $totalStatements) * 100 : 0;
$methodCoverage = $totalMethods > 0 ? ($coveredMethods / $totalMethods) * 100 : 0;

echo "\n";
echo "Code Coverage Report:\n";
echo "=====================\n";
echo sprintf("Lines:   %.2f%% (%d/%d)\n", $lineCoverage, $coveredStatements, $totalStatements);
echo sprintf("Methods: %.2f%% (%d/%d)\n", $methodCoverage, $coveredMethods, $totalMethods);
echo "\n";
echo sprintf("Required minimum: %.2f%%\n", $minLineCoverage);
echo "\n";

$failed = false;

if ($lineCoverage < $minLineCoverage) {
    echo sprintf("❌ Line coverage %.2f%% is below minimum threshold of %.2f%%\n", $lineCoverage, $minLineCoverage);
    $failed = true;
}

if ($methodCoverage < $minMethodCoverage) {
    echo sprintf("❌ Method coverage %.2f%% is below minimum threshold of %.2f%%\n", $methodCoverage, $minMethodCoverage);
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "✅ All coverage requirements met!\n";
exit(0);
