<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Security\Captcha;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

final class NextcloudCaptchaHttp implements CaptchaHttp {
	public function __construct(
		private readonly IClientService $clients,
		private readonly LoggerInterface $logger,
	) {
	}

	public function postForm(string $url, array $fields, int $timeoutSeconds): array {
		try {
			$response = $this->clients->newClient()->post($url, [
				'form_params' => $fields,
				'timeout' => $timeoutSeconds,
				'http_errors' => false,
				'allow_redirects' => false,
			]);
			$data = json_decode((string)$response->getBody(), true, 8, JSON_THROW_ON_ERROR);
		} catch (\Throwable $e) {
			$this->logger->warning('The captcha provider could not be reached or gave an unreadable answer', ['exception' => $e]);
			throw $e;
		}
		return is_array($data) ? $data : [];
	}
}
