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
 * Folders (docs/SCHEMA.md section 24): each person's folders, and which one they put a form in.
 */
class Version001300Date20261125000000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('advforms_folders')) {
			$table = $schema->createTable('advforms_folders');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 100]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'advforms_fold_user');
			$changed = true;
		}

		if (!$schema->hasTable('advforms_folder_forms')) {
			$table = $schema->createTable('advforms_folder_forms');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('folder_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['form_id', 'user_id'], 'advforms_ff_pair');
			$table->addIndex(['user_id'], 'advforms_ff_user');
			$table->addIndex(['folder_id'], 'advforms_ff_folder');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
