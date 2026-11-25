<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Forms\FolderName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FolderNameTest extends TestCase {
	public function testTrimsAndMakesRunsOfSpaceOne(): void {
		self::assertSame('Events 2026', FolderName::resolve("  Events \t\n 2026 ", []));
	}

	public function testTakesTheLongestNameAndCountsCharactersNotBytes(): void {
		$name = str_repeat('é', FolderName::MAX_LENGTH);
		self::assertSame($name, FolderName::resolve($name, []));
	}

	/**
	 * @return iterable<string, array{string, list<string>, string}>
	 */
	public static function refused(): iterable {
		yield 'empty' => ['', [], 'folder_name_invalid'];
		yield 'only spaces' => ["  \n ", [], 'folder_name_invalid'];
		yield 'too long' => [str_repeat('a', FolderName::MAX_LENGTH + 1), [], 'folder_name_invalid'];
		yield 'not UTF-8' => ["\xff", [], 'folder_name_invalid'];
		yield 'taken' => ['Events', ['Events'], 'folder_name_taken'];
		yield 'taken apart from capitals' => [' ÉVÉNEMENTS ', ['Événements'], 'folder_name_taken'];
	}

	/**
	 * @param list<string> $others
	 */
	#[DataProvider('refused')]
	public function testRefuses(string $given, array $others, string $code): void {
		try {
			FolderName::resolve($given, $others);
			self::fail('The name should have been refused.');
		} catch (ValidationException $e) {
			self::assertSame([['code' => $code, 'path' => 'name']], $e->getErrors());
		}
	}
}
