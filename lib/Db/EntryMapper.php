<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Db;

use OCA\AdvancedForms\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Entry storage. Everything that reads or writes entries goes through here,
 * so a different storage backend (for example Nextcloud Tables) can replace it
 * later without touching callers.
 *
 * @template-extends QBMapper<Entry>
 */
class EntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'advforms_entries', Entry::class);
	}

	/**
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function findInForm(int $formId, int $entryId): Entry {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($entryId)))
			->andWhere($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException();
		}
	}

	/**
	 * Newest first.
	 *
	 * @return list<Entry>
	 * @throws Exception
	 */
	public function findByForm(int $formId, int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)))
			->orderBy('submitted_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return array_values($this->findEntities($qb));
	}

	/**
	 * @throws Exception
	 */
	public function countByForm(int $formId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($this->getTableName())
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		return $total;
	}

	/**
	 * @throws Exception
	 */
	public function countByFormAndUser(int $formId, string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($this->getTableName())
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		return $total;
	}

	/**
	 * Remove a deleted account's name from the responses it gave, keeping the answers.
	 *
	 * @throws Exception
	 */
	public function anonymizeUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function deleteByForm(int $formId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
		$qb->executeStatement();
	}
}
