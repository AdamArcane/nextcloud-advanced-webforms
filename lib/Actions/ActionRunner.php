<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions;

use OCA\Webforms\Actions\Ports\AccountPort;
use OCA\Webforms\Actions\Ports\HttpPort;
use OCA\Webforms\Actions\Ports\FilesFailure;
use OCA\Webforms\Actions\Ports\MailPort;
use OCA\Webforms\Actions\Ports\ResponseFilesPort;
use OCA\Webforms\Actions\Ports\SecretPort;
use OCA\Webforms\Actions\Ports\TablesFailure;
use OCA\Webforms\Actions\Ports\TablesPort;
use OCA\Webforms\Forms\CsvExporter;
use OCA\Webforms\Rules\FieldType;

/**
 * Runs one queued `email`, `webhook`, `tables` or `files` action (docs/SCHEMA.md sections 7.2, 15
 * and 19). It only decides and builds; the mailer, HTTP client, secret store and file access are
 * passed in, so the behaviour can be tested without a Nextcloud server.
 */
final class ActionRunner {
	public const WEBHOOK_TIMEOUT_SECONDS = 10;

	/**
	 * Cap on emails whose recipient comes from respondent input, per form per
	 * hour. Without it a public form is a free way to send mail to anyone.
	 */
	public const MAX_RESPONDENT_EMAILS_PER_HOUR = 30;

	/** A response file larger than this is not read again: the owner starts a new one. */
	public const MAX_RESPONSE_FILE_BYTES = 20 * 1024 * 1024;

	public function __construct(
		private readonly MailPort $mail,
		private readonly HttpPort $http,
		private readonly SecretPort $secrets,
		private readonly AccountPort $accounts,
		private readonly ?TablesPort $tables = null,
		private readonly ?ResponseFilesPort $files = null,
	) {
	}

	/**
	 * @param array<string, mixed> $action
	 * @throws ActionSkipped
	 * @throws ActionFailed
	 */
	public function run(array $action, ActionContext $context): void {
		match ($action['type'] ?? null) {
			'email' => $this->email($action, $context),
			'webhook' => $this->webhook($action, $context),
			'tables' => $this->tables($action, $context),
			'files' => $this->files($action, $context),
			default => throw new ActionFailed('unsupported_action', false),
		};
	}

	/**
	 * @param array<string, mixed> $action
	 */
	private function email(array $action, ActionContext $context): void {
		$to = $action['to'] ?? null;
		// Only an address the respondent typed is capped: it is the abuse vector. A fixed address and
		// the accounts of the form owner or a signed-in respondent are not chosen by an anonymous visitor.
		$fromRespondent = false;

		if (is_string($to)) {
			$address = $to;
		} elseif (is_array($to) && ($to['owner'] ?? null) === true) {
			$address = $context->ownerId === '' ? '' : ($this->accounts->emailOf($context->ownerId) ?? '');
			if ($address === '') {
				throw new ActionSkipped('no_account_email');
			}
		} elseif (is_array($to) && ($to['respondent'] ?? null) === true) {
			if ($context->userId === null) {
				throw new ActionSkipped('not_signed_in');
			}
			$address = $this->accounts->emailOf($context->userId) ?? '';
			if ($address === '') {
				throw new ActionSkipped('no_account_email');
			}
		} elseif (is_array($to) && is_string($to['field'] ?? null)) {
			$fromRespondent = true;
			$answer = $context->data[$to['field']] ?? null;
			$address = is_string($answer) ? trim($answer) : '';
		} else {
			throw new ActionFailed('bad_recipient', false);
		}

		if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $address) === 1) {
			throw new ActionSkipped('no_recipient');
		}
		if ($fromRespondent && $context->recentEmails >= self::MAX_RESPONDENT_EMAILS_PER_HOUR) {
			throw new ActionSkipped('rate_limited');
		}

		$subject = Template::render((string)($action['subject'] ?? ''), $context->fields, $context->data, Template::SUBJECT);
		$template = (string)($action['body'] ?? '');
		$html = null;
		$allFields = null;
		if (Template::usesAllFields($template, $context->fields)) {
			// A list of answers reads best as a table, so this mail also gets an HTML version. Mail without
			// the list stays plain text, exactly as before.
			$answers = Template::answers($context->fields, $context->data, $context->pages);
			$allFields = Template::answersAsText($answers);
			$html = Template::renderHtml($template, $context->fields, $context->data, $answers);
		}
		$body = Template::render($template, $context->fields, $context->data, Template::TEXT, $allFields);

		try {
			$this->mail->send($address, $subject, $body, $html, $this->replyTo($action, $address, $context), $context->mail->fromNameFor($action));
		} catch (\Throwable $e) {
			throw new ActionFailed('mail_failed: ' . $e->getMessage(), true);
		}
	}

	/**
	 * The address replies should go to (docs/SCHEMA.md sections 7.2 and 16.2), or null to leave the mail
	 * without one: the action's own when the administrator lets it through, else the server's default.
	 * Unlike the recipient it is never a reason not to send: an empty or invalid answer, an anonymous
	 * respondent or an account without an address just means the next choice, or none.
	 *
	 * @param array<string, mixed> $action
	 */
	private function replyTo(array $action, string $recipient, ActionContext $context): ?string {
		$own = $this->resolveAddress($context->mail->replyToSource($action), $context);
		if ($own !== null) {
			return $own;
		}
		$default = match ($context->mail->replyToMode) {
			MailPolicy::REPLY_OWNER => $this->resolveAddress(['owner' => true], $context),
			MailPolicy::REPLY_ADDRESS => $this->resolveAddress($context->mail->replyToAddress, $context),
			default => null,
		};
		// A default that is the recipient itself would only send replies back to where the mail went.
		return $default !== null && strcasecmp($default, $recipient) === 0 ? null : $default;
	}

	/**
	 * One plain address from a source written like the recipient, or null when there is none.
	 */
	private function resolveAddress(mixed $source, ActionContext $context): ?string {
		$address = '';
		if (is_string($source)) {
			$address = $source;
		} elseif (is_array($source) && ($source['owner'] ?? null) === true) {
			$address = $context->ownerId === '' ? '' : ($this->accounts->emailOf($context->ownerId) ?? '');
		} elseif (is_array($source) && ($source['respondent'] ?? null) === true) {
			$address = $context->userId === null ? '' : ($this->accounts->emailOf($context->userId) ?? '');
		} elseif (is_array($source) && is_string($source['field'] ?? null)) {
			$answer = $context->data[$source['field']] ?? null;
			$address = is_string($answer) ? $answer : '';
		}
		$address = trim($address);
		// It becomes a mail header, so nothing but one plain address may pass.
		if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $address) === 1) {
			return null;
		}
		return $address;
	}

	/**
	 * Adds a row to a Nextcloud Tables table as the form owner (docs/SCHEMA.md section 15). An answer
	 * that has no value, or no matching option in its column, is left out and Tables decides whether
	 * that column was required.
	 *
	 * @param array<string, mixed> $action
	 * @throws ActionSkipped
	 */
	private function tables(array $action, ActionContext $context): void {
		if ($this->tables === null || !$this->tables->isAvailable()) {
			// Tables may be enabled later, so keep trying.
			throw new ActionFailed('tables_unavailable', true);
		}
		$tableId = $action['tableId'] ?? null;
		$map = $action['map'] ?? null;
		if (!is_int($tableId) || !is_array($map) || $map === [] || $context->ownerId === '') {
			throw new ActionFailed('tables_bad_action', false);
		}

		try {
			$columns = [];
			foreach ($this->tables->columns($tableId, $context->ownerId) as $column) {
				$columns[(int)$column['id']] = $column;
			}

			$meta = ['submittedAt' => $context->submittedAt, 'userId' => $context->userId, 'entryId' => $context->entryId];
			$values = [];
			foreach ($map as $columnId => $source) {
				$column = $columns[(int)$columnId] ?? null;
				if ($column === null || !is_string($source)) {
					continue;
				}
				$isMeta = in_array($source, TablesValue::META, true);
				if (!$isMeta && !is_array($context->fields[$source] ?? null)) {
					continue;
				}
				$field = $isMeta ? [] : $context->fields[$source];
				// The converter works from what kind of answer it is: the field's type, or the meta name.
				$value = TablesValue::convert($isMeta ? $source : (string)($field['type'] ?? ''), $field, $context->data[$source] ?? null, $column, $meta);
				if ($value !== null) {
					$values[(int)$columnId] = $value;
				}
			}
			if ($values === []) {
				throw new ActionSkipped('nothing_to_write');
			}
			$this->tables->addRow($tableId, $values, $context->ownerId);
		} catch (TablesFailure $e) {
			throw new ActionFailed($e->getMessage(), $e->retryable);
		}
	}

	/**
	 * Adds the response as a line of a CSV file in the owner's Files (docs/SCHEMA.md section 19), with the
	 * columns and values of the CSV export. The file is read and written under a lock, so two deliveries
	 * never lose each other's line.
	 *
	 * @param array<string, mixed> $action
	 */
	private function files(array $action, ActionContext $context): void {
		$path = is_string($action['path'] ?? null) ? CsvFile::cleanPath($action['path']) : null;
		if ($this->files === null || $path === null || $context->ownerId === '') {
			throw new ActionFailed('files_bad_action', false);
		}
		$table = CsvExporter::table(
			[['id' => $context->entryId, 'versionId' => $context->versionId, 'userId' => $context->userId, 'submittedAt' => $context->submittedAt, 'data' => $context->data]],
			[$context->versionId => ['fields' => $context->fields, 'pages' => $context->pages]],
		);
		$files = $this->files;
		$owner = $context->ownerId;
		try {
			$files->locked($owner, $path, static function () use ($files, $owner, $path, $table): void {
				if (!$files->canWrite($owner, $path)) {
					throw new FilesFailure('files_not_writable');
				}
				$existing = $files->read($owner, $path, self::MAX_RESPONSE_FILE_BYTES);
				$files->write($owner, $path, CsvFile::append($existing, $table['header'], $table['rows'][0], $table['numbers'][0]));
			});
		} catch (FilesFailure $e) {
			throw new ActionFailed($e->getMessage(), $e->retryable);
		}
	}

	/**
	 * @param array<string, mixed> $action
	 */
	private function webhook(array $action, ActionContext $context): void {
		$url = $action['url'] ?? null;
		if (!is_string($url) || $url === '') {
			throw new ActionFailed('bad_url', false);
		}

		$body = json_encode(self::payload($context), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$headers = [
			'Content-Type' => 'application/json',
			'User-Agent' => 'NextcloudWebforms',
			'X-Webforms-Event' => 'entry.created',
			'X-Webforms-Delivery' => (string)$context->logId,
		];

		$ref = $action['secretRef'] ?? null;
		if (is_string($ref) && $ref !== '') {
			$secret = $this->secrets->get($context->formId, $ref);
			if ($secret === null || $secret === '') {
				throw new ActionFailed('secret_missing', false);
			}
			$headers['X-Webforms-Timestamp'] = (string)$context->now;
			$headers['X-Webforms-Signature'] = self::signature($secret, $context->now, $body);
		}

		try {
			$status = $this->http->postJson($url, $body, $headers, self::WEBHOOK_TIMEOUT_SECONDS);
		} catch (\Throwable $e) {
			throw new ActionFailed('request_failed: ' . $e->getMessage(), true);
		}

		if ($status >= 200 && $status < 300) {
			return;
		}
		// A busy or struggling receiver may recover; a rejected request will not.
		$retryable = $status === 408 || $status === 429 || $status >= 500;
		throw new ActionFailed('http_' . $status, $retryable);
	}

	/**
	 * HMAC-SHA256 over `<timestamp>.<body>`, so a captured request cannot be
	 * replayed later with a fresh timestamp.
	 */
	public static function signature(string $secret, int $timestamp, string $body): string {
		return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
	}

	/**
	 * The request body described in docs/SCHEMA.md section 7.3. Hidden and
	 * unanswered fields are not in the entry, so they are not here either.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(ActionContext $context): array {
		$data = [];
		foreach ($context->fields as $id => $field) {
			$id = (string)$id;
			if (!array_key_exists($id, $context->data) || !FieldType::isInput((string)($field['type'] ?? ''))) {
				continue;
			}
			$value = $context->data[$id];
			if (($field['type'] ?? null) === FieldType::FILE && is_array($value)) {
				// Where a file is stored is private; a receiver gets what it is.
				$value = array_values(array_map(static fn (mixed $file): array => is_array($file)
					? ['name' => (string)($file['name'] ?? ''), 'size' => (int)($file['size'] ?? 0), 'mime' => (string)($file['mime'] ?? '')]
					: [], $value));
			}
			$data[$id] = ['label' => (string)($field['label'] ?? ''), 'value' => $value];
		}
		return [
			'event' => 'entry.created',
			'formId' => $context->formId,
			'versionId' => $context->versionId,
			'entryId' => $context->entryId,
			'submittedAt' => gmdate('Y-m-d\TH:i:s\Z', $context->submittedAt),
			'userId' => $context->userId,
			// An empty map must stay an object, not become [].
			'data' => $data === [] ? new \stdClass() : $data,
		];
	}
}
