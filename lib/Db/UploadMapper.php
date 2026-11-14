<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Db;

use OCA\AdvancedForms\Uploads\PendingUpload;
use OCA\AdvancedForms\Uploads\Ports\UploadRegistryPort;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Uploads waiting for their form to be submitted.
 *
 * @template-extends QBMapper<Upload>
 */
class UploadMapper extends QBMapper implements UploadRegistryPort {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'advforms_uploads', Upload::class);
	}

	public function add(PendingUpload $upload): void {
		$row = new Upload();
		$row->setToken($upload->token);
		$row->setFormId($upload->formId);
		$row->setFieldId($upload->fieldId);
		$row->setUserId($upload->userId);
		$row->setName($upload->name);
		$row->setSize($upload->size);
		$row->setMime($upload->mime);
		$row->setCreatedAt($upload->createdAt);
		$this->insert($row);
	}

	public function find(string $token): ?PendingUpload {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		try {
			return self::pending($this->findEntity($qb));
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function remove(string $token): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		$qb->executeStatement();
	}

	public function pendingBytes(int $formId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('size'))->from($this->getTableName())->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		return $total;
	}

	public function expired(int $olderThan, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($olderThan)))
			->orderBy('created_at', 'ASC')
			->setMaxResults($limit);
		return array_values(array_map(self::pending(...), $this->findEntities($qb)));
	}

	public function forForm(int $formId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
		return array_values(array_map(self::pending(...), $this->findEntities($qb)));
	}

	private static function pending(Upload $row): PendingUpload {
		return new PendingUpload($row->getToken(), $row->getFormId(), $row->getFieldId(), $row->getUserId(), $row->getName(), $row->getSize(), $row->getMime(), $row->getCreatedAt());
	}
}
