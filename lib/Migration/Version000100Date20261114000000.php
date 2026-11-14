<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Adam and Advanced Forms contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema, see docs/SCHEMA.md.
 *
 * There are no foreign keys on purpose (Nextcloud convention). Deleting a form
 * must delete its versions, entries, and action log in application code.
 */
class Version000100Date20261114000000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('advforms_forms')) {
			$table = $schema->createTable('advforms_forms');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			// Mutable draft definition (JSON).
			$table->addColumn('draft', Types::TEXT, ['notnull' => true]);
			// JSON list of field IDs that were deleted and must never be reused.
			$table->addColumn('retired_ids', Types::TEXT, ['notnull' => false]);
			// 0 = unpublished, 1 = published, 2 = closed.
			$table->addColumn('status', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('public_token', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('latest_version_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner_id'], 'advforms_forms_owner');
			$table->addUniqueIndex(['public_token'], 'advforms_forms_token');
		}

		if (!$schema->hasTable('advforms_versions')) {
			$table = $schema->createTable('advforms_versions');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('version_number', Types::INTEGER, ['notnull' => true]);
			// Version of the definition format (schemaVersion in docs/SCHEMA.md).
			$table->addColumn('schema_version', Types::SMALLINT, ['notnull' => true]);
			// Immutable published snapshot (JSON).
			$table->addColumn('definition', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['form_id', 'version_number'], 'advforms_ver_form_num');
		}

		if (!$schema->hasTable('advforms_entries')) {
			$table = $schema->createTable('advforms_entries');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Set only for logged-in respondents.
			$table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			// JSON keyed by field ID, visible fields only.
			$table->addColumn('data', Types::TEXT, ['notnull' => true]);
			$table->addColumn('submitted_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['form_id', 'submitted_at'], 'advforms_ent_form_time');
		}

		if (!$schema->hasTable('advforms_action_log')) {
			$table = $schema->createTable('advforms_action_log');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('entry_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('action_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// queued, success, failed.
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('attempt', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['entry_id'], 'advforms_log_entry');
			$table->addIndex(['form_id'], 'advforms_log_form');
		}

		return $schema;
	}
}
