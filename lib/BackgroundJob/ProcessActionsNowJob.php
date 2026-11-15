<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\BackgroundJob;

use OCA\Webforms\Service\ActionProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Queued right after a submission so email and webhooks go out at the next
 * background job run instead of waiting for the one-minute timer.
 */
class ProcessActionsNowJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly ActionProcessor $processor,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$this->processor->processDue();
	}
}
