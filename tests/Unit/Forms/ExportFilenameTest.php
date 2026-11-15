<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Service\EntryExportService;
use PHPUnit\Framework\TestCase;

class ExportFilenameTest extends TestCase {
	public function testBuildsAnAsciiNameWithTheDate(): void {
		$name = EntryExportService::filename('Project inquiry');
		$this->assertMatchesRegularExpression('/^Project-inquiry-\d{4}-\d{2}-\d{2}\.csv$/D', $name);
	}

	public function testNeverLetsATitleBreakOutOfTheHeader(): void {
		foreach (["a\"; filename=\"evil.exe", "line\r\nX-Injected: 1", '../../etc/passwd', 'Zoë 日本語', '   ', '', str_repeat('x', 500)] as $title) {
			$name = EntryExportService::filename($title);
			$this->assertMatchesRegularExpression('/^[A-Za-z0-9-]+\.csv$/D', $name, $title);
			$this->assertLessThanOrEqual(80, strlen($name));
		}
		$this->assertStringStartsWith('responses-', EntryExportService::filename('日本語'));
	}
}
