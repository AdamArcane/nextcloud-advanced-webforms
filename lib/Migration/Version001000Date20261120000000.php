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
 * Collaboration lifecycle (docs/SCHEMA.md section 22.7): a form that passed to a new owner whose Files and
 * Tables it cannot use is closed, and says so until it is published again.
 */
class Version001000Date20261120000000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('advforms_forms');
		if ($table->hasColumn('destinations_pending')) {
			return null;
		}
		$table->addColumn('destinations_pending', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		return $schema;
	}
}
