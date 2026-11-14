<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Db;

use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * The action log table is written by the actions pipeline (a later phase).
 * For now this only supports cleanup when a form is deleted.
 */
class ActionLogMapper {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @throws Exception
	 */
	public function deleteByForm(int $formId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('advforms_action_log')->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
		$qb->executeStatement();
	}
}
