<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Exception;

use RuntimeException;

/** A definition or submission failed validation. Carries machine-readable errors. */
class ValidationException extends RuntimeException {
	/**
	 * @param list<array<string, string>> $errors
	 */
	public function __construct(
		private readonly array $errors,
		string $message = 'Validation failed',
	) {
		parent::__construct($message);
	}

	/**
	 * @return list<array<string, string>>
	 */
	public function getErrors(): array {
		return $this->errors;
	}
}
