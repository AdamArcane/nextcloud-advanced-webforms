<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Settings;

use OCA\Webforms\Actions\MailPolicy;
use OCA\Webforms\Security\Captcha\ConfigStore;

/**
 * The administrator's settings for mail that forms send (docs/SCHEMA.md section 16.2). Never
 * configured means today's behaviour: no default reply-to, Nextcloud's own sender name, and form
 * owners free to set their own. A damaged value falls back to that too.
 */
class MailSettings {
	private const REPLY_TO_MODE = 'mail_reply_to_mode';
	private const REPLY_TO_ADDRESS = 'mail_reply_to_address';
	private const ALLOW_OVERRIDE = 'mail_allow_override';
	private const FROM_NAME = 'mail_from_name';

	public function __construct(
		private readonly ConfigStore $config,
	) {
	}

	public function policy(): MailPolicy {
		$mode = $this->config->get(self::REPLY_TO_MODE);
		$address = $this->config->get(self::REPLY_TO_ADDRESS);
		if (!in_array($mode, MailPolicy::REPLY_MODES, true)) {
			$mode = MailPolicy::REPLY_NONE;
		}
		if ($mode === MailPolicy::REPLY_ADDRESS && !self::isAddress($address)) {
			// A fixed address that is not one any more: better no reply-to than a broken header.
			$mode = MailPolicy::REPLY_NONE;
		}
		return new MailPolicy(
			replyToMode: $mode,
			replyToAddress: $mode === MailPolicy::REPLY_ADDRESS ? $address : '',
			// Only an explicit "0" locks it, so a missing or damaged value never takes a choice away.
			allowOverride: $this->config->get(self::ALLOW_OVERRIDE) !== '0',
			fromName: MailPolicy::cleanName($this->config->get(self::FROM_NAME)),
		);
	}

	public function save(string $replyToMode, string $replyToAddress, bool $allowOverride, string $fromName): void {
		$this->config->set(self::REPLY_TO_MODE, in_array($replyToMode, MailPolicy::REPLY_MODES, true) ? $replyToMode : MailPolicy::REPLY_NONE);
		$this->config->set(self::REPLY_TO_ADDRESS, $replyToMode === MailPolicy::REPLY_ADDRESS ? trim($replyToAddress) : '');
		$this->config->set(self::ALLOW_OVERRIDE, $allowOverride ? '1' : '0');
		$this->config->set(self::FROM_NAME, MailPolicy::cleanName($fromName));
	}

	/** One plain address that is safe in a mail header. */
	public static function isAddress(string $address): bool {
		return $address !== '' && strlen($address) <= 254 && filter_var($address, FILTER_VALIDATE_EMAIL) !== false && preg_match('/[\r\n]/', $address) !== 1;
	}
}
