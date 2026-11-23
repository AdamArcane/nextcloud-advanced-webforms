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
 * A saved template has a description of its own (docs/SCHEMA.md section 13.4), shown under its name in the
 * New form chooser. It describes the template, so it is not the form's description that respondents read.
 * The column is nullable with an empty default: Nextcloud refuses a NOT NULL text column whose default is an empty
 * string (it would be NULL on Oracle). Code reads a missing value as empty.
 */
class Version001200Date20261123120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('advforms_templates')) {
			return null;
		}
		$table = $schema->getTable('advforms_templates');
		if ($table->hasColumn('description')) {
			return null;
		}
		$table->addColumn('description', Types::STRING, ['notnull' => false, 'length' => 500, 'default' => '']);
		return $schema;
	}
}
