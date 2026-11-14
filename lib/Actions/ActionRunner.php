<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Actions;

use OCA\AdvancedForms\Actions\Ports\AccountPort;
use OCA\AdvancedForms\Actions\Ports\HttpPort;
use OCA\AdvancedForms\Actions\Ports\MailPort;
use OCA\AdvancedForms\Actions\Ports\SecretPort;
use OCA\AdvancedForms\Rules\FieldType;

/**
 * Runs one queued `email` or `webhook` action (docs/SCHEMA.md section 7.2).
 * It only decides and builds; the mailer, HTTP client and secret store are
 * passed in, so the behaviour can be tested without a Nextcloud server.
 */
final class ActionRunner {
	public const WEBHOOK_TIMEOUT_SECONDS = 10;

	/**
	 * Cap on emails whose recipient comes from respondent input, per form per
	 * hour. Without it a public form is a free way to send mail to anyone.
	 */
	public const MAX_RESPONDENT_EMAILS_PER_HOUR = 30;

	public function __construct(
		private readonly MailPort $mail,
		private readonly HttpPort $http,
		private readonly SecretPort $secrets,
		private readonly AccountPort $accounts,
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
		$body = Template::render((string)($action['body'] ?? ''), $context->fields, $context->data, Template::TEXT);

		try {
			$this->mail->send($address, $subject, $body);
		} catch (\Throwable $e) {
			throw new ActionFailed('mail_failed: ' . $e->getMessage(), true);
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
			'User-Agent' => 'NextcloudAdvancedForms',
			'X-AdvancedForms-Event' => 'entry.created',
			'X-AdvancedForms-Delivery' => (string)$context->logId,
		];

		$ref = $action['secretRef'] ?? null;
		if (is_string($ref) && $ref !== '') {
			$secret = $this->secrets->get($context->formId, $ref);
			if ($secret === null || $secret === '') {
				throw new ActionFailed('secret_missing', false);
			}
			$headers['X-AdvancedForms-Timestamp'] = (string)$context->now;
			$headers['X-AdvancedForms-Signature'] = self::signature($secret, $context->now, $body);
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
			$data[$id] = ['label' => (string)($field['label'] ?? ''), 'value' => $context->data[$id]];
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
