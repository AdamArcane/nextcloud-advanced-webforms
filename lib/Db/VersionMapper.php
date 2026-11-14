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
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Version>
 */
class VersionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'advforms_versions', Version::class);
	}

	/**
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function find(int $id): Version {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException();
		}
	}

	/**
	 * @throws Exception
	 */
	public function findLatest(int $formId): ?Version {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)))
			->orderBy('version_number', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
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
