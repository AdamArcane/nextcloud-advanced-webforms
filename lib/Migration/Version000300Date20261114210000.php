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
 * Files that were uploaded to an upload field but not yet submitted with their form
 * (docs/SCHEMA.md section 4.6). Rows are removed when the form is submitted, and by a
 * background job when they are never used.
 */
class Version000300Date20261114210000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('advforms_uploads')) {
			return null;
		}
		$table = $schema->createTable('advforms_uploads');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('form_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('field_id', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('mime', Types::STRING, ['notnull' => true, 'length' => 127]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token'], 'advforms_upl_token');
		$table->addIndex(['form_id'], 'advforms_upl_form');
		$table->addIndex(['created_at'], 'advforms_upl_created');
		return $schema;
	}
}
