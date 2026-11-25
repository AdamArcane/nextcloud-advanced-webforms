<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One person's folder in their list of forms (docs/SCHEMA.md section 24).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Folder extends Entity {
	protected $userId;
	protected $name;
	protected $createdAt;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('name', 'string');
		$this->addType('createdAt', 'integer');
	}
}
