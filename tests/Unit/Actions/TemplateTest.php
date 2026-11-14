<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Tests\Unit\Actions;

use OCA\AdvancedForms\Actions\Template;
use PHPUnit\Framework\TestCase;

class TemplateTest extends TestCase {
	private const FIELDS = [
		'f_name' => ['type' => 'text', 'label' => 'Name'],
		'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'new', 'label' => 'New website'], ['value' => 'support', 'label' => 'Support']]],
		'f_tags' => ['type' => 'checkboxes', 'label' => 'Tags', 'options' => [['value' => 'aaa', 'label' => 'Alpha'], ['value' => 'bbb', 'label' => 'Beta']]],
		'f_ok' => ['type' => 'checkbox', 'label' => 'OK'],
		'f_age' => ['type' => 'number', 'label' => 'Age'],
	];

	public function testSubstitutesAnswersAsPlainText(): void {
		$out = Template::render('Hi {f_name}, you chose {f_kind}.', self::FIELDS, ['f_name' => 'Ada', 'f_kind' => 'support']);
		$this->assertSame('Hi Ada, you chose Support.', $out);
	}

	public function testShowsOptionLabelsAndJoinsLists(): void {
		$this->assertSame('Alpha, Beta', Template::render('{f_tags}', self::FIELDS, ['f_tags' => ['aaa', 'bbb']]));
	}

	public function testFormatsBooleansAndNumbers(): void {
		$this->assertSame('Yes', Template::render('{f_ok}', self::FIELDS, ['f_ok' => true]));
		$this->assertSame('No', Template::render('{f_ok}', self::FIELDS, ['f_ok' => false]));
		$this->assertSame('42', Template::render('{f_age}', self::FIELDS, ['f_age' => 42.0]));
		$this->assertSame('3.5', Template::render('{f_age}', self::FIELDS, ['f_age' => 3.5]));
	}

	public function testMissingAnswersBecomeEmpty(): void {
		$this->assertSame('Hi !', Template::render('Hi {f_name}!', self::FIELDS, []));
	}

	public function testUnknownPlaceholdersAreLeftAlone(): void {
		$this->assertSame('{nope} Ada', Template::render('{nope} {f_name}', self::FIELDS, ['f_name' => 'Ada']));
	}

	public function testDoesNotInterpretAnswersAsTemplates(): void {
		$out = Template::render('{f_name}', self::FIELDS + ['f_other' => ['type' => 'text']], ['f_name' => '{f_other}', 'f_other' => 'SECRET']);
		$this->assertSame('{f_other}', $out);
	}

	public function testSubjectCannotInjectHeaders(): void {
		$out = Template::render('Re: {f_name}', self::FIELDS, ['f_name' => "Ada\r\nBcc: evil@example.com"], Template::SUBJECT);
		$this->assertStringNotContainsString("\n", $out);
		$this->assertStringNotContainsString("\r", $out);
		$this->assertSame('Re: Ada Bcc: evil@example.com', $out);
	}

	public function testUrlValuesAreEncoded(): void {
		$out = Template::render('https://example.com/thanks?n={f_name}', self::FIELDS, ['f_name' => 'A&b=c d/é'], Template::URL);
		$this->assertSame('https://example.com/thanks?n=A%26b%3Dc%20d%2F%C3%A9', $out);
	}

	public function testUploadedFilesShowTheirNamesOnly(): void {
		$field = ['type' => 'file', 'label' => 'CV'];
		$value = [
			['name' => 'cv.pdf', 'size' => 10, 'mime' => 'application/pdf', 'storage' => 'files', 'ref' => '12'],
			['name' => 'letter.docx', 'size' => 20, 'mime' => 'x', 'storage' => 'app', 'ref' => 'tok'],
		];
		$this->assertSame('cv.pdf, letter.docx', Template::display($field, $value));
		$this->assertSame('Files: cv.pdf, letter.docx', Template::render('Files: {f_cv}', ['f_cv' => $field], ['f_cv' => $value]));
	}
}
