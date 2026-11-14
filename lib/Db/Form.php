<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getDraft()
 * @method void setDraft(string $draft)
 * @method string|null getRetiredIds()
 * @method void setRetiredIds(?string $retiredIds)
 * @method int getStatus()
 * @method void setStatus(int $status)
 * @method string|null getPublicToken()
 * @method void setPublicToken(?string $publicToken)
 * @method int|null getLatestVersionId()
 * @method void setLatestVersionId(?int $latestVersionId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Form extends Entity {
	public const STATUS_UNPUBLISHED = 0;
	public const STATUS_PUBLISHED = 1;
	public const STATUS_CLOSED = 2;

	protected $ownerId;
	protected $title;
	protected $description;
	protected $draft;
	protected $retiredIds;
	protected $status = self::STATUS_UNPUBLISHED;
	protected $publicToken;
	protected $latestVersionId;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('ownerId', 'string');
		$this->addType('title', 'string');
		$this->addType('description', 'string');
		$this->addType('draft', 'string');
		$this->addType('retiredIds', 'string');
		$this->addType('status', 'integer');
		$this->addType('publicToken', 'string');
		$this->addType('latestVersionId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDraftDefinition(): array {
		$decoded = json_decode($this->getDraft(), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @return list<string>
	 */
	public function getRetiredIdList(): array {
		$decoded = json_decode((string)$this->getRetiredIds(), true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
	}
}
