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
 * A password on the public link (docs/SCHEMA.md section 18.5): the hash of it, never the password.
 */
class Version000700Date20261119000000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$forms = $schema->getTable('advforms_forms');
		if ($forms->hasColumn('link_password')) {
			return null;
		}
		// Null means the link has no password, which is how every form that already exists starts.
		$forms->addColumn('link_password', Types::STRING, ['notnull' => false, 'length' => 255]);
		return $schema;
	}
}
