<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Security\Captcha;

interface CaptchaHttp {
	/**
	 * POST a form and return the decoded JSON answer.
	 *
	 * @param array<string, string> $fields
	 * @return array<string, mixed>
	 * @throws \Throwable on a transport failure or an answer that is not JSON
	 */
	public function postForm(string $url, array $fields, int $timeoutSeconds): array;
}
