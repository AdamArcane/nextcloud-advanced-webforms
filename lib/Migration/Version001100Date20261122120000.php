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
 * Waiting list (docs/SCHEMA.md section 3.2): a response over the form's limit can be kept on a waiting
 * list instead of refused. It has a column of its own because it changes what counts against the limit.
 * Also the table of templates people save from their own forms (section 13.4).
 */
class Version001100Date20261122120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		$entries = $schema->getTable('advforms_entries');
		if (!$entries->hasColumn('waitlisted')) {
			$entries->addColumn('waitlisted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$entries->addIndex(['form_id', 'waitlisted'], 'advforms_ent_form_wait');
			$changed = true;
		}

		if (!$schema->hasTable('advforms_templates')) {
			$table = $schema->createTable('advforms_templates');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('definition', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner_id'], 'advforms_tpl_owner');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
