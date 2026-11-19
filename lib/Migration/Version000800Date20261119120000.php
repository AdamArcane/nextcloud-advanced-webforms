<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Collaboration (docs/SCHEMA.md section 22.2): forms shared with accounts and groups, what each person
 * has seen of a form's responses, and who published a version.
 */
class Version000800Date20261119120000 extends SimpleMigrationStep {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('advforms_form_shares')) {
			$table = $schema->createTable('advforms_form_shares');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// 0 for an account, 1 for a group: the same numbers as OCP\Share\IShare.
			$table->addColumn('share_type', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('share_with', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['form_id', 'share_type', 'share_with'], 'advforms_share_target');
			$table->addIndex(['share_type', 'share_with'], 'advforms_share_with');
			$changed = true;
		}

		if (!$schema->hasTable('advforms_form_seen')) {
			$table = $schema->createTable('advforms_form_seen');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('seen_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['form_id', 'user_id'], 'advforms_seen_pair');
			$table->addIndex(['user_id'], 'advforms_seen_user');
			$changed = true;
		}

		$versions = $schema->getTable('advforms_versions');
		if (!$versions->hasColumn('published_by')) {
			// Null for versions published before this column existed.
			$versions->addColumn('published_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	/**
	 * What each owner had seen is now kept in the new table. Forms never opened have no value and so no row,
	 * which is how they started: all their responses are new.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'owner_id', 'responses_seen_at')->from('advforms_forms')
			->where($select->expr()->isNotNull('responses_seen_at'));
		$result = $select->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$exists = $this->db->getQueryBuilder();
			$exists->select($exists->func()->count('*'))->from('advforms_form_seen')
				->where($exists->expr()->eq('form_id', $exists->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($exists->expr()->eq('user_id', $exists->createNamedParameter((string)$row['owner_id'])));
			if ((int)$exists->executeQuery()->fetchOne() > 0) {
				continue;
			}
			$insert = $this->db->getQueryBuilder();
			$insert->insert('advforms_form_seen')->values([
				'form_id' => $insert->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT),
				'user_id' => $insert->createNamedParameter((string)$row['owner_id']),
				'seen_at' => $insert->createNamedParameter((int)$row['responses_seen_at'], IQueryBuilder::PARAM_INT),
			]);
			$insert->executeStatement();
		}
		$result->closeCursor();
	}
}
