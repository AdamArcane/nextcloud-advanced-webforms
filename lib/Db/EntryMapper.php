<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Db;

use OCA\Webforms\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Webforms\Forms\EntryFilter;
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
	 * Responses of a form inside a submitted range and from a kind of respondent, in the filter's order.
	 * Free text and answer conditions are not decided here (see EntryFilter::needsScan).
	 *
	 * @return list<Entry>
	 * @throws Exception
	 */
	public function findFiltered(int $formId, EntryFilter $filter, int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$this->applyFilter($qb, $formId, $filter);
		$direction = $filter->newestFirst ? 'DESC' : 'ASC';
		$qb->orderBy('submitted_at', $direction)->addOrderBy('id', $direction)
			->setMaxResults($limit)
			->setFirstResult($offset);
		return array_values($this->findEntities($qb));
	}

	/**
	 * The responses of one form with these ids, oldest first. Ids of other forms, or that do not exist, are
	 * simply not returned.
	 *
	 * @param list<int> $ids
	 * @return list<Entry>
	 * @throws Exception
	 */
	public function findByIds(int $formId, array $ids): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $entry) {
				$found[] = $entry;
			}
		}
		usort($found, static fn (Entry $a, Entry $b): int => [$a->getSubmittedAt(), $a->getId()] <=> [$b->getSubmittedAt(), $b->getId()]);
		return $found;
	}

	/**
	 * @throws Exception
	 */
	public function countFiltered(int $formId, EntryFilter $filter): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($this->getTableName());
		$this->applyFilter($qb, $formId, $filter);
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		return $total;
	}

	private function applyFilter(IQueryBuilder $qb, int $formId, EntryFilter $filter): void {
		$qb->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		if ($filter->from !== null) {
			$qb->andWhere($qb->expr()->gte('submitted_at', $qb->createNamedParameter($filter->from, IQueryBuilder::PARAM_INT)));
		}
		if ($filter->to !== null) {
			$qb->andWhere($qb->expr()->lte('submitted_at', $qb->createNamedParameter($filter->to, IQueryBuilder::PARAM_INT)));
		}
		if ($filter->respondent === EntryFilter::RESPONDENT_SIGNED_IN) {
			$qb->andWhere($qb->expr()->isNotNull('user_id'));
		} elseif ($filter->respondent === EntryFilter::RESPONDENT_ANONYMOUS) {
			$qb->andWhere($qb->expr()->isNull('user_id'));
		}
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
	 * The oldest responses submitted before a moment, for retention (docs/SCHEMA.md section 16).
	 *
	 * @return list<Entry>
	 * @throws Exception
	 */
	public function findOlderThan(int $cutoff, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->lt('submitted_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return array_values($this->findEntities($qb));
	}

	/**
	 * @throws Exception
	 */
	public function countOlderThan(int $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($this->getTableName())
			->where($qb->expr()->lt('submitted_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
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
