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
 * Sharing options (docs/SCHEMA.md section 18): a custom address beside the random token, and whether
 * other websites may show the form in a frame. They belong to the form, not to a published version.
 */
class Version000500Date20261115120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('advforms_forms');
		$changed = false;
		if (!$table->hasColumn('slug')) {
			$table->addColumn('slug', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addUniqueIndex(['slug'], 'advforms_forms_slug');
			$changed = true;
		}
		if (!$table->hasColumn('embed')) {
			// 0 = other sites may not frame the form, 1 = they may.
			$table->addColumn('embed', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$changed = true;
		}
		if (!$table->hasColumn('embed_origins')) {
			// JSON list of origins allowed to frame the form. Null or empty means any site.
			$table->addColumn('embed_origins', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
