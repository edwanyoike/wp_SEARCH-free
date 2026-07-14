<?php
declare(strict_types=1);

/**
 * Coverage ratchet: fails when line coverage in a clover.xml report drops
 * below the threshold. Usage:
 *
 *   php tests/phpunit/check-coverage.php <clover.xml> <min-percent>
 *
 * Raise the threshold as coverage grows — never lower it.
 */

$clover    = $argv[1] ?? '';
$threshold = (float) ( $argv[2] ?? 85 );

if ( ! is_file( $clover ) ) {
	fwrite( STDERR, "check-coverage: report not found: {$clover}\n" );
	exit( 1 );
}

$xml     = new SimpleXMLElement( (string) file_get_contents( $clover ) );
$metrics = $xml->project->metrics;
$total   = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if ( 0 === $total ) {
	fwrite( STDERR, "check-coverage: no statements found in report\n" );
	exit( 1 );
}

$percent = $covered / $total * 100;
printf( "Line coverage: %.2f%% (%d/%d statements), threshold %.1f%%\n", $percent, $covered, $total, $threshold );

if ( $percent + 0.005 < $threshold ) {
	fwrite( STDERR, sprintf( "check-coverage: FAILED — %.2f%% is below the %.1f%% ratchet\n", $percent, $threshold ) );
	exit( 1 );
}
echo "check-coverage: OK\n";
