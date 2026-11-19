<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\Destinations;
use PHPUnit\Framework\TestCase;

final class DestinationsTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private static function definition(): array {
		return [
			'title' => 'Intake',
			'fields' => [
				'name' => ['type' => 'text', 'label' => 'Name'],
				'cv' => ['type' => 'file', 'label' => 'CV', 'folder' => 'Hiring/CVs'],
				'photo' => ['type' => 'file', 'label' => 'Photo', 'storage' => 'app'],
				'logo' => ['type' => 'image', 'src' => '@files-placeholder'],
				'web' => ['type' => 'image', 'src' => 'https://example.com/a.png'],
			],
			'actions' => [
				['id' => 'a_mail', 'type' => 'email', 'to' => 'x@example.com'],
				['id' => 'a_csv', 'type' => 'files', 'path' => 'Hiring/answers.csv'],
				['id' => 'a_tab', 'type' => 'tables', 'tableId' => 5],
			],
		];
	}

	public function testOnlyPlacesInTheOwnersFilesAndTablesAreCounted(): void {
		$definition = self::definition();
		$definition['fields']['logo']['src'] = 'Pictures/logo.png';
		$set = Destinations::of($definition);
		self::assertSame(['actions.a_csv', 'actions.a_tab', 'fields.cv', 'fields.logo'], $this->sorted(array_keys($set)));
	}

	public function testAFileFieldKeptInTheAppIsNotADestination(): void {
		self::assertArrayNotHasKey('fields.photo', Destinations::of(self::definition()));
	}

	public function testAFileFieldWithNoStorageDefaultsToFiles(): void {
		$definition = ['fields' => ['cv' => ['type' => 'file', 'label' => 'CV']]];
		self::assertArrayHasKey('fields.cv', Destinations::of($definition));
	}

	public function testChangingOtherThingsIsNotAChange(): void {
		$before = self::definition();
		$after = self::definition();
		$after['title'] = 'Other';
		$after['fields']['name']['label'] = 'Full name';
		$after['fields']['photo']['maxFiles'] = 3;
		$after['actions'][0]['to'] = 'y@example.com';
		$after['fields']['extra'] = ['type' => 'text', 'label' => 'Extra'];
		self::assertSame([], Destinations::changes($before, $after));
	}

	public function testChangingAFolderIsAChange(): void {
		$after = self::definition();
		$after['fields']['cv']['folder'] = 'Private/Elsewhere';
		self::assertSame(['fields.cv'], Destinations::changes(self::definition(), $after));
	}

	public function testChangingWhoMayUploadIsAChange(): void {
		$after = self::definition();
		$after['fields']['cv']['audience'] = 'login';
		self::assertSame(['fields.cv'], Destinations::changes(self::definition(), $after));
	}

	public function testAddingAFileFieldKeptInFilesIsAChange(): void {
		$after = self::definition();
		$after['fields']['more'] = ['type' => 'file', 'label' => 'More'];
		self::assertSame(['fields.more'], Destinations::changes(self::definition(), $after));
	}

	public function testAddingAFileFieldKeptInTheAppIsNot(): void {
		$after = self::definition();
		$after['fields']['more'] = ['type' => 'file', 'label' => 'More', 'storage' => 'app'];
		self::assertSame([], Destinations::changes(self::definition(), $after));
	}

	public function testRemovingADestinationIsAChange(): void {
		$after = self::definition();
		unset($after['fields']['cv'], $after['actions'][1]);
		self::assertSame(['actions.a_csv', 'fields.cv'], Destinations::changes(self::definition(), $after));
	}

	public function testTurningAFileFieldIntoSomethingElseCountsAsRemovingIt(): void {
		$after = self::definition();
		$after['fields']['cv'] = ['type' => 'text', 'label' => 'CV'];
		self::assertSame(['fields.cv'], Destinations::changes(self::definition(), $after));
	}

	public function testAnActionOfTypeFilesOrTablesIsChangedAsAWhole(): void {
		$after = self::definition();
		$after['actions'][2]['tableId'] = 6;
		$after['actions'][1]['condition'] = ['all' => []];
		self::assertSame(['actions.a_csv', 'actions.a_tab'], Destinations::changes(self::definition(), $after));
	}

	public function testTheOrderOfKeysDoesNotMatter(): void {
		$after = self::definition();
		$after['actions'][1] = ['path' => 'Hiring/answers.csv', 'type' => 'files', 'id' => 'a_csv'];
		self::assertSame([], Destinations::changes(self::definition(), $after));
	}

	public function testAnActionKeptButMovedInTheListIsNotAChange(): void {
		$after = self::definition();
		$after['actions'] = array_reverse($after['actions']);
		self::assertSame([], Destinations::changes(self::definition(), $after));
	}

	/**
	 * @param list<int|string> $values
	 * @return list<string>
	 */
	private function sorted(array $values): array {
		$values = array_map('strval', $values);
		sort($values);
		return $values;
	}

	public function testActionsThatPointNowhereAreLeftOutOfWhatIsCheckedForANewOwner(): void {
		$cleaned = Destinations::withoutUnfinished([
			'actions' => [
				['id' => 'a_mail', 'type' => 'email', 'to' => 'x@example.com'],
				['id' => 'a_table', 'type' => 'tables', 'tableId' => 0, 'map' => []],
				['id' => 'a_csv', 'type' => 'files', 'path' => '  '],
				['id' => 'a_hook', 'type' => 'webhook', 'url' => 'https://example.com/h'],
				['id' => 'a_table2', 'type' => 'tables', 'tableId' => 12, 'map' => []],
				['id' => 'a_csv2', 'type' => 'files', 'path' => 'Hiring/answers.csv'],
			],
		]);
		self::assertSame(['a_mail', 'a_hook', 'a_table2', 'a_csv2'], array_column($cleaned['actions'], 'id'));
		self::assertSame([0, 3, 4, 5], array_keys($cleaned['actions']), 'the others keep their place, so error paths still match');
	}

	public function testADefinitionWithNoActionsIsUnchanged(): void {
		self::assertSame(['title' => 'x'], Destinations::withoutUnfinished(['title' => 'x']));
	}
}
