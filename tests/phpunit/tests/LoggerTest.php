<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Logger;

final class LoggerTest extends TestCase {

	protected function setUp(): void {
		wcs_tests_reset();
	}

	public function test_logs_through_wc_logger_with_version_prefix(): void {
		Logger::log( 'something happened', 'warning' );

		$this->assertCount( 1, $GLOBALS['wcs_test_logs'] );
		$entry = $GLOBALS['wcs_test_logs'][0];
		$this->assertSame( 'warning', $entry['level'] );
		$this->assertStringContainsString( WCS_VERSION, $entry['message'] );
		$this->assertStringContainsString( 'something happened', $entry['message'] );
	}

	public function test_defaults_to_info_level(): void {
		Logger::log( 'plain message' );
		$this->assertSame( 'info', $GLOBALS['wcs_test_logs'][0]['level'] );
	}
}
