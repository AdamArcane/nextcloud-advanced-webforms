<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Forms\DefinitionValidator;
use OCA\Webforms\Forms\FormPackage;
use PHPUnit\Framework\TestCase;

class FormPackageTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private static function definition(): array {
		return [
			'schemaVersion' => 1,
			'title' => 'Inquiry',
			'settings' => ['submitLabel' => 'Send'],
			'fields' => ['f_name' => ['type' => 'text', 'label' => 'Name']],
			'pages' => [['id' => 'p_1', 'title' => null, 'rows' => [['id' => 'r_1', 'cells' => [['id' => 'c_1', 'span' => 12, 'field' => 'f_name']]]]]],
			'rules' => [],
			'actions' => [
				['id' => 'act_1', 'type' => 'webhook', 'url' => 'https://example.com/hook', 'secretRef' => 'whsec_1'],
				['id' => 'act_2', 'type' => 'message', 'text' => 'Thanks'],
			],
		];
	}

	/**
	 * @return list<string>
	 */
	private static function codes(callable $action): array {
		try {
			$action();
		} catch (ValidationException $e) {
			return array_map(static fn (array $error): string => $error['code'], $e->getErrors());
		}
		return [];
	}

	public function testBuildWrapsTheDefinitionWithoutSecrets(): void {
		$package = FormPackage::build(self::definition(), FormPackage::SOURCE_DRAFT, 1700000000);

		$this->assertSame('webforms.form', $package['format']);
		$this->assertSame(1, $package['formatVersion']);
		$this->assertSame('draft', $package['source']);
		$this->assertSame(1700000000, $package['exportedAt']);
		$this->assertArrayNotHasKey('secretRef', $package['definition']['actions'][0]);
		$this->assertSame('https://example.com/hook', $package['definition']['actions'][0]['url']);
		$this->assertStringNotContainsString('whsec_1', json_encode($package, JSON_THROW_ON_ERROR));
	}

	public function testBuildKeepsEmptyMapsAsObjects(): void {
		$definition = self::definition();
		$definition['settings'] = [];
		$definition['fields'] = [];

		$json = json_encode(FormPackage::build($definition, FormPackage::SOURCE_DRAFT, 1), JSON_THROW_ON_ERROR);

		$this->assertStringContainsString('"settings":{}', $json);
		$this->assertStringContainsString('"fields":{}', $json);
	}

	public function testExportedFileParsesBack(): void {
		$definition = self::definition();
		$package = json_decode(json_encode(FormPackage::build($definition, FormPackage::SOURCE_PUBLISHED, 1), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

		$parsed = FormPackage::parse($package);

		unset($definition['actions'][0]['secretRef']);
		$this->assertSame($definition, $parsed);
	}

	public function testParseStripsSecretRefsFromAForeignFile(): void {
		$package = FormPackage::build(self::definition(), FormPackage::SOURCE_DRAFT, 1);
		$package['definition']['actions'][0]['secretRef'] = 'whsec_other_server';

		$parsed = FormPackage::parse($package);

		$this->assertArrayNotHasKey('secretRef', $parsed['actions'][0]);
	}

	public function testParseRefusesSomethingThatIsNotAFormFile(): void {
		$this->assertSame(['import_not_a_form'], self::codes(fn () => FormPackage::parse([])));
		$this->assertSame(['import_not_a_form'], self::codes(fn () => FormPackage::parse(['format' => 'other', 'formatVersion' => 1, 'definition' => self::definition()])));
		$this->assertSame(['import_not_a_form'], self::codes(fn () => FormPackage::parse(['format' => FormPackage::FORMAT, 'formatVersion' => '1', 'definition' => self::definition()])));
		$this->assertSame(['import_not_a_form'], self::codes(fn () => FormPackage::parse(['format' => FormPackage::FORMAT, 'formatVersion' => 1, 'definition' => 'x'])));
		$this->assertSame(['import_not_a_form'], self::codes(fn () => FormPackage::parse(['format' => FormPackage::FORMAT, 'formatVersion' => 1, 'definition' => [1, 2]])));
		$this->assertSame(['import_not_a_form'], self::codes(fn () => FormPackage::parse(['format' => FormPackage::FORMAT, 'formatVersion' => 1, 'definition' => ['title' => 'x']])));
	}

	public function testParseRefusesNewerFormats(): void {
		$package = FormPackage::build(self::definition(), FormPackage::SOURCE_DRAFT, 1);

		$newerFile = $package;
		$newerFile['formatVersion'] = FormPackage::FORMAT_VERSION + 1;
		$this->assertSame(['import_too_new'], self::codes(fn () => FormPackage::parse($newerFile)));

		$newerSchema = $package;
		$newerSchema['definition']['schemaVersion'] = DefinitionValidator::SCHEMA_VERSION + 1;
		$this->assertSame(['import_too_new'], self::codes(fn () => FormPackage::parse($newerSchema)));
	}
}
