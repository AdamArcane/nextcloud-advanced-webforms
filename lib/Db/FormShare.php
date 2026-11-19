<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A form shared with an account or a group (docs/SCHEMA.md section 22.2).
 *
 * @method int getFormId()
 * @method void setFormId(int $formId)
 * @method int getShareType()
 * @method void setShareType(int $shareType)
 * @method string getShareWith()
 * @method void setShareWith(string $shareWith)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class FormShare extends Entity {
	/** Same numbers as OCP\Share\IShare::TYPE_USER and TYPE_GROUP. */
	public const TYPE_USER = 0;
	public const TYPE_GROUP = 1;

	protected $formId;
	protected $shareType;
	protected $shareWith;
	protected $role;
	protected $createdBy;
	protected $createdAt;

	public function __construct() {
		$this->addType('formId', 'integer');
		$this->addType('shareType', 'integer');
		$this->addType('shareWith', 'string');
		$this->addType('role', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'integer');
	}
}
