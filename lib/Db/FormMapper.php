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
 * @template-extends QBMapper<Form>
 */
class FormMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'advforms_forms', Form::class);
	}

	/**
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function find(int $id): Form {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException();
		}
	}

	/**
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function findByToken(string $token): Form {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('public_token', $qb->createNamedParameter($token)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException();
		}
	}

	/**
	 * @return list<Form>
	 * @throws Exception
	 */
	public function findByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('updated_at', 'DESC');
		return array_values($this->findEntities($qb));
	}
}
