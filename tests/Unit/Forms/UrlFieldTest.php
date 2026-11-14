<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Tests\Unit\Forms;

use OCA\AdvancedForms\Forms\FieldValueValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlFieldTest extends TestCase {
	/**
	 * @return array<string, array{mixed, string|null, string|null}>
	 */
	public static function cases(): array {
		return [
			'https kept' => ['https://example.com/a?b=1#c', 'https://example.com/a?b=1#c', null],
			'http kept' => ['http://example.com', 'http://example.com', null],
			'no scheme gets https' => ['example.com', 'https://example.com', null],
			'trimmed' => ['  www.example.org/x  ', 'https://www.example.org/x', null],
			'empty is empty' => ['', null, null],
			'javascript refused' => ['javascript:alert(1)', null, 'invalid_url'],
			'data refused' => ['data:text/html,x', null, 'invalid_url'],
			'ftp refused' => ['ftp://example.com', null, 'invalid_url'],
			'no dot in host' => ['localhost', null, 'invalid_url'],
			'spaces refused' => ['https://exa mple.com', null, 'invalid_url'],
			'angle brackets refused' => ['https://example.com/<x>', null, 'invalid_url'],
			'too long' => ['https://example.com/' . str_repeat('a', 2100), null, 'invalid_url'],
			'wrong type' => [['x'], null, 'invalid_type'],
		];
	}

	#[DataProvider('cases')]
	public function testClean(mixed $raw, ?string $value, ?string $error): void {
		$this->assertSame([$value, $error], FieldValueValidator::clean(['type' => 'url', 'label' => 'Site'], $raw));
	}
}
