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
 * Archiving a form (docs/SCHEMA.md section 8.1): a timestamp, null for a form that is not archived.
 * It sits beside the status so that restoring a form brings back what it was.
 */
class Version000400Date20261115000000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('advforms_forms');
		if ($table->hasColumn('archived_at')) {
			return null;
		}
		$table->addColumn('archived_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		return $schema;
	}
}
