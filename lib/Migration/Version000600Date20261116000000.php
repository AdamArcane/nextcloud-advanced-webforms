<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Migration;

use Closure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * New responses, statuses and a note (docs/SCHEMA.md section 20): when the owner last looked at a form's
 * responses, and a status and a note on each response.
 */
class Version000600Date20261116000000 extends SimpleMigrationStep {
	private bool $addedSeenAt = false;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $time,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		$forms = $schema->getTable('advforms_forms');
		if (!$forms->hasColumn('responses_seen_at')) {
			// Unix seconds. A response submitted later is new. Null means the list was never opened.
			$forms->addColumn('responses_seen_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$this->addedSeenAt = true;
			$changed = true;
		}

		$entries = $schema->getTable('advforms_entries');
		if (!$entries->hasColumn('status')) {
			// The id of one of the administrator's statuses, or null for none.
			$entries->addColumn('status', Types::STRING, ['notnull' => false, 'length' => 32]);
			$entries->addIndex(['form_id', 'status'], 'advforms_ent_form_status');
			$changed = true;
		}
		if (!$entries->hasColumn('note')) {
			// The owner's private note. Never sent anywhere.
			$entries->addColumn('note', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	/**
	 * Forms that exist already start as looked at, so nothing old shows up as new after the update.
	 *
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->addedSeenAt) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('advforms_forms')
			->set('responses_seen_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->isNull('responses_seen_at'));
		$qb->executeStatement();
	}
}
