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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<FormShare>
 */
class FormShareMapper extends QBMapper {
	/** Parameters in one IN list, kept under what every supported database accepts. */
	private const CHUNK = 500;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'advforms_form_shares', FormShare::class);
	}

	/**
	 * @return list<FormShare>
	 */
	public function findByForm(int $formId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)))
			->orderBy('created_at', 'ASC')->addOrderBy('id', 'ASC');
		return array_values($this->findEntities($qb));
	}

	/**
	 * @throws NotFoundException
	 */
	public function findInForm(int $formId, int $id): FormShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException();
		}
	}

	public function findOne(int $formId, int $type, string $with): ?FormShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($with)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Every share that reaches this person: made with their account or with one of their groups.
	 *
	 * @param list<string> $groupIds
	 * @return list<FormShare>
	 */
	public function findForTargets(string $userId, array $groupIds): array {
		$found = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('share_type', $qb->createNamedParameter(FormShare::TYPE_USER)))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($userId)));
		foreach ($this->findEntities($qb) as $share) {
			$found[] = $share;
		}
		foreach (array_chunk($groupIds, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->eq('share_type', $qb->createNamedParameter(FormShare::TYPE_GROUP)))
				->andWhere($qb->expr()->in('share_with', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			foreach ($this->findEntities($qb) as $share) {
				$found[] = $share;
			}
		}
		return $found;
	}

	/** Remove every share of a form, when the form is deleted. */
	public function deleteByForm(int $formId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
		$qb->executeStatement();
	}

	/** Remove every share made with this account or group, when it no longer exists. */
	public function deleteByTarget(int $type, string $with): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('share_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($with)));
		$qb->executeStatement();
	}
}
