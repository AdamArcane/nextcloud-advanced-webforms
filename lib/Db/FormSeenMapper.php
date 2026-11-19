<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Db;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * When each person last opened a form's responses (docs/SCHEMA.md sections 20.1 and 22.2). "New" is
 * their own, so one person looking does not clear it for the rest of the team.
 */
class FormSeenMapper {
	private const TABLE = 'advforms_form_seen';
	private const CHUNK = 500;

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function get(int $formId, string $userId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('seen_at')->from(self::TABLE)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$value = $qb->executeQuery()->fetchOne();
		return $value === false || $value === null ? null : (int)$value;
	}

	/**
	 * What this person last saw of each of these forms. A form they never opened is missing.
	 *
	 * @param list<int> $formIds
	 * @return array<int, int> form id to unix time
	 */
	public function forForms(string $userId, array $formIds): array {
		$seen = [];
		foreach (array_chunk($formIds, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('form_id', 'seen_at')->from(self::TABLE)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('form_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$seen[(int)$row['form_id']] = (int)$row['seen_at'];
			}
			$result->closeCursor();
		}
		return $seen;
	}

	public function set(int $formId, string $userId, int $seenAt): void {
		if ($this->update($formId, $userId, $seenAt) > 0) {
			return;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABLE)->values([
				'form_id' => $qb->createNamedParameter($formId),
				'user_id' => $qb->createNamedParameter($userId),
				'seen_at' => $qb->createNamedParameter($seenAt),
			]);
			$qb->executeStatement();
		} catch (Exception $e) {
			// Two tabs opened the list at the same moment and the other one wrote first.
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$this->update($formId, $userId, $seenAt);
		}
	}

	private function update(int $formId, string $userId, int $seenAt): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('seen_at', $qb->createNamedParameter($seenAt))
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}

	public function deleteByForm(int $formId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
		$qb->executeStatement();
	}

	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
