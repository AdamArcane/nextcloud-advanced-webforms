<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Exception;

use OCA\Webforms\Db\Form;
use RuntimeException;

/** The draft was saved by someone else since the editor loaded it (docs/SCHEMA.md section 22.6). */
class DraftConflictException extends RuntimeException {
	public function __construct(
		private readonly Form $stored,
		private readonly string $savedByName,
	) {
		parent::__construct('Draft conflict');
	}

	public function getStored(): Form {
		return $this->stored;
	}

	public function getSavedByName(): string {
		return $this->savedByName;
	}
}
