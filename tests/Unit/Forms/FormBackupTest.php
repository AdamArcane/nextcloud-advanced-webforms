<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\FormBackup;
use OCA\Webforms\Forms\FormPackage;
use PHPUnit\Framework\TestCase;

class FormBackupTest extends TestCase {
	private const NOW = 1763100000;

	/**
	 * @return array<string, mixed>
	 */
	private static function definition(string $title): array {
		return [
			'schemaVersion' => 1,
			'title' => $title,
			'settings' => [],
			'fields' => ['f_name' => ['type' => 'text', 'label' => 'Name']],
			'pages' => [['id' => 'p_1', 'title' => null, 'rows' => [['id' => 'r_1', 'cells' => [['id' => 'c_1', 'span' => 12, 'field' => 'f_name']]]]]],
			'rules' => [],
			'actions' => [['id' => 'act_1', 'type' => 'webhook', 'url' => 'https://example.com/hook', 'secretRef' => 'whsec_1']],
		];
	}

	public function testOneDraftPerFormAndAPublishedFileOnlyWhenPublished(): void {
		$files = FormBackup::files([
			['id' => 4, 'title' => 'Project inquiry', 'draft' => self::definition('Project inquiry'), 'published' => self::definition('Project inquiry')],
			['id' => 9, 'title' => 'Project inquiry', 'draft' => self::definition('Project inquiry'), 'published' => null],
		], self::NOW);

		$this->assertSame([
			'webforms-backup-2025-11-14/4-Project-inquiry.draft.webforms.json',
			'webforms-backup-2025-11-14/4-Project-inquiry.published.webforms.json',
			'webforms-backup-2025-11-14/9-Project-inquiry.draft.webforms.json',
			'webforms-backup-2025-11-14/README.txt',
		], array_keys($files));
		$this->assertStringContainsString('2 forms', $files['webforms-backup-2025-11-14/README.txt']);
	}

	public function testEachFileIsAnImportablePackageWithoutSecrets(): void {
		$files = FormBackup::files([['id' => 1, 'title' => 'A', 'draft' => self::definition('A'), 'published' => self::definition('A')]], self::NOW);

		$draft = json_decode($files['webforms-backup-2025-11-14/1-A.draft.webforms.json'], true, 512, JSON_THROW_ON_ERROR);
		$published = json_decode($files['webforms-backup-2025-11-14/1-A.published.webforms.json'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame('draft', $draft['source']);
		$this->assertSame('published', $published['source']);
		foreach ([$draft, $published] as $package) {
			$this->assertSame('A', FormPackage::parse($package)['title']);
			$this->assertArrayNotHasKey('secretRef', $package['definition']['actions'][0]);
		}
		$this->assertStringNotContainsString('whsec_1', implode('', $files));
	}

	public function testTitlesNeverMakeAnUnsafePath(): void {
		foreach (['../../etc/passwd', "a\r\nb", 'Zoë 日本語', '   ', '', str_repeat('x', 500)] as $title) {
			$files = FormBackup::files([['id' => 3, 'title' => $title, 'draft' => self::definition('T'), 'published' => null]], self::NOW);
			foreach (array_keys($files) as $name) {
				$this->assertMatchesRegularExpression('#^webforms-backup-2025-11-14/[A-Za-z0-9.-]+$#D', $name, $title);
				$this->assertLessThanOrEqual(120, strlen($name));
			}
		}
	}

	public function testNoFormsStillGivesAReadme(): void {
		$files = FormBackup::files([], self::NOW);
		$this->assertSame(['webforms-backup-2025-11-14/README.txt'], array_keys($files));
		$this->assertStringContainsString('0 forms', $files['webforms-backup-2025-11-14/README.txt']);
	}

	public function testTheZipHoldsTheFilesAndHasASafeName(): void {
		$this->assertSame('webforms-backup-2025-11-14.zip', FormBackup::filename(self::NOW));
		$files = FormBackup::files([['id' => 1, 'title' => 'A', 'draft' => self::definition('A'), 'published' => null]], self::NOW);
		$bytes = FormBackup::zip($files);

		$path = tempnam(sys_get_temp_dir(), 'wfbt');
		file_put_contents($path, $bytes);
		try {
			$zip = new \ZipArchive();
			$this->assertTrue($zip->open($path));
			$this->assertSame(count($files), $zip->count());
			foreach ($files as $name => $content) {
				$this->assertSame($content, $zip->getFromName($name));
			}
			$zip->close();
		} finally {
			unlink($path);
		}
	}
}
