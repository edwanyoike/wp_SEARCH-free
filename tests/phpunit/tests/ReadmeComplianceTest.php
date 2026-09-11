<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guards invariants about readme.txt/changelog.txt that nothing else in the
 * toolchain enforces — PHPCS and PHPUnit only see PHP, and build.sh's own
 * version-bump sed touches version numbers, not changelog structure.
 *
 * These have drifted for real, more than once: a missing current-version
 * changelog entry (WPR-101), and — the reason this file exists — the
 * "readme.txt holds only the current + previous release" rule stated in
 * changelog.txt's own header being violated for three releases straight
 * (1.11.11 through 1.11.14) before anyone noticed, because trimming
 * readme.txt back down after adding an entry was a manual step nothing
 * required. Each new release's own changelog entry gets added by hand
 * (readme.txt isn't machine-generated), so this can't catch a bad entry's
 * *content* — only that the count and version identity stay correct.
 */
final class ReadmeComplianceTest extends TestCase {

	/** @return string[] Version strings ("1.11.14") from readme.txt's own == Changelog == section, in file order (newest first). */
	private function readmeChangelogVersions(): array {
		$readme = (string) file_get_contents( OTSW_PLUGIN_DIR . 'readme.txt' );
		preg_match_all( '/^= (\d+\.\d+\.\d+) =$/m', $readme, $matches );
		return $matches[1];
	}

	public function test_readme_changelog_holds_at_most_current_and_previous_release(): void {
		$versions = $this->readmeChangelogVersions();

		$this->assertLessThanOrEqual(
			2,
			count( $versions ),
			"readme.txt's == Changelog == section has " . count( $versions ) . ' entries (' . implode( ', ', $versions ) . '); '
				. "changelog.txt's own header promises readme.txt holds only the current and immediately previous release. "
				. 'Move the older entries into changelog.txt, immediately after its header and before its existing first entry.'
		);
	}

	public function test_readme_top_changelog_entry_matches_stable_tag(): void {
		$readme  = (string) file_get_contents( OTSW_PLUGIN_DIR . 'readme.txt' );
		$version = $this->readmeChangelogVersions()[0] ?? null;

		$this->assertNotNull( $version, 'readme.txt has no == Changelog == entries at all.' );

		preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $stable_tag_match );
		$this->assertSame(
			$stable_tag_match[1] ?? null,
			$version,
			"readme.txt's Stable tag must match its own newest changelog entry — a release with no changelog entry describing it looks unmaintained to both users and a WordPress.org reviewer."
		);
	}

	public function test_changelog_txt_continues_immediately_after_readmes_oldest_entry_with_no_gap_or_overlap(): void {
		$readme_versions    = $this->readmeChangelogVersions();
		$changelog          = (string) file_get_contents( OTSW_PLUGIN_DIR . 'changelog.txt' );
		preg_match_all( '/^= (\d+\.\d+\.\d+) =$/m', $changelog, $matches );
		$changelog_versions = $matches[1];

		$this->assertNotEmpty( $changelog_versions, 'changelog.txt has no entries at all.' );
		$this->assertEmpty(
			array_intersect( $readme_versions, $changelog_versions ),
			'A version appears in both readme.txt and changelog.txt — pick one home for each release\'s notes, not both.'
		);
	}

	public function test_readme_stays_comfortably_under_the_wordpress_org_size_guidance(): void {
		// ~10KB is the threshold this repo has previously treated as the
		// practical ceiling for readme.txt (see the 1.11.10-era trim this
		// test's own sibling findings reference). Warn well before that: a
		// readme sitting at 9.5KB from unrelated additions (a new FAQ
		// entry, a longer description) plus the two changelog entries this
		// test allows could tip over with no changelog growth at all.
		$size = (int) filesize( OTSW_PLUGIN_DIR . 'readme.txt' );
		$this->assertLessThan( 9000, $size, "readme.txt is {$size} bytes, approaching the ~10KB practical ceiling. Trim the Changelog section (see the other tests in this file) or shorten other sections." );
	}
}
