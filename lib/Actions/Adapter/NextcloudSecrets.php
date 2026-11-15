<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Adapter;

use OCA\Webforms\Actions\Ports\SecretPort;
use OCA\Webforms\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Webhook signing secrets, kept out of form definitions. Definitions are
 * versioned, copied into entries views and sent around; a secret there would
 * leak. Only a reference (`secretRef`) lives in the definition. The value is
 * stored as a sensitive app config value, which Nextcloud encrypts at rest.
 */
final class NextcloudSecrets implements SecretPort {
	public const REF_PATTERN = '/^whsec_[a-z0-9]{8,32}$/D';

	public function __construct(
		private readonly IAppConfig $config,
		private readonly ISecureRandom $random,
	) {
	}

	public function get(int $formId, string $ref): ?string {
		if (preg_match(self::REF_PATTERN, $ref) !== 1) {
			return null;
		}
		$value = $this->config->getValueString(Application::APP_ID, self::key($formId, $ref), '', true);
		return $value === '' ? null : $value;
	}

	public function exists(int $formId, string $ref): bool {
		return $this->get($formId, $ref) !== null;
	}

	/**
	 * Create a secret, or replace the one stored under `$ref`. The value is
	 * returned here and cannot be read back later.
	 *
	 * @return array{ref: string, secret: string}
	 */
	public function generate(int $formId, ?string $ref = null): array {
		if ($ref === null || preg_match(self::REF_PATTERN, $ref) !== 1) {
			$ref = 'whsec_' . $this->random->generate(12, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		}
		$secret = $this->random->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->config->setValueString(Application::APP_ID, self::key($formId, $ref), $secret, true, true);
		return ['ref' => $ref, 'secret' => $secret];
	}

	public function deleteForForm(int $formId): void {
		$prefix = 'whsec.' . $formId . '.';
		foreach ($this->config->getKeys(Application::APP_ID) as $key) {
			if (str_starts_with($key, $prefix)) {
				$this->config->deleteKey(Application::APP_ID, $key);
			}
		}
	}

	private static function key(int $formId, string $ref): string {
		return 'whsec.' . $formId . '.' . $ref;
	}
}
