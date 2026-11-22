<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getFormId()
 * @method void setFormId(int $formId)
 * @method int getVersionId()
 * @method void setVersionId(int $versionId)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string getData()
 * @method void setData(string $data)
 * @method int getSubmittedAt()
 * @method void setSubmittedAt(int $submittedAt)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method int getWaitlisted()
 * @method void setWaitlisted(int $waitlisted)
 */
class Entry extends Entity {
	protected $formId;
	protected $versionId;
	protected $userId;
	protected $data;
	protected $submittedAt;
	/** The id of one of the administrator's statuses, or null (docs/SCHEMA.md section 20.2). */
	protected $status;
	/** The owner's private note, or null. */
	protected $note;
	/** 1 when the response is on the form's waiting list (docs/SCHEMA.md section 3.2), otherwise 0. */
	protected $waitlisted = 0;

	public function __construct() {
		$this->addType('formId', 'integer');
		$this->addType('versionId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('data', 'string');
		$this->addType('submittedAt', 'integer');
		$this->addType('status', 'string');
		$this->addType('note', 'string');
		$this->addType('waitlisted', 'integer');
	}

	public function isWaitlisted(): bool {
		return $this->getWaitlisted() === 1;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDataArray(): array {
		$decoded = json_decode($this->getData(), true);
		return is_array($decoded) ? $decoded : [];
	}
}
