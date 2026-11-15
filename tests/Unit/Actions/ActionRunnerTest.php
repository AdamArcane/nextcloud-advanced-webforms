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
use OCA\Webforms\Actions\ActionSkipped;
use OCA\Webforms\Actions\Ports\AccountPort;
use OCA\Webforms\Actions\Ports\HttpPort;
use OCA\Webforms\Actions\Ports\MailPort;
use OCA\Webforms\Actions\Ports\SecretPort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ActionRunnerTest extends TestCase {
	/** @var list<array{string, string, string}> */
	private array $mails = [];
	/** @var list<array{string, string, array<string, string>, int}> */
	private array $posts = [];
	private int $httpStatus = 200;
	private ?\Throwable $httpError = null;
	private ?\Throwable $mailError = null;
	private ?string $secret = 'topsecret';
	/** @var array<string, string> account id => email address */
	private array $accounts = ['alice' => 'alice@example.com', 'bob' => ''];

	private function runner(): ActionRunner {
		$test = $this;
		$mail = new class($test) implements MailPort {
			public function __construct(private TestCase $test) {
			}

			public function send(string $to, string $subject, string $body): void {
				/** @var ActionRunnerTest $t */
				$t = $this->test;
				$t->recordMail($to, $subject, $body);
			}
		};
		$http = new class($test) implements HttpPort {
			public function __construct(private TestCase $test) {
			}

			public function postJson(string $url, string $body, array $headers, int $timeoutSeconds): int {
				/** @var ActionRunnerTest $t */
				$t = $this->test;
				return $t->recordPost($url, $body, $headers, $timeoutSeconds);
			}
		};
		$secrets = new class($test) implements SecretPort {
			public function __construct(private TestCase $test) {
			}

			public function get(int $formId, string $ref): ?string {
				/** @var ActionRunnerTest $t */
				$t = $this->test;
				return $t->secretFor($formId, $ref);
			}
		};
		$accounts = new class($test) implements AccountPort {
			public function __construct(private TestCase $test) {
			}

			public function emailOf(string $userId): ?string {
				/** @var ActionRunnerTest $t */
				$t = $this->test;
				return $t->accountEmail($userId);
			}
		};
		return new ActionRunner($mail, $http, $secrets, $accounts);
	}

	public function recordMail(string $to, string $subject, string $body): void {
		if ($this->mailError !== null) {
			throw $this->mailError;
		}
		$this->mails[] = [$to, $subject, $body];
	}

	public function recordPost(string $url, string $body, array $headers, int $timeout): int {
		if ($this->httpError !== null) {
			throw $this->httpError;
		}
		$this->posts[] = [$url, $body, $headers, $timeout];
		return $this->httpStatus;
	}

	public function accountEmail(string $userId): ?string {
		$address = $this->accounts[$userId] ?? null;
		return $address === '' ? null : $address;
	}

	public function secretFor(int $formId, string $ref): ?string {
		return $formId === 7 && $ref === 'whsec_one' ? $this->secret : null;
	}

	private function context(array $data = ['f_name' => 'Ada', 'f_email' => 'ada@example.com'], int $recent = 0, ?string $userId = null, string $ownerId = 'alice'): ActionContext {
		return new ActionContext(
			formId: 7,
			versionId: 3,
			entryId: 457,
			logId: 99,
			userId: $userId,
			submittedAt: 1794700000,
			fields: [
				'f_name' => ['type' => 'text', 'label' => 'Name'],
				'f_email' => ['type' => 'email', 'label' => 'Email'],
				'f_note' => ['type' => 'paragraph', 'text' => 'hi'],
			],
			data: $data,
			now: 1794700100,
			recentEmails: $recent,
			ownerId: $ownerId,
		);
	}

	// ---- email ----

	public function testSendsToAStaticAddressWithPlaceholders(): void {
		$this->runner()->run(['type' => 'email', 'to' => 'owner@example.com', 'subject' => 'New: {f_name}', 'body' => "From {f_name} <{f_email}>"], $this->context());
		$this->assertSame([['owner@example.com', 'New: Ada', 'From Ada <ada@example.com>']], $this->mails);
	}

	public function testSendsToTheRespondentFromAnEmailField(): void {
		$this->runner()->run(['type' => 'email', 'to' => ['field' => 'f_email'], 'subject' => 'Thanks', 'body' => 'x'], $this->context());
		$this->assertSame('ada@example.com', $this->mails[0][0]);
	}

	public function testSendsToTheFormOwnersAccountAddressAndIsNotCapped(): void {
		$this->runner()->run(['type' => 'email', 'to' => ['owner' => true], 'subject' => 'New: {f_name}', 'body' => 'x'], $this->context(recent: ActionRunner::MAX_RESPONDENT_EMAILS_PER_HOUR + 50));
		$this->assertSame('alice@example.com', $this->mails[0][0]);
	}

	public function testSkipsWhenTheOwnerHasNoEmailOnTheirAccount(): void {
		try {
			$this->runner()->run(['type' => 'email', 'to' => ['owner' => true], 'subject' => 's', 'body' => 'b'], $this->context(ownerId: 'bob'));
			$this->fail('expected a skip');
		} catch (ActionSkipped $e) {
			$this->assertSame('no_account_email', $e->getMessage());
		}
		$this->assertSame([], $this->mails);
	}

	public function testSendsToTheSignedInRespondentsAccountAddress(): void {
		$this->runner()->run(['type' => 'email', 'to' => ['respondent' => true], 'subject' => 's', 'body' => 'b'], $this->context(userId: 'alice', ownerId: 'bob'));
		$this->assertSame('alice@example.com', $this->mails[0][0]);
	}

	public function testSkipsTheRespondentAccountWhenNobodyWasSignedInOrTheAccountHasNoEmail(): void {
		foreach ([[null, 'not_signed_in'], ['bob', 'no_account_email'], ['ghost', 'no_account_email']] as [$user, $reason]) {
			try {
				$this->runner()->run(['type' => 'email', 'to' => ['respondent' => true], 'subject' => 's', 'body' => 'b'], $this->context(userId: $user));
				$this->fail('expected a skip');
			} catch (ActionSkipped $e) {
				$this->assertSame($reason, $e->getMessage());
			}
		}
		$this->assertSame([], $this->mails);
	}

	public function testSkipsWhenTheRespondentGaveNoUsableAddress(): void {
		foreach ([[], ['f_email' => ''], ['f_email' => 'nope'], ['f_email' => ['a@b.co']], ['f_email' => "a@b.co\r\nBcc: x@y.z"]] as $data) {
			try {
				$this->runner()->run(['type' => 'email', 'to' => ['field' => 'f_email'], 'subject' => 's', 'body' => 'b'], $this->context($data));
				$this->fail('expected a skip');
			} catch (ActionSkipped $e) {
				$this->assertSame('no_recipient', $e->getMessage());
			}
		}
		$this->assertSame([], $this->mails);
	}

	public function testRespondentEmailsAreCappedPerFormButOwnerEmailsAreNot(): void {
		$action = ['type' => 'email', 'to' => ['field' => 'f_email'], 'subject' => 's', 'body' => 'b'];
		try {
			$this->runner()->run($action, $this->context(recent: ActionRunner::MAX_RESPONDENT_EMAILS_PER_HOUR));
			$this->fail('expected a skip');
		} catch (ActionSkipped $e) {
			$this->assertSame('rate_limited', $e->getMessage());
		}
		$this->assertSame([], $this->mails);

		$this->runner()->run($action, $this->context(recent: ActionRunner::MAX_RESPONDENT_EMAILS_PER_HOUR - 1));
		$this->assertCount(1, $this->mails);

		$this->runner()->run(['type' => 'email', 'to' => 'owner@example.com', 'subject' => 's', 'body' => 'b'], $this->context(recent: 1000));
		$this->assertCount(2, $this->mails);
	}

	public function testSubjectLinesCannotBeUsedToInjectHeaders(): void {
		$this->runner()->run(['type' => 'email', 'to' => 'owner@example.com', 'subject' => 'Hi {f_name}', 'body' => 'b'], $this->context(['f_name' => "x\r\nBcc: a@b.co"]));
		$this->assertStringNotContainsString("\n", $this->mails[0][1]);
	}

	public function testWebhookPayloadNeverRevealsWhereAFileIsStored(): void {
		$context = new ActionContext(
			formId: 7, versionId: 3, entryId: 457, logId: 99, userId: null, submittedAt: 1794700000,
			fields: ['f_cv' => ['type' => 'file', 'label' => 'CV']],
			data: ['f_cv' => [['name' => 'cv.pdf', 'size' => 25, 'mime' => 'application/pdf', 'storage' => 'files', 'ref' => '151']]],
			now: 1794700100, recentEmails: 0, ownerId: 'alice',
		);
		$this->runner()->run(['type' => 'webhook', 'url' => 'https://hooks.example.com/x'], $context);
		$payload = json_decode($this->posts[0][1], true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(['label' => 'CV', 'value' => [['name' => 'cv.pdf', 'size' => 25, 'mime' => 'application/pdf']]], $payload['data']['f_cv']);
		$this->assertStringNotContainsString('151', $this->posts[0][1]);
		$this->assertStringNotContainsString('storage', $this->posts[0][1]);
	}

	public function testAMailSystemErrorIsRetryable(): void {
		$this->mailError = new \RuntimeException('smtp down');
		try {
			$this->runner()->run(['type' => 'email', 'to' => 'owner@example.com', 'subject' => 's', 'body' => 'b'], $this->context());
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertTrue($e->retryable);
			$this->assertStringContainsString('smtp down', $e->getMessage());
		}
	}

	// ---- webhook ----

	public function testPostsTheDocumentedPayload(): void {
		$this->runner()->run(['type' => 'webhook', 'url' => 'https://hooks.example.com/x'], $this->context());
		[$url, $body, $headers, $timeout] = $this->posts[0];
		$this->assertSame('https://hooks.example.com/x', $url);
		$this->assertSame(10, $timeout);
		$this->assertSame('application/json', $headers['Content-Type']);
		$this->assertSame('99', $headers['X-Webforms-Delivery']);
		$this->assertSame('entry.created', $headers['X-Webforms-Event']);

		$payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame('entry.created', $payload['event']);
		$this->assertSame(7, $payload['formId']);
		$this->assertSame(3, $payload['versionId']);
		$this->assertSame(457, $payload['entryId']);
		$this->assertSame('2026-11-14T23:46:40Z', $payload['submittedAt']);
		$this->assertNull($payload['userId']);
		$this->assertSame(['label' => 'Name', 'value' => 'Ada'], $payload['data']['f_name']);
		// Display fields are not answers.
		$this->assertArrayNotHasKey('f_note', $payload['data']);
	}

	public function testOmitsUnansweredFieldsAndKeepsAnEmptyMapAnObject(): void {
		$this->runner()->run(['type' => 'webhook', 'url' => 'https://hooks.example.com/x'], $this->context(['f_name' => 'Ada']));
		$this->assertArrayNotHasKey('f_email', json_decode($this->posts[0][1], true)['data']);

		$this->runner()->run(['type' => 'webhook', 'url' => 'https://hooks.example.com/x'], $this->context([]));
		$this->assertStringContainsString('"data":{}', $this->posts[1][1]);
	}

	public function testSignsTheBodyWithTheStoredSecret(): void {
		$this->runner()->run(['type' => 'webhook', 'url' => 'https://hooks.example.com/x', 'secretRef' => 'whsec_one'], $this->context());
		[, $body, $headers] = $this->posts[0];
		$this->assertSame('1794700100', $headers['X-Webforms-Timestamp']);
		$expected = 'sha256=' . hash_hmac('sha256', '1794700100.' . $body, 'topsecret');
		$this->assertSame($expected, $headers['X-Webforms-Signature']);
		$this->assertSame($expected, ActionRunner::signature('topsecret', 1794700100, $body));
		// The timestamp is signed, so replaying the body with a new one fails verification.
		$this->assertNotSame($expected, ActionRunner::signature('topsecret', 1794700101, $body));
		// The secret itself never goes over the wire.
		$this->assertStringNotContainsString('topsecret', json_encode($headers) . $body);
	}

	public function testWithoutASecretRefTheRequestIsUnsigned(): void {
		$this->runner()->run(['type' => 'webhook', 'url' => 'https://hooks.example.com/x'], $this->context());
		$this->assertArrayNotHasKey('X-Webforms-Signature', $this->posts[0][2]);
	}

	public function testAMissingSecretFailsWithoutSendingAnythingUnsigned(): void {
		$this->secret = null;
		try {
			$this->runner()->run(['type' => 'webhook', 'url' => 'https://hooks.example.com/x', 'secretRef' => 'whsec_one'], $this->context());
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertSame('secret_missing', $e->getMessage());
			$this->assertFalse($e->retryable);
		}
		$this->assertSame([], $this->posts);
	}

	/**
	 * @return array<string, array{int, bool}>
	 */
	public static function statusProvider(): array {
		return [
			'server error' => [500, true],
			'bad gateway' => [502, true],
			'too many requests' => [429, true],
			'request timeout' => [408, true],
			'not found' => [404, false],
			'unauthorized' => [401, false],
			'redirect is not followed' => [302, false],
		];
	}

	#[DataProvider('statusProvider')]
	public function testFailureStatusesAreClassified(int $status, bool $retryable): void {
		$this->httpStatus = $status;
		try {
			$this->runner()->run(['type' => 'webhook', 'url' => 'https://hooks.example.com/x'], $this->context());
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertSame('http_' . $status, $e->getMessage());
			$this->assertSame($retryable, $e->retryable);
		}
	}

	public function testEverySuccessStatusIsAccepted(): void {
		foreach ([200, 201, 202, 204] as $status) {
			$this->httpStatus = $status;
			$this->runner()->run(['type' => 'webhook', 'url' => 'https://hooks.example.com/x'], $this->context());
		}
		$this->assertCount(4, $this->posts);
	}

	public function testATransportErrorIsRetryable(): void {
		$this->httpError = new \RuntimeException('connection refused');
		try {
			$this->runner()->run(['type' => 'webhook', 'url' => 'https://hooks.example.com/x'], $this->context());
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertTrue($e->retryable);
		}
	}

	public function testUnknownActionTypesAreAPermanentFailure(): void {
		try {
			$this->runner()->run(['type' => 'sms'], $this->context());
			$this->fail('expected a failure');
		} catch (ActionFailed $e) {
			$this->assertFalse($e->retryable);
		}
	}
}
