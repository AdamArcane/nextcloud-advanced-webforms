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
 */
class Entry extends Entity {
	protected $formId;
	protected $versionId;
	protected $userId;
	protected $data;
	protected $submittedAt;

	public function __construct() {
		$this->addType('formId', 'integer');
		$this->addType('versionId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('data', 'string');
		$this->addType('submittedAt', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDataArray(): array {
		$decoded = json_decode($this->getData(), true);
		return is_array($decoded) ? $decoded : [];
	}
}
