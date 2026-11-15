<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Db;

use OCA\Webforms\Forms\ShareSettings;
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
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method int getEmbed()
 * @method void setEmbed(int $embed)
 * @method string|null getEmbedOrigins()
 * @method void setEmbedOrigins(?string $embedOrigins)
 * @method int|null getLatestVersionId()
 * @method void setLatestVersionId(?int $latestVersionId)
 * @method int|null getArchivedAt()
 * @method void setArchivedAt(?int $archivedAt)
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
	protected $slug;
	protected $embed = 0;
	protected $embedOrigins;
	protected $latestVersionId;
	protected $archivedAt;
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
		$this->addType('slug', 'string');
		$this->addType('embed', 'integer');
		$this->addType('embedOrigins', 'string');
		$this->addType('latestVersionId', 'integer');
		$this->addType('archivedAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function isArchived(): bool {
		return $this->getArchivedAt() !== null;
	}

	/** Whether other websites may show this form in a frame. */
	public function isEmbeddable(): bool {
		return (int)$this->getEmbed() === 1;
	}

	/**
	 * Origins allowed to frame the form. Empty means any site.
	 *
	 * @return list<string>
	 */
	public function getEmbedOriginList(): array {
		return ShareSettings::decodeOrigins($this->getEmbedOrigins());
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
