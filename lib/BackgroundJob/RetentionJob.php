<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\BackgroundJob;

use OCA\Webforms\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Applies the administrator's retention period. Does nothing while retention is off.
 */
class RetentionJob extends TimedJob {
	public function __construct(
		private readonly ITimeFactory $clock,
		private readonly RetentionService $retention,
	) {
		parent::__construct($clock);
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->retention->purge($this->clock->getTime());
	}
}
