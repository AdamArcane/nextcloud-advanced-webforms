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
use OCP\DB\QueryBuilder\IQueryBuilder;
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
	 * Store the draft fields only if the stored revision is still `$base`, in one statement, so two saves
	 * can never both win (docs/SCHEMA.md section 22.6). On success the entity carries the new revision.
	 *
	 * @throws Exception
	 */
	public function updateDraftAt(Form $form, int $base, string $savedBy): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('title', $qb->createNamedParameter($form->getTitle()))
			->set('description', $qb->createNamedParameter($form->getDescription()))
			->set('draft', $qb->createNamedParameter($form->getDraft()))
			->set('retired_ids', $qb->createNamedParameter($form->getRetiredIds()))
			->set('updated_at', $qb->createNamedParameter($form->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->set('revision', $qb->createNamedParameter($base + 1, IQueryBuilder::PARAM_INT))
			->set('draft_saved_by', $qb->createNamedParameter($savedBy))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($form->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($base, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) {
			return false;
		}
		$form->setRevision($base + 1);
		$form->setDraftSavedBy($savedBy);
		$form->resetUpdatedFields();
		return true;
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
	 * @param list<int> $ids
	 * @return list<Form>
	 * @throws Exception
	 */
	public function findByIds(array $ids): array {
		$forms = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $form) {
				$forms[] = $form;
			}
		}
		return $forms;
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
