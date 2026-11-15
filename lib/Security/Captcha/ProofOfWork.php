<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Security\Captcha;

/**
 * Built-in captcha: the browser must find a number that hashes to a challenge the server
 * signed. It costs a real browser a moment and costs a spam bot that submits in bulk much
 * more, with no account, no third party, and nothing shown to the respondent.
 *
 * The format follows the open ALTCHA protocol (version 1, SHA-256):
 *   challenge = sha256(salt . number), signature = hmac_sha256(challenge)
 * and the solution is base64(json {algorithm, challenge, number, salt, signature}).
 * The salt carries its own expiry, so the server keeps no state except used-challenge ids.
 */
final class ProofOfWork {
	public const MAX_NUMBER = 100000;
	private const LIFETIME_SECONDS = 600;
	private const MAX_PAYLOAD_BYTES = 1024;

	public function __construct(
		private readonly string $secret,
		private readonly ReplayStore $replays,
		private readonly int $maxNumber = self::MAX_NUMBER,
	) {
	}

	/**
	 * @return array{algorithm: string, challenge: string, salt: string, signature: string, maxnumber: int}
	 */
	public function issue(int $now): array {
		$salt = bin2hex(random_bytes(12)) . '?expires=' . ($now + self::LIFETIME_SECONDS);
		$number = random_int(0, $this->maxNumber);
		$challenge = hash('sha256', $salt . $number);
		return [
			'algorithm' => 'SHA-256',
			'challenge' => $challenge,
			'salt' => $salt,
			'signature' => $this->sign($challenge),
			'maxnumber' => $this->maxNumber,
		];
	}

	public function verify(string $payload, int $now): bool {
		if ($payload === '' || strlen($payload) > self::MAX_PAYLOAD_BYTES) {
			return false;
		}
		$json = base64_decode($payload, true);
		$data = $json === false ? null : json_decode($json, true);
		if (!is_array($data)
			|| ($data['algorithm'] ?? null) !== 'SHA-256'
			|| !is_string($data['challenge'] ?? null)
			|| !is_string($data['salt'] ?? null)
			|| !is_string($data['signature'] ?? null)
			|| !is_int($data['number'] ?? null)
			|| $data['number'] < 0
			|| $data['number'] > $this->maxNumber) {
			return false;
		}
		// The challenge must be one this server signed, and not changed since.
		if (!hash_equals($this->sign($data['challenge']), $data['signature'])) {
			return false;
		}
		if (preg_match('/\?expires=(\d{1,12})$/D', $data['salt'], $m) !== 1 || (int)$m[1] < $now) {
			return false;
		}
		if (!hash_equals($data['challenge'], hash('sha256', $data['salt'] . $data['number']))) {
			return false;
		}
		return $this->replays->markUsed($data['challenge'], self::LIFETIME_SECONDS);
	}

	private function sign(string $challenge): string {
		return hash_hmac('sha256', $challenge, hash_hmac('sha256', 'webforms-captcha', $this->secret));
	}
}
