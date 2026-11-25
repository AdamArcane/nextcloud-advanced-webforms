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
 * Which of their folders each person put a form in (docs/SCHEMA.md section 24). No row means none.
 */
class FolderFormMapper {
	private const TABLE = 'advforms_folder_forms';
	private const CHUNK = 500;

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Where this person put each of these forms. A form in no folder is missing.
	 *
	 * @param list<int> $formIds
	 * @return array<int, int> form id to folder id
	 */
	public function forForms(string $userId, array $formIds): array {
		$folders = [];
		foreach (array_chunk($formIds, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('form_id', 'folder_id')->from(self::TABLE)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('form_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$folders[(int)$row['form_id']] = (int)$row['folder_id'];
			}
			$result->closeCursor();
		}
		return $folders;
	}

	public function set(int $formId, string $userId, int $folderId): void {
		// Removed and written again, because an update that changes nothing reports no rows on some databases.
		$this->remove($formId, $userId);
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABLE)->values([
				'folder_id' => $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT),
				'form_id' => $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($userId),
			]);
			$qb->executeStatement();
		} catch (Exception $e) {
			// Two moves at the same moment and the other one wrote first.
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)->set('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		}
	}

	public function remove(int $formId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function deleteByFolder(int $folderId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteByForm(int $formId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
