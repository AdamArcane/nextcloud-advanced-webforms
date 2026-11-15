<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Actions;

use OCA\Webforms\Actions\ActionContext;
use OCA\Webforms\Actions\ActionFailed;
use OCA\Webforms\Actions\ActionPlanner;
use OCA\Webforms\Actions\ActionRunner;
use OCA\Webforms\Actions\ActionSkipped;
use OCA\Webforms\Actions\Ports\AccountPort;
use OCA\Webforms\Actions\Ports\HttpPort;
use OCA\Webforms\Actions\Ports\MailPort;
use OCA\Webforms\Actions\Ports\SecretPort;
use OCA\Webforms\Actions\Ports\TablesFailure;
use OCA\Webforms\Actions\Ports\TablesPort;
use OCA\Webforms\Rules\RuleEngine;
use PHPUnit\Framework\TestCase;

final class ActionRunnerTablesTest extends TestCase {
	private const COLUMNS = [
		['id' => 11, 'title' => 'Name', 'type' => 'text', 'subtype' => 'line', 'mandatory' => true, 'options' => []],
		['id' => 12, 'title' => 'Kind', 'type' => 'selection', 'subtype' => '', 'mandatory' => false, 'options' => [['id' => 5, 'label' => 'Support'], ['id' => 6, 'label' => 'Sales']]],
		['id' => 13, 'title' => 'Owner', 'type' => 'usergroup', 'subtype' => '', 'mandatory' => false, 'options' => []],
	];

	private FakeTables $tables;

	protected function setUp(): void {
		$this->tables = new FakeTables(self::COLUMNS);
	}

	private function runner(?TablesPort $tables): ActionRunner {
		$mail = new class implements MailPort {
			public function send(string $to, string $subject, string $body): void {
			}
		};
		$http = new class implements HttpPort {
			public function postJson(string $url, string $body, array $headers, int $timeoutSeconds): int {
				return 200;
			}
		};
		$secrets = new class implements SecretPort {
			public function get(int $formId, string $ref): ?string {
				return null;
			}
		};
		$accounts = new class implements AccountPort {
			public function emailOf(string $userId): ?string {
				return null;
			}
		};
		return new ActionRunner($mail, $http, $secrets, $accounts, $tables);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function context(array $data): ActionContext {
		return new ActionContext(
			formId: 12, versionId: 3, entryId: 457, logId: 1, userId: null, submittedAt: 1794700000,
			fields: [
				'f_name' => ['type' => 'text', 'label' => 'Name'],
				'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'opt_s', 'label' => 'Support'], ['value' => 'opt_x', 'label' => 'Other']]],
			],
			data: $data, now: 1794700100, ownerId: 'ada',
		);
	}

	/** @return array<string, mixed> */
	private function action(): array {
		return ['id' => 'act_tables', 'type' => 'tables', 'tableId' => 9, 'map' => ['11' => 'f_name', '12' => 'f_kind']];
	}

	public function testAddsARowAsTheOwner(): void {
		$this->runner($this->tables)->run($this->action(), $this->context(['f_name' => 'Ada', 'f_kind' => 'opt_s']));
		$this->assertSame([[9, [11 => 'Ada', 12 => 5], 'ada']], $this->tables->rows);
	}

	public function testLeavesOutWhatHasNoValueOrNoMatchingOption(): void {
		$this->runner($this->tables)->run($this->action(), $this->context(['f_name' => 'Ada', 'f_kind' => 'opt_x']));
		$this->assertSame([[9, [11 => 'Ada'], 'ada']], $this->tables->rows);
	}

	public function testNothingToWriteIsSkippedNotFailed(): void {
		$this->expectException(ActionSkipped::class);
		$this->runner($this->tables)->run($this->action(), $this->context([]));
	}

	public function testAColumnThatNoLongerExistsIsIgnored(): void {
		$action = $this->action();
		$action['map']['99'] = 'f_name';
		$this->runner($this->tables)->run($action, $this->context(['f_name' => 'Ada']));
		$this->assertSame([11 => 'Ada'], $this->tables->rows[0][1]);
	}

	public function testMetaSources(): void {
		$action = ['id' => 'act_tables', 'type' => 'tables', 'tableId' => 9, 'map' => ['11' => '@respondent']];
		$context = new ActionContext(12, 3, 457, 1, 'bob', 1794700000, [], [], 1794700100, 0, 'ada');
		$this->runner($this->tables)->run($action, $context);
		$this->assertSame([11 => 'bob'], $this->tables->rows[0][1]);
	}

	public function testMissingTablesIsRetryable(): void {
		foreach ([null, new FakeTables(self::COLUMNS, available: false)] as $port) {
			try {
				$this->runner($port)->run($this->action(), $this->context(['f_name' => 'Ada']));
				$this->fail('expected a failure');
			} catch (ActionFailed $e) {
				$this->assertSame('tables_unavailable', $e->getMessage());
				$this->assertTrue($e->retryable);
			}
		}
	}

	public function testTablesRefusalsKeepTheirRetryability(): void {
		$this->tables->failure = new TablesFailure('tables_forbidden');
		try {
			$this->runner($this->tables)->run($this->action(), $this->context(['f_name' => 'Ada']));
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertSame('tables_forbidden', $e->getMessage());
			$this->assertFalse($e->retryable);
		}

		$this->tables->failure = new TablesFailure('tables_error', true, 'database is down');
		try {
			$this->runner($this->tables)->run($this->action(), $this->context(['f_name' => 'Ada']));
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertStringStartsWith('tables_error', $e->getMessage());
			$this->assertTrue($e->retryable);
		}
	}

	public function testABadActionDoesNotRetry(): void {
		try {
			$this->runner($this->tables)->run(['id' => 'a', 'type' => 'tables', 'tableId' => 'nine', 'map' => []], $this->context([]));
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertSame('tables_bad_action', $e->getMessage());
			$this->assertFalse($e->retryable);
		}
	}

	public function testThePlannerQueuesIt(): void {
		$planner = new ActionPlanner(new RuleEngine());
		$plan = $planner->plan(['fields' => [], 'actions' => [$this->action()]], []);
		$this->assertSame(['act_tables'], $plan['queue']);
	}
}

/** An in-memory Tables. */
final class FakeTables implements TablesPort {
	/** @var list<array{int, array<int, mixed>, string}> */
	public array $rows = [];

	public ?TablesFailure $failure = null;

	/**
	 * @param list<array<string, mixed>> $columns
	 */
	public function __construct(private readonly array $columns, private readonly bool $available = true) {
	}

	public function isAvailable(): bool {
		return $this->available;
	}

	public function writableTables(string $userId): array {
		return [['id' => 9, 'title' => 'Leads', 'emoji' => '']];
	}

	public function columns(int $tableId, string $userId): array {
		return $this->failure === null ? $this->columns : throw $this->failure;
	}

	public function addRow(int $tableId, array $values, string $userId): int {
		$this->rows[] = [$tableId, $values, $userId];
		return count($this->rows);
	}

	public function createTable(string $title, array $columns, string $userId): array {
		throw new TablesFailure('tables_create_unsupported');
	}
}
