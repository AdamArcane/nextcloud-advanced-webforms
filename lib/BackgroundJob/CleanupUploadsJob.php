<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\BackgroundJob;

use OCA\AdvancedForms\Uploads\UploadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Removes uploads that were never submitted with their form.
 */
class CleanupUploadsJob extends TimedJob {
	public function __construct(
		private readonly ITimeFactory $clock,
		private readonly UploadService $uploads,
	) {
		parent::__construct($clock);
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->uploads->cleanup($this->clock->getTime());
	}
}
