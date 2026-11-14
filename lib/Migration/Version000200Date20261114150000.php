<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Turns the action log into the delivery queue for email and webhook actions
 * (docs/SCHEMA.md section 7.4): what kind of action it is, when to try next, and
 * at most one row per action per entry.
 */
class Version000200Date20261114150000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('advforms_action_log')) {
			return null;
		}
		$table = $schema->getTable('advforms_action_log');

		if (!$table->hasColumn('type')) {
			$table->addColumn('type', Types::STRING, ['notnull' => false, 'length' => 16]);
		}
		if (!$table->hasColumn('next_attempt_at')) {
			$table->addColumn('next_attempt_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		}
		if (!$table->hasColumn('updated_at')) {
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		}
		if (!$table->hasIndex('advforms_log_due')) {
			$table->addIndex(['status', 'next_attempt_at'], 'advforms_log_due');
		}
		if (!$table->hasIndex('advforms_log_unique')) {
			$table->addUniqueIndex(['entry_id', 'action_id'], 'advforms_log_unique');
		}

		return $schema;
	}
}
