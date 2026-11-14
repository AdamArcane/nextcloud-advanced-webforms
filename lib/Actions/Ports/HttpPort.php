<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Actions\Ports;

interface HttpPort {
	/**
	 * POST a JSON body and return the HTTP status code. Redirects are not followed.
	 *
	 * @param array<string, string> $headers
	 * @throws \Throwable on a transport failure (DNS, connection, timeout, blocked address)
	 */
	public function postJson(string $url, string $body, array $headers, int $timeoutSeconds): int;
}
