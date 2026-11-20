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
use OCA\Webforms\Forms\ResponseStatuses;
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
		if ($filter->afterId !== null) {
			$qb->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($filter->afterId, IQueryBuilder::PARAM_INT)));
		}
		if ($filter->respondent === EntryFilter::RESPONDENT_SIGNED_IN) {
			$qb->andWhere($qb->expr()->isNotNull('user_id'));
		} elseif ($filter->respondent === EntryFilter::RESPONDENT_ANONYMOUS) {
			$qb->andWhere($qb->expr()->isNull('user_id'));
		}
		if ($filter->statuses !== null) {
			$ids = array_values(array_filter($filter->statuses, static fn (string $id): bool => $id !== ResponseStatuses::NONE));
			$any = [];
			if ($ids !== []) {
				$any[] = $qb->expr()->in('status', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY));
			}
			if (count($ids) !== count($filter->statuses)) {
				$any[] = $qb->expr()->isNull('status');
			}
			$qb->andWhere($qb->expr()->orX(...$any));
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
	 * How many responses each of these forms has. A form without responses is left out.
	 *
	 * @param list<int> $formIds
	 * @return array<int, int> form id to count
	 * @throws Exception
	 */
	public function countByForms(array $formIds): array {
		$counts = [];
		foreach (array_chunk(array_values(array_unique($formIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('form_id')
				->addSelect($qb->func()->count('*', 'total'))
				->from($this->getTableName())
				->where($qb->expr()->in('form_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->groupBy('form_id');
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$counts[(int)$row['form_id']] = (int)$row['total'];
			}
			$result->closeCursor();
		}
		return $counts;
	}

	/**
	 * How many responses of one form arrived after a moment (docs/SCHEMA.md section 20.1). Null means the
	 * owner never looked, so all of them.
	 *
	 * @throws Exception
	 */
	public function countNew(int $formId, ?int $seenAt): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($this->getTableName())
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		if ($seenAt !== null) {
			$qb->andWhere($qb->expr()->gt('submitted_at', $qb->createNamedParameter($seenAt, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		return $total;
	}

	/**
	 * Responses each of these forms received since this person last opened them, for the forms that have
	 * any (docs/SCHEMA.md sections 20.1 and 22.4). A form the person never opened counts them all.
	 *
	 * @param list<int> $formIds
	 * @return array<int, int> form id to how many are new
	 * @throws Exception
	 */
	public function countNewForUser(string $userId, array $formIds): array {
		$counts = [];
		foreach (array_chunk($formIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('e.form_id')
				->addSelect($qb->func()->count('*', 'total'))
				->from($this->getTableName(), 'e')
				->leftJoin('e', 'advforms_form_seen', 's', $qb->expr()->andX(
					$qb->expr()->eq('s.form_id', 'e.form_id'),
					$qb->expr()->eq('s.user_id', $qb->createNamedParameter($userId)),
				))
				->where($qb->expr()->in('e.form_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->orX(
					$qb->expr()->isNull('s.seen_at'),
					$qb->expr()->gt('e.submitted_at', 's.seen_at'),
				))
				->groupBy('e.form_id');
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$counts[(int)$row['form_id']] = (int)$row['total'];
			}
			$result->closeCursor();
		}
		return $counts;
	}

	/**
	 * The response submitted just before this one in the same form, and the one just after, for stepping
	 * through them. Responses of the same second are ordered by id.
	 *
	 * @return array{previous: int|null, next: int|null}
	 * @throws Exception
	 */
	public function neighbours(Entry $entry): array {
		$found = [];
		foreach (['previous' => ['lt', 'DESC'], 'next' => ['gt', 'ASC']] as $side => [$compare, $direction]) {
			$qb = $this->db->getQueryBuilder();
			$time = $qb->createNamedParameter($entry->getSubmittedAt(), IQueryBuilder::PARAM_INT);
			$qb->select('id')->from($this->getTableName())
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($entry->getFormId(), IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->orX(
					$qb->expr()->$compare('submitted_at', $time),
					$qb->expr()->andX(
						$qb->expr()->eq('submitted_at', $time),
						$qb->expr()->$compare('id', $qb->createNamedParameter($entry->getId(), IQueryBuilder::PARAM_INT)),
					),
				))
				->orderBy('submitted_at', $direction)->addOrderBy('id', $direction)
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$id = $result->fetchOne();
			$result->closeCursor();
			$found[$side] = $id === false ? null : (int)$id;
		}
		return $found;
	}

	/**
	 * How many responses on the whole server have one of these statuses.
	 *
	 * @param list<string> $statusIds
	 * @throws Exception
	 */
	public function countWithStatus(array $statusIds): int {
		if ($statusIds === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter($statusIds, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		return $total;
	}

	/**
	 * Take these statuses off every response that has one of them, when the administrator deletes them.
	 *
	 * @param list<string> $statusIds
	 * @throws Exception
	 */
	public function clearStatuses(array $statusIds): void {
		if ($statusIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->in('status', $qb->createNamedParameter($statusIds, IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
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
