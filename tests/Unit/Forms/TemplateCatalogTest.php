<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Forms\DefinitionValidator;
use OCA\Webforms\Forms\FormPackage;
use OCA\Webforms\Forms\TemplateCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TemplateCatalogTest extends TestCase {
	public function testItListsTheStarterTemplates(): void {
		$all = (new TemplateCatalog())->all();
		$this->assertGreaterThanOrEqual(6, count($all));
		$ids = array_column($all, 'id');
		$this->assertSame(count($ids), count(array_unique($ids)));
		foreach ($all as $item) {
			$this->assertStringStartsWith('b:', $item['id']);
			$this->assertNotSame('', $item['name']);
			$this->assertGreaterThan(0, $item['fieldCount']);
		}
	}

	public function testEveryStarterTemplateCanBePublishedAsItIs(): void {
		$catalog = new TemplateCatalog();
		foreach ($catalog->all() as $item) {
			$definition = $catalog->definition($item['id']);
			$this->assertSame([], (new DefinitionValidator())->validate($definition), $item['id']);
			foreach (array_keys($definition['fields']) as $fieldId) {
				$this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{2,31}$/', $fieldId, $item['id']);
			}
		}
	}

	public function testNoStarterTemplateCarriesASecret(): void {
		$catalog = new TemplateCatalog();
		foreach ($catalog->all() as $item) {
			$definition = $catalog->definition($item['id']);
			$this->assertSame($definition, FormPackage::withoutSecrets($definition), $item['id']);
			foreach ($definition['actions'] as $action) {
				$this->assertNotSame('webhook', $action['type'] ?? null, $item['id']);
			}
		}
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function unknown(): iterable {
		yield 'no prefix' => ['contact'];
		yield 'someone else\'s kind' => ['u:1'];
		yield 'missing' => ['b:nothing_like_it'];
		yield 'a path' => ['b:../contact'];
		yield 'empty' => [''];
	}

	#[DataProvider('unknown')]
	public function testAnUnknownIdIsNotFound(string $id): void {
		$this->expectException(NotFoundException::class);
		(new TemplateCatalog())->definition($id);
	}

	public function testHiddenStartersAreLeftOutOfTheList(): void {
		$all = (new TemplateCatalog())->all();
		$shown = TemplateCatalog::withoutHidden($all, ['b:contact', 'b:not_a_template']);
		$this->assertCount(count($all) - 1, $shown);
		$this->assertNotContains('b:contact', array_column($shown, 'id'));
		$this->assertSame($all, TemplateCatalog::withoutHidden($all, []));
	}
}
