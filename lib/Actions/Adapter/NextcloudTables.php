<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Adapter;

use OCA\Webforms\Actions\Ports\TablesFailure;
use OCA\Webforms\Actions\Ports\TablesPort;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Talks to the Tables app through its own services, as the form owner (docs/SCHEMA.md section 15).
 * Tables has no public PHP interface for writing, only the REST API (which needs a login session)
 * and these internal services, so every call is wrapped: a change in Tables shows up as a clear
 * failure in the delivery log, never as a failed submission.
 */
final class NextcloudTables implements TablesPort {
	private const NS = 'OCA\\Tables\\Service\\';

	public function __construct(
		private readonly IAppManager $apps,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		return $this->apps->isInstalled('tables') && class_exists(self::NS . 'TableService');
	}

	public function writableTables(string $userId): array {
		if (!$this->isAvailable()) {
			return [];
		}
		try {
			$permissions = $this->service('PermissionsService');
			$tables = [];
			foreach ($this->service('TableService')->findAll($userId, true, false, false) as $table) {
				if ($permissions->canCreateRows($table, 'table', $userId)) {
					$tables[] = ['id' => (int)$table->getId(), 'title' => (string)$table->getTitle(), 'emoji' => (string)$table->getEmoji()];
				}
			}
			return $tables;
		} catch (\Throwable $e) {
			throw $this->fail($e);
		}
	}

	public function columns(int $tableId, string $userId): array {
		try {
			$columns = [];
			foreach ($this->service('ColumnService')->findAllByTable($tableId, $userId) as $column) {
				$columns[] = [
					'id' => (int)$column->getId(),
					'title' => (string)$column->getTitle(),
					'type' => (string)$column->getType(),
					'subtype' => (string)$column->getSubtype(),
					'mandatory' => (bool)$column->getMandatory(),
					'options' => array_values(array_map(
						static fn (array $option): array => ['id' => (int)$option['id'], 'label' => (string)$option['label']],
						array_filter($column->getType() === 'selection' ? ($column->getSelectionOptionsArray() ?? []) : [], 'is_array'),
					)),
				];
			}
			return $columns;
		} catch (\Throwable $e) {
			throw $this->fail($e);
		}
	}

	public function addRow(int $tableId, array $values, string $userId): int {
		$data = [];
		foreach ($values as $columnId => $value) {
			$data[] = ['columnId' => $columnId, 'value' => $value];
		}
		try {
			return (int)$this->service('RowService')->create($tableId, null, $data, $userId)->getId();
		} catch (\Throwable $e) {
			throw $this->fail($e);
		}
	}

	public function createTable(string $title, array $columns, string $userId): array {
		throw new TablesFailure('tables_create_unsupported');
	}

	private function service(string $name): object {
		return $this->container->get(self::NS . $name);
	}

	/**
	 * Tables' own error types decide whether trying again can help.
	 */
	private function fail(\Throwable $e): TablesFailure {
		if ($e instanceof TablesFailure) {
			return $e;
		}
		$name = (new \ReflectionClass($e))->getShortName();
		if ($name === 'PermissionError') {
			return new TablesFailure('tables_forbidden');
		}
		if ($name === 'NotFoundError') {
			return new TablesFailure('tables_not_found');
		}
		if ($name === 'BadRequestError') {
			// Tables refused the values (a required column left empty, a number out of range).
			return new TablesFailure('tables_rejected', false, mb_substr($e->getMessage(), 0, 200));
		}
		$this->logger->warning('Advanced Webforms could not use Tables', ['exception' => $e, 'app' => 'webforms']);
		return new TablesFailure('tables_error', true, mb_substr($e->getMessage(), 0, 200));
	}
}
