<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A form a person saved as a template for their own later use (docs/SCHEMA.md section 13.4). It holds a
 * definition and nothing else: no responses, link or versions.
 *
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getDefinition()
 * @method void setDefinition(string $definition)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class FormTemplate extends Entity {
	/** The owner of a template the administrator made for everyone. No account has this id. */
	public const SERVER = '';

	protected $ownerId;
	protected $title;
	protected $definition;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('ownerId', 'string');
		$this->addType('title', 'string');
		$this->addType('definition', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDefinitionArray(): array {
		$data = json_decode($this->definition, true);
		return is_array($data) ? $data : [];
	}
}
