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
	 * The form a public address leads to: its random token, or its custom address (docs/SCHEMA.md
	 * section 18). The token is tried first, so a custom address can never take over another form's link.
	 *
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function findByToken(string $token): Form {
		foreach (['public_token', 'slug'] as $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq($column, $qb->createNamedParameter($token)));
			try {
				return $this->findEntity($qb);
			} catch (DoesNotExistException) {
				// Try the next column.
			}
		}
		throw new NotFoundException();
	}

	/**
	 * Whether another form already answers to this address, as its custom address or as its token.
	 *
	 * @throws Exception
	 */
	public function addressTaken(string $address, int $exceptFormId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('slug', $qb->createNamedParameter($address)),
				$qb->expr()->eq('public_token', $qb->createNamedParameter($address)),
			))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptFormId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
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
