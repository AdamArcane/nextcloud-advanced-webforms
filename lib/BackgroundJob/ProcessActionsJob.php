<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\BackgroundJob;

use OCA\Webforms\Service\ActionProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Picks up deliveries that are due: retries after a failure, and anything the
 * immediate job missed.
 */
class ProcessActionsJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly ActionProcessor $processor,
	) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$this->processor->processDue();
	}
}
