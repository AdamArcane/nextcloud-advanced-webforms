<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Uploads\Ports;

interface TokenPort {
	/** A new unguessable id: 32 lower case letters and digits. */
	public function newToken(): string;
}
