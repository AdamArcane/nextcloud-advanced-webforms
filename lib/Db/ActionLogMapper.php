<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * The delivery queue and log for email and webhook actions.
 *
 * @template-extends QBMapper<ActionLog>
 */
class ActionLogMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'advforms_action_log', ActionLog::class);
	}

	/**
	 * @throws Exception
	 */
	public function enqueue(int $formId, int $entryId, string $actionId, string $type, int $now): ActionLog {
		$row = new ActionLog();
		$row->setFormId($formId);
		$row->setEntryId($entryId);
		$row->setActionId($actionId);
		$row->setType($type);
		$row->setStatus(ActionLog::STATUS_QUEUED);
		$row->setAttempt(0);
		$row->setCreatedAt($now);
		$row->setUpdatedAt($now);
		$row->setNextAttemptAt($now);
		return $this->insert($row);
	}

	/**
	 * Queued rows whose time has come, oldest first.
	 *
	 * @return list<ActionLog>
	 * @throws Exception
	 */
	public function findDue(int $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(ActionLog::STATUS_QUEUED)))
			->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now)))
			->orderBy('next_attempt_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);
		return array_values($this->findEntities($qb));
	}

	/**
	 * Take a row for processing by pushing its due time forward. Only one of
	 * several workers racing for the same row gets true.
	 *
	 * @throws Exception
	 */
	public function claim(ActionLog $row, int $leaseUntil, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('next_attempt_at', $qb->createNamedParameter($leaseUntil))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($row->getId())))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ActionLog::STATUS_QUEUED)))
			->andWhere($qb->expr()->eq('next_attempt_at', $qb->createNamedParameter($row->getNextAttemptAt())));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Emails queued or sent for a form since a time, for the per-hour cap on
	 * mail to addresses that respondents typed.
	 *
	 * @throws Exception
	 */
	public function countRecentEmails(int $formId, int $since): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($this->getTableName())
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('email')))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[ActionLog::STATUS_QUEUED, ActionLog::STATUS_SUCCESS],
				\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY,
			)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
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
