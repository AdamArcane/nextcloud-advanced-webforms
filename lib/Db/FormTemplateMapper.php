<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Db;

use OCA\Webforms\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<FormTemplate>
 */
class FormTemplateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'advforms_templates', FormTemplate::class);
	}

	/**
	 * @return list<FormTemplate>
	 */
	public function findByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('title', 'ASC')->addOrderBy('id', 'ASC');
		return array_values($this->findEntities($qb));
	}

	/**
	 * A template of someone else is not there for this person, so ids cannot be probed.
	 *
	 * @throws NotFoundException
	 */
	public function findOwned(string $ownerId, int $id): FormTemplate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException();
		}
	}

	/**
	 * The templates the administrator made for everyone.
	 *
	 * @return list<FormTemplate>
	 */
	public function findServerWide(): array {
		return $this->findByOwner(FormTemplate::SERVER);
	}

	public function countServerWide(): int {
		return $this->countByOwner(FormTemplate::SERVER);
	}

	public function countByOwner(string $ownerId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function deleteByOwner(string $ownerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));
		$qb->executeStatement();
	}
}
