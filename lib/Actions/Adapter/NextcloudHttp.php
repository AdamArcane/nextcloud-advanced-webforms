<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Adapter;

use OCA\Webforms\Actions\Ports\HttpPort;
use OCP\Http\Client\IClientService;

/**
 * Uses Nextcloud's HTTP client on purpose: it refuses addresses on private
 * networks unless an administrator has allowed them, which keeps a form owner
 * from pointing a webhook at the server's internal services (SSRF).
 */
final class NextcloudHttp implements HttpPort {
	public function __construct(
		private readonly IClientService $clients,
	) {
	}

	public function postJson(string $url, string $body, array $headers, int $timeoutSeconds): int {
		$response = $this->clients->newClient()->post($url, [
			'body' => $body,
			'headers' => $headers,
			'timeout' => $timeoutSeconds,
			// Report 4xx and 5xx as a status instead of throwing, and never follow a redirect to somewhere else.
			'http_errors' => false,
			'allow_redirects' => false,
		]);
		return $response->getStatusCode();
	}
}
