<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\ResponseStatuses;
use OCA\Webforms\Security\Captcha\ConfigStore;
use OCA\Webforms\Settings\StatusSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseStatusesTest extends TestCase {
	private function store(): ConfigStore {
		return new class implements ConfigStore {
			/** @var array<string, string> */
			public array $values = [];

			public function get(string $key, bool $sensitive = false): string {
				return $this->values[$key] ?? '';
			}

			public function set(string $key, string $value, bool $sensitive = false): void {
				$this->values[$key] = $value;
			}
		};
	}

	public function testAServerStartsWithThreeOrdinaryStatuses(): void {
		$this->assertSame(['in_progress', 'done', 'spam'], array_column(ResponseStatuses::parse(''), 'id'));
		$settings = new StatusSettings($this->store());
		$this->assertTrue($settings->has('done'));
		$this->assertFalse($settings->has('none'));
		$this->assertSame('In progress', $settings->labels()['in_progress']);
	}

	public function testAnEmptyListStaysEmptyAndIsNotTheDefaults(): void {
		$settings = new StatusSettings($this->store());
		$settings->save([]);
		$this->assertSame([], $settings->all());
		$this->assertFalse($settings->has('done'));
	}

	public function testSaveAndReadBackKeepsOrderAndNames(): void {
		$settings = new StatusSettings($this->store());
		$list = ResponseStatuses::validate([
			['id' => 'called_back', 'label' => '  Called back ', 'color' => 'teal', 'extra' => 'dropped'],
			['id' => 'done', 'label' => 'Erledigt', 'color' => 'green'],
		]);
		$settings->save($list);
		$this->assertSame([
			['id' => 'called_back', 'label' => 'Called back', 'color' => 'teal'],
			['id' => 'done', 'label' => 'Erledigt', 'color' => 'green'],
		], $settings->all());
	}

	public function testADamagedListReadsAsEmptyAndADamagedEntryIsLeftOut(): void {
		$this->assertSame([], ResponseStatuses::parse('{nope'));
		$this->assertSame([], ResponseStatuses::parse('"text"'));
		$stored = json_encode([
			['id' => 'done', 'label' => 'Done', 'color' => 'green'],
			['id' => 'Bad Id', 'label' => 'x', 'color' => 'green'],
			['id' => 'done', 'label' => 'Twice', 'color' => 'red'],
			'not a status',
			['id' => 'later', 'label' => 'Later', 'color' => 'mauve'],
		]);
		$this->assertSame([['id' => 'done', 'label' => 'Done', 'color' => 'green']], ResponseStatuses::parse((string)$stored));
	}

	/** @return array<string, array{array<mixed>, string}> */
	public static function bad(): array {
		$ok = ['id' => 'done', 'label' => 'Done', 'color' => 'green'];
		return [
			'not an object' => [['done'], 'status_invalid'],
			'id with a capital' => [[['id' => 'Done'] + $ok], 'status_id_invalid'],
			'id too short' => [[['id' => 'ab'] + $ok], 'status_id_invalid'],
			'id is the word for no status' => [[['id' => 'none'] + $ok], 'status_id_invalid'],
			'same id twice' => [[$ok, $ok], 'status_id_duplicate'],
			'empty label' => [[['label' => '   '] + $ok], 'status_label_invalid'],
			'label too long' => [[['label' => str_repeat('a', 41)] + $ok], 'status_label_invalid'],
			'label with a line break' => [[['label' => "Do\nne"] + $ok], 'status_label_invalid'],
			'unknown colour' => [[['color' => '#ff0000'] + $ok], 'status_color_invalid'],
			'too many' => [array_map(static fn (int $n): array => ['id' => 'status_' . $n, 'label' => 'S' . $n, 'color' => 'gray'], range(1, 21)), 'too_many_statuses'],
		];
	}

	/** @param array<mixed> $input */
	#[DataProvider('bad')]
	public function testRefusesABadList(array $input, string $reason): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($reason);
		ResponseStatuses::validate($input);
	}

	public function testTwentyStatusesAreAllowed(): void {
		$list = array_map(static fn (int $n): array => ['id' => 'status_' . $n, 'label' => 'S' . $n, 'color' => 'gray'], range(1, 20));
		$this->assertCount(20, ResponseStatuses::validate($list));
	}
}
