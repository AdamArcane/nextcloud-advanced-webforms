<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\ImageSource;
use OCA\Webforms\Forms\ReadingCopy;
use PHPUnit\Framework\TestCase;

final class ReadingCopyTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private static function definition(): array {
		return [
			'schemaVersion' => 1,
			'title' => 'Intake',
			'description' => 'For new hires',
			'settings' => ['closedMessage' => 'private wording', 'captcha' => true],
			'fields' => [
				'name' => ['type' => 'text', 'label' => 'Name', 'maxLength' => 80],
				'cv' => ['type' => 'file', 'label' => 'CV', 'maxFiles' => 2, 'audience' => 'anyone', 'storage' => 'files', 'folder' => 'Hiring/CVs'],
				'ref' => ['type' => 'hidden', 'label' => 'Source', 'description' => 'Owner note: filled by the campaign link', 'defaultValue' => 'web'],
				'logo' => ['type' => 'image', 'src' => 'Pictures/private/logo.png', 'alt' => 'Logo'],
				'web' => ['type' => 'image', 'src' => 'https://example.com/a.png', 'alt' => 'Web'],
			],
			'pages' => [['id' => 'p_1', 'title' => null, 'rows' => []]],
			'rules' => [['id' => 'r_1']],
			'actions' => [
				['id' => 'a_mail', 'type' => 'email', 'to' => 'hr@example.com'],
				['id' => 'a_hook', 'type' => 'webhook', 'url' => 'https://hooks.example.com/secret-path', 'secretRef' => 'ref1'],
			],
		];
	}

	public function testItKeepsWhatIsNeededToShowAnswersUnderTheirQuestions(): void {
		$copy = ReadingCopy::of(self::definition());
		self::assertSame('Intake', $copy['title']);
		self::assertSame(self::definition()['pages'], $copy['pages']);
		self::assertSame(self::definition()['rules'], $copy['rules']);
		self::assertSame(['type' => 'text', 'label' => 'Name', 'maxLength' => 80], $copy['fields']['name']);
	}

	public function testItLeavesOutTheActionsAndTheSettings(): void {
		$copy = ReadingCopy::of(self::definition());
		self::assertArrayNotHasKey('actions', $copy);
		self::assertArrayNotHasKey('settings', $copy);
		$text = json_encode($copy, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('hr@example.com', $text);
		self::assertStringNotContainsString('hooks.example.com', $text);
		self::assertStringNotContainsString('secretRef', $text);
		self::assertStringNotContainsString('private wording', $text);
	}

	public function testItLeavesOutWhereFilesAreKept(): void {
		$field = ReadingCopy::of(self::definition())['fields']['cv'];
		self::assertSame(['type' => 'file', 'label' => 'CV', 'maxFiles' => 2], $field);
	}

	public function testItLeavesOutAHiddenFieldsNote(): void {
		$field = ReadingCopy::of(self::definition())['fields']['ref'];
		self::assertArrayNotHasKey('description', $field);
		self::assertSame('web', $field['defaultValue']);
	}

	public function testAPictureInFilesBecomesTheMarkerAndAWebPictureStays(): void {
		$fields = ReadingCopy::of(self::definition())['fields'];
		self::assertSame(ImageSource::FILES_MARKER, $fields['logo']['src']);
		self::assertSame('https://example.com/a.png', $fields['web']['src']);
		self::assertStringNotContainsString('private', json_encode($fields, JSON_THROW_ON_ERROR));
	}

	public function testAKeyItDoesNotKnowIsLeftOutUntilSomeoneDecidesItIsSafe(): void {
		$definition = self::definition();
		$definition['internalNote'] = 'do not show';
		$definition['fields']['name']['costCentre'] = 'secret-42';
		$text = json_encode(ReadingCopy::of($definition), JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('do not show', $text);
		self::assertStringNotContainsString('secret-42', $text);
	}

	public function testEveryTopLevelKeyOfADefinitionIsDecidedOn(): void {
		$known = (new \ReflectionClassConstant(\OCA\Webforms\Forms\DefinitionValidator::class, 'TOP_KEYS'))->getValue();
		$dropped = ['settings', 'actions'];
		$undecided = array_diff($known, ReadingCopy::KEPT_TOP, $dropped);
		self::assertSame([], array_values($undecided), 'A new top level key of the definition must be added to ReadingCopy::KEPT_TOP, or listed here as dropped.');
	}

	public function testNonArrayFieldsPassThroughWithoutBreaking(): void {
		$copy = ReadingCopy::of(['title' => 'x', 'fields' => ['bad' => 'oops']]);
		self::assertSame('oops', $copy['fields']['bad']);
	}
}
