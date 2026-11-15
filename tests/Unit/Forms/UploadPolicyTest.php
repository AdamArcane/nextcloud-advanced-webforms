<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\UploadPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UploadPolicyTest extends TestCase {
	public function testDefaultsAcceptImagesAndDocuments(): void {
		$field = ['type' => 'file'];
		$this->assertTrue(UploadPolicy::accepts($field, 'photo.JPG'));
		$this->assertTrue(UploadPolicy::accepts($field, 'cv.pdf'));
		$this->assertFalse(UploadPolicy::accepts($field, 'bundle.zip'));
		$this->assertFalse(UploadPolicy::accepts($field, 'noextension'));
		$this->assertSame(1, UploadPolicy::maxFiles($field));
		$this->assertSame(10 * 1024 * 1024, UploadPolicy::maxBytes($field));
		$this->assertSame('files', UploadPolicy::storage($field));
		$this->assertSame('anyone', UploadPolicy::audience($field));
	}

	public function testGroupsAndExtraExtensions(): void {
		$field = ['accept' => ['archives', '.dwg']];
		$this->assertTrue(UploadPolicy::accepts($field, 'a.zip'));
		$this->assertTrue(UploadPolicy::accepts($field, 'plan.DWG'));
		$this->assertFalse(UploadPolicy::accepts($field, 'a.pdf'));
	}

	public function testDangerousTypesAreNeverAccepted(): void {
		$field = ['accept' => ['.php', '.exe', '.svg', '.html']];
		foreach (['a.php', 'a.exe', 'a.svg', 'a.html'] as $name) {
			$this->assertFalse(UploadPolicy::accepts($field, $name), $name);
		}
	}

	#[DataProvider('names')]
	public function testSafeName(string $input, string $expected): void {
		$this->assertSame($expected, UploadPolicy::safeName($input));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function names(): array {
		return [
			'path removed' => ['../../etc/passwd.txt', 'passwd.txt'],
			'windows path' => ['C:\\Users\\me\\cv.pdf', 'cv.pdf'],
			'control characters' => ["a\x00b\nc.png", 'abc.png'],
			'leading dots' => ['.htaccess', 'htaccess'],
			'only dots' => ['...', ''],
			'unicode kept' => ['Résumé 日本.pdf', 'Résumé 日本.pdf'],
		];
	}

	public function testLongNamesKeepTheirExtension(): void {
		$name = UploadPolicy::safeName(str_repeat('a', 300) . '.pdf');
		$this->assertLessThanOrEqual(120, mb_strlen($name));
		$this->assertStringEndsWith('.pdf', $name);
	}

	public function testHiddenDeniedPartsAreCaught(): void {
		$this->assertTrue(UploadPolicy::hasDeniedPart('shell.php.jpg'));
		$this->assertTrue(UploadPolicy::hasDeniedPart('a.exe'));
		$this->assertTrue(UploadPolicy::hasDeniedPart('.htaccess'));
		$this->assertFalse(UploadPolicy::hasDeniedPart('report.final.pdf'));
	}

	#[DataProvider('folders')]
	public function testCleanFolder(string $input, ?string $expected): void {
		$this->assertSame($expected, UploadPolicy::cleanFolder($input));
	}

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function folders(): array {
		return [
			'plain' => ['Work/Applications', 'Work/Applications'],
			'slashes trimmed' => ['/Work/Applications/', 'Work/Applications'],
			'parent refused' => ['Work/../Secret', null],
			'dot refused' => ['./Work', null],
			'empty part' => ['Work//Applications', null],
			'backslash' => ['Work\\Applications', null],
			'control' => ["Work\nApplications", null],
			'empty' => ['', null],
			'too deep' => ['a/b/c/d/e/f/g/h/i', null],
		];
	}

	public function testDefaultFolderFollowsTheFormTitle(): void {
		$this->assertSame('Webforms/Job application', UploadPolicy::folder([], 'Job application'));
		$this->assertSame('Webforms/Form', UploadPolicy::folder([], '///'));
		$this->assertSame('Mine/Here', UploadPolicy::folder(['folder' => 'Mine/Here'], 'T'));
		$this->assertSame('Webforms/T', UploadPolicy::folder(['folder' => '../x'], 'T'));
	}
}
