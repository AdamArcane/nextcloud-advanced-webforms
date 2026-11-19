<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * An immutable published snapshot of a form definition.
 *
 * @method int getFormId()
 * @method void setFormId(int $formId)
 * @method int getVersionNumber()
 * @method void setVersionNumber(int $versionNumber)
 * @method int getSchemaVersion()
 * @method void setSchemaVersion(int $schemaVersion)
 * @method string getDefinition()
 * @method void setDefinition(string $definition)
 * @method string|null getPublishedBy()
 * @method void setPublishedBy(?string $publishedBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Version extends Entity {
	protected $formId;
	protected $versionNumber;
	protected $schemaVersion;
	protected $definition;
	/** Who published it, or null for a version from before this was kept. */
	protected $publishedBy;
	protected $createdAt;

	public function __construct() {
		$this->addType('formId', 'integer');
		$this->addType('versionNumber', 'integer');
		$this->addType('schemaVersion', 'integer');
		$this->addType('definition', 'string');
		$this->addType('publishedBy', 'string');
		$this->addType('createdAt', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDefinitionArray(): array {
		$decoded = json_decode($this->getDefinition(), true);
		return is_array($decoded) ? $decoded : [];
	}
}
