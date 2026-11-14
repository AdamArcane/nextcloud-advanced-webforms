<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Actions\Ports;

interface SecretPort {
	/**
	 * The signing secret stored for a form's webhook, or null if there is none.
	 */
	public function get(int $formId, string $ref): ?string;
}
