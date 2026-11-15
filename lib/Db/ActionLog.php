<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One queued or finished email or webhook delivery for one entry.
 *
 * `attempt` counts attempts made so far. While `status` is queued,
 * `nextAttemptAt` says when the row is next due; a worker that picks a row
 * pushes that time forward as a lease, so a crashed worker's row becomes due
 * again instead of being stuck.
 *
 * @method int getFormId()
 * @method void setFormId(int $formId)
 * @method int getEntryId()
 * @method void setEntryId(int $entryId)
 * @method string getActionId()
 * @method void setActionId(string $actionId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getAttempt()
 * @method void setAttempt(int $attempt)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int|null getUpdatedAt()
 * @method void setUpdatedAt(?int $updatedAt)
 * @method int|null getNextAttemptAt()
 * @method void setNextAttemptAt(?int $nextAttemptAt)
 */
class ActionLog extends Entity {
	public const STATUS_QUEUED = 'queued';
	public const STATUS_SUCCESS = 'success';
	public const STATUS_FAILED = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	protected $formId;
	protected $entryId;
	protected $actionId;
	protected $type;
	protected $status;
	protected $attempt;
	protected $error;
	protected $createdAt;
	protected $updatedAt;
	protected $nextAttemptAt;

	public function __construct() {
		$this->addType('formId', 'integer');
		$this->addType('entryId', 'integer');
		$this->addType('actionId', 'string');
		$this->addType('type', 'string');
		$this->addType('status', 'string');
		$this->addType('attempt', 'integer');
		$this->addType('error', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('nextAttemptAt', 'integer');
	}
}
