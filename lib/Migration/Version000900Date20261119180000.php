<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Revision conflicts (docs/SCHEMA.md section 22.6): a counter raised by every draft save, and who saved last.
 */
class Version000900Date20261119180000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('advforms_forms');
		$changed = false;
		if (!$table->hasColumn('revision')) {
			$table->addColumn('revision', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 1]);
			$changed = true;
		}
		if (!$table->hasColumn('draft_saved_by')) {
			// Null for drafts saved before this column existed.
			$table->addColumn('draft_saved_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
