<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Actions;

use OCA\Webforms\Actions\ActionContext;
use OCA\Webforms\Actions\ActionFailed;
use OCA\Webforms\Actions\ActionRunner;
use OCA\Webforms\Actions\Ports\AccountPort;
use OCA\Webforms\Actions\Ports\FilesFailure;
use OCA\Webforms\Actions\Ports\HttpPort;
use OCA\Webforms\Actions\Ports\MailPort;
use OCA\Webforms\Actions\Ports\ResponseFilesPort;
use OCA\Webforms\Actions\Ports\SecretPort;
use PHPUnit\Framework\TestCase;

final class ActionRunnerFilesTest extends TestCase {
	private FakeResponseFiles $files;

	protected function setUp(): void {
		$this->files = new FakeResponseFiles();
	}

	private function runner(): ActionRunner {
		$mail = new class implements MailPort {
			public function send(string $to, string $subject, string $body, ?string $html = null, ?string $replyTo = null, ?string $fromName = null): void {
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
		return new ActionRunner($mail, $http, $secrets, $accounts, null, $this->files);
	}

	private function context(int $entryId, array $data, string $owner = 'alice'): ActionContext {
		return new ActionContext(
			formId: 1,
			versionId: 3,
			entryId: $entryId,
			logId: 9,
			userId: null,
			submittedAt: 1_700_000_000,
			fields: [
				'f_name' => ['type' => 'text', 'label' => 'Name'],
				'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'support', 'label' => 'Support']]],
			],
			data: $data,
			now: 1_700_000_100,
			ownerId: $owner,
			pages: [['id' => 'p_one', 'rows' => [['id' => 'r_one', 'cells' => [['field' => 'f_kind', 'span' => 6], ['field' => 'f_name', 'span' => 6]]]]]],
		);
	}

	private const ACTION = ['id' => 'act_sheet', 'type' => 'files', 'path' => 'Webforms/Leads.csv'];

	public function testCreatesTheFileThenAppends(): void {
		$runner = $this->runner();
		$runner->run(self::ACTION, $this->context(7, ['f_name' => 'Ada', 'f_kind' => 'support']));
		$runner->run(self::ACTION, $this->context(8, ['f_name' => '=1+1']));
		$this->assertSame(
			"\xEF\xBB\xBFResponse ID,Submitted (UTC),Respondent,Kind,Name\r\n"
			. "7,2023-11-14 22:13:20,,Support,Ada\r\n"
			. "8,2023-11-14 22:13:20,,,'=1+1\r\n",
			$this->files->contents['alice']['Webforms/Leads.csv'],
		);
		$this->assertSame(2, $this->files->lockCount);
	}

	public function testNoPermissionIsNotRetried(): void {
		$this->files->writable = false;
		try {
			$this->runner()->run(self::ACTION, $this->context(7, ['f_name' => 'Ada']));
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertSame('files_not_writable', $e->getMessage());
			$this->assertFalse($e->retryable);
		}
	}

	public function testBusyFileIsRetried(): void {
		$this->files->busy = true;
		try {
			$this->runner()->run(self::ACTION, $this->context(7, ['f_name' => 'Ada']));
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertSame('files_busy', $e->getMessage());
			$this->assertTrue($e->retryable);
		}
	}

	public function testBadPathIsNotRetried(): void {
		try {
			$this->runner()->run(['id' => 'act_sheet', 'type' => 'files', 'path' => '../x.csv'], $this->context(7, []));
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertSame('files_bad_action', $e->getMessage());
			$this->assertFalse($e->retryable);
		}
	}
}

final class FakeResponseFiles implements ResponseFilesPort {
	/** @var array<string, array<string, string>> */
	public array $contents = [];
	public bool $writable = true;
	public bool $busy = false;
	public int $lockCount = 0;

	public function canWrite(string $ownerId, string $path): bool {
		return $this->writable;
	}

	public function locked(string $ownerId, string $path, callable $work): void {
		if ($this->busy) {
			throw new FilesFailure('files_busy', true);
		}
		$this->lockCount++;
		$work();
	}

	public function read(string $ownerId, string $path, int $maxBytes): ?string {
		return $this->contents[$ownerId][$path] ?? null;
	}

	public function write(string $ownerId, string $path, string $content): void {
		$this->contents[$ownerId][$path] = $content;
	}
}
