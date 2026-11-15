<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\JsonShape;
use PHPUnit\Framework\TestCase;

class JsonShapeTest extends TestCase {
	public function testEmptyMapsEncodeAsObjects(): void {
		$json = json_encode(JsonShape::definition(['settings' => [], 'fields' => [], 'rules' => []]));
		$this->assertSame('{"settings":{},"fields":{},"rules":[]}', $json);
	}

	public function testFilledMapsAndListsAreUntouched(): void {
		$in = ['settings' => ['submitLabel' => 'Go'], 'fields' => ['f_a' => ['type' => 'text']], 'rules' => [1]];
		$this->assertSame($in, JsonShape::definition($in));
	}

	public function testEmptyEntryDataEncodesAsObject(): void {
		$this->assertSame('{}', json_encode(JsonShape::map([])));
		$this->assertSame('{"f_a":"x"}', json_encode(JsonShape::map(['f_a' => 'x'])));
	}
}
