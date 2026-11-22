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
 */
class Version001100Date20261122120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$entries = $schema->getTable('advforms_entries');
		if ($entries->hasColumn('waitlisted')) {
			return null;
		}
		$entries->addColumn('waitlisted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		$entries->addIndex(['form_id', 'waitlisted'], 'advforms_ent_form_wait');
		return $schema;
	}
}
