<?php
/**
 * CLI Test Runner for WPCalibrate Product Catalog Connector
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/unit/DomainLogicTest.php';
require_once __DIR__ . '/integration/FeedGenerationIntegrationTest.php';
require_once __DIR__ . '/api/PinterestApiMockTest.php';

use WPCalibrate\ProductCatalogConnector\Tests\Api\PinterestApiMockTest;
use WPCalibrate\ProductCatalogConnector\Tests\Integration\FeedGenerationIntegrationTest;
use WPCalibrate\ProductCatalogConnector\Tests\Unit\DomainLogicTest;

echo "========================================================\n";
echo " WPCalibrate Product Catalog Connector - Automated Tests\n";
echo " Running on PHP " . PHP_VERSION . "\n";
echo "========================================================\n\n";

$suites = [
	'Unit Tests (Pure Domain & Validation Logic)'  => new DomainLogicTest(),
	'Integration Tests (Feed Generation & Locks)' => new FeedGenerationIntegrationTest(),
	'API & OAuth Tests (Mocked Pinterest Endpoints)' => new PinterestApiMockTest(),
];

$total_passed = 0;
$total_failed = 0;
$start_time = microtime( true );

foreach ( $suites as $suite_name => $suite ) {
	echo "-> Suite: {$suite_name}\n";
	$results = $suite->run();

	foreach ( $results as $test_name => $passed ) {
		if ( $passed ) {
			echo "   [PASS] {$test_name}\n";
			$total_passed++;
		} else {
			echo "   [FAIL] {$test_name}\n";
			$total_failed++;
		}
	}
	echo "\n";
}

$elapsed = round( microtime( true ) - $start_time, 4 );

echo "========================================================\n";
echo " SUMMARY: {$total_passed} Passed, {$total_failed} Failed in {$elapsed}s\n";
echo "========================================================\n";

if ( $total_failed > 0 ) {
	exit( 1 );
}

exit( 0 );
