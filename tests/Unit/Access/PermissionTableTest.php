<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Access;

use OCA\Webforms\Service\ActionLogService;
use OCA\Webforms\Service\ApiService;
use OCA\Webforms\Service\EntryExportService;
use OCA\Webforms\Service\FormService;
use OCA\Webforms\Service\OwnershipService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Section 22.1 of docs/SCHEMA.md as data. Every method that touches a form on someone's behalf must ask
 * for the role it lists, so a new method with no role, or a changed one, fails here.
 */
final class PermissionTableTest extends TestCase {
	/** Methods of FormService that take a person and a form id. */
	private const FORM_SERVICE = [
		'exportForm' => 'EDITOR',
		'duplicateForm' => 'EDITOR',
		'saveAsTemplate' => 'EDITOR',
		'saveServerTemplate' => 'EDITOR',
		'saveDraft' => 'EDITOR',
		'publish' => 'EDITOR',
		'setShare' => 'EDITOR',
		'setStatus' => 'EDITOR',
		'setArchived' => 'EDITOR',
		'delete' => 'OWNER',
		'listEntries' => 'REVIEWER',
		'getEntry' => 'REVIEWER',
		'markResponsesSeen' => 'REVIEWER',
		'setEntryStatus' => 'REVIEWER',
		'setEntriesStatus' => 'REVIEWER',
		'setEntryNote' => 'REVIEWER',
		'summarizeEntries' => 'REVIEWER',
		'deleteEntry' => 'EDITOR',
		'deleteEntries' => 'EDITOR',
		'openEntryFile' => 'REVIEWER',
	];

	/** Public methods that do not name a form to act on (they list, create or build from the caller's own data). */
	private const FORM_SERVICE_OPEN = ['list', 'listForDisplay', 'create', 'importForm', 'listTemplates', 'createFromTemplate', 'deleteTemplate', 'listServerTemplates', 'setHiddenTemplates', 'renameServerTemplate', 'deleteServerTemplate', 'backup', 'get', 'toArray', 'destinationErrorsFor', 'holdsFilesInFiles'];

	private const ACTION_LOG = ['list' => 'EDITOR', 'retry' => 'EDITOR'];
	private const EXPORT = ['export' => 'REVIEWER'];
	private const OWNERSHIP = ['transfer' => 'OWNER'];

	/** The read-only API (section 23): everything it reads needs the Reviewer role, and nothing else. */
	private const API = [
		'form' => 'REVIEWER',
		'versions' => 'REVIEWER',
		'version' => 'REVIEWER',
		'responses' => 'REVIEWER',
		'response' => 'REVIEWER',
		'summary' => 'REVIEWER',
		'csv' => 'REVIEWER',
	];

	/** The API's one method that names no form: it lists the caller's own, as FormService::listForDisplay does. */
	private const API_OPEN = ['forms'];

	/** Methods that only hand over to one of the methods above, which asks for the role. */
	private const DELEGATES = [EntryExportService::class . '::csv' => EntryExportService::class . '::export'];

	/**
	 * @return iterable<string, array{class-string, string, string}>
	 */
	public static function methods(): iterable {
		foreach (self::FORM_SERVICE as $method => $role) {
			yield 'FormService::' . $method => [FormService::class, $method, $role];
		}
		foreach (self::ACTION_LOG as $method => $role) {
			yield 'ActionLogService::' . $method => [ActionLogService::class, $method, $role];
		}
		foreach (self::OWNERSHIP as $method => $role) {
			yield 'OwnershipService::' . $method => [OwnershipService::class, $method, $role];
		}
		foreach (self::API as $method => $role) {
			yield 'ApiService::' . $method => [ApiService::class, $method, $role];
		}
		foreach (self::EXPORT as $method => $role) {
			yield 'EntryExportService::' . $method => [EntryExportService::class, $method, $role];
		}
	}

	#[DataProvider('methods')]
	public function testEachMethodAsksForExactlyTheRoleInTheTable(string $class, string $method, string $role): void {
		$body = self::body($class, $method);
		preg_match_all('/Role::([A-Z]+)/', $body, $found);
		self::assertSame([$role], array_values(array_unique($found[1])), $class . '::' . $method . ' must ask for ' . $role . ' and nothing else');
	}

	public function testNoPublicFormServiceMethodIsLeftOutOfTheTable(): void {
		$undecided = [];
		foreach ((new \ReflectionClass(FormService::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isConstructor() || $method->isStatic()) {
				continue;
			}
			if (!isset(self::FORM_SERVICE[$method->getName()]) && !in_array($method->getName(), self::FORM_SERVICE_OPEN, true)) {
				$undecided[] = $method->getName();
			}
		}
		self::assertSame([], $undecided, 'A new public FormService method must be given a role in section 22.1 and in this table.');
	}

	public function testNoPublicApiServiceMethodIsLeftOutOfTheTable(): void {
		$undecided = [];
		foreach ((new \ReflectionClass(ApiService::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isConstructor() || $method->isStatic()) {
				continue;
			}
			if (!isset(self::API[$method->getName()]) && !in_array($method->getName(), self::API_OPEN, true)) {
				$undecided[] = $method->getName();
			}
		}
		self::assertSame([], $undecided, 'A new public ApiService method must be given a role in section 23 and in this table.');
	}

	public function testAMethodThatOnlyDelegatesCallsOneThatAsksForTheRole(): void {
		foreach (self::DELEGATES as $from => $to) {
			[$class, $method] = explode('::', $from);
			[, $target] = explode('::', $to);
			self::assertStringContainsString('$this->' . $target . '(', self::body($class, $method));
			self::assertStringNotContainsString('Role::', self::body($class, $method), 'A delegate must not decide for itself.');
		}
	}

	public function testGetCannotBeCalledWithoutSayingWhatIsNeeded(): void {
		$get = new \ReflectionMethod(FormService::class, 'get');
		$role = $get->getParameters()[2];
		self::assertSame('needs', $role->getName());
		self::assertFalse($role->isOptional());
	}

	private static function body(string $class, string $method): string {
		$reflection = new \ReflectionMethod($class, $method);
		$lines = file((string)$reflection->getFileName());
		self::assertIsArray($lines);
		return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
	}
}
