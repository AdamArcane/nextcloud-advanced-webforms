<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Actions;

use OCA\Webforms\Actions\Template;
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

	// ---- {all_fields} ----

	private const FORM = [
		'f_msg' => ['type' => 'textarea', 'label' => 'Message'],
		'f_name' => ['type' => 'text', 'label' => 'Name'],
		'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'sup', 'label' => 'Support']]],
		'f_tags' => ['type' => 'checkboxes', 'label' => 'Tags', 'options' => [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta']]],
		'f_ok' => ['type' => 'checkbox', 'label' => 'Agree'],
		'f_note' => ['type' => 'paragraph', 'text' => 'Read this'],
		'f_blank' => ['type' => 'text', 'label' => '  '],
		'f_skip' => ['type' => 'text', 'label' => 'Skipped'],
	];

	private const PAGES = [
		['id' => 'p_1', 'rows' => [
			['id' => 'r_1', 'cells' => [['id' => 'c_1', 'span' => 6, 'field' => 'f_name'], ['id' => 'c_2', 'span' => 6, 'field' => 'f_kind']]],
			['id' => 'r_2', 'cells' => [['id' => 'c_3', 'span' => 12, 'field' => 'f_note']]],
		]],
		['id' => 'p_2', 'rows' => [
			['id' => 'r_3', 'cells' => [['id' => 'c_4', 'span' => 12, 'field' => 'f_msg']]],
		]],
	];

	public function testAnswersFollowTheLayoutAndSkipWhatWasNotAnswered(): void {
		$data = ['f_msg' => "Line one\nLine two", 'f_name' => 'Ada', 'f_kind' => 'sup', 'f_tags' => ['a', 'b'], 'f_ok' => false, 'f_blank' => 'x', 'f_skip' => ''];
		$this->assertSame([
			['label' => 'Name', 'value' => 'Ada'],
			['label' => 'Kind', 'value' => 'Support'],
			['label' => 'Message', 'value' => "Line one\nLine two"],
			// Not in the layout: after the others, in the order of the definition.
			['label' => 'Tags', 'value' => 'Alpha, Beta'],
			['label' => 'Agree', 'value' => 'No'],
			// No label to show, so its id.
			['label' => 'f_blank', 'value' => 'x'],
		], Template::answers(self::FORM, $data, self::PAGES));
		$this->assertSame([], Template::answers(self::FORM, [], self::PAGES));
		$this->assertSame([], Template::answers(self::FORM, ['f_tags' => [], 'f_name' => null], self::PAGES));
	}

	public function testAnswersAsTextIndentAnAnswerOfSeveralLines(): void {
		$text = Template::answersAsText([
			['label' => 'Name', 'value' => 'Ada'],
			['label' => 'Message', 'value' => "Line one\r\nLine two"],
		]);
		$this->assertSame("Name: Ada\nMessage:\n  Line one\n  Line two", $text);
	}

	public function testAllFieldsIsOnlyFilledWhereItIsAskedFor(): void {
		$this->assertSame('x {all_fields}', Template::render('x {all_fields}', self::FORM, []));
		$this->assertSame('x LIST', Template::render('x {all_fields}', self::FORM, [], Template::TEXT, 'LIST'));
		$this->assertTrue(Template::usesAllFields('a {all_fields} b', self::FORM));
		$this->assertFalse(Template::usesAllFields('a {f_name} b', self::FORM));
	}

	public function testAFieldThatIsCalledAllFieldsKeepsItsOwnMeaning(): void {
		$fields = ['all_fields' => ['type' => 'text', 'label' => 'Odd']];
		$this->assertFalse(Template::usesAllFields('{all_fields}', $fields));
		$this->assertSame('mine', Template::render('{all_fields}', $fields, ['all_fields' => 'mine'], Template::TEXT, 'LIST'));
	}

	public function testHtmlEscapesEverythingAPersonTyped(): void {
		$answers = [['label' => 'Na<me', 'value' => "<script>alert(1)</script>\nsecond"]];
		$html = Template::renderHtml("Hi {f_name} & co\n{all_fields}\n<i>end</i> {nope}", self::FORM, ['f_name' => '<img src=x>'], $answers);
		$this->assertStringContainsString('Hi &lt;img src=x&gt; &amp; co<br>', $html);
		$this->assertStringContainsString('>Na&lt;me</th>', $html);
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;<br>', $html);
		$this->assertStringContainsString('&lt;i&gt;end&lt;/i&gt; {nope}', $html);
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringNotContainsString('<img', $html);
		$this->assertSame(1, substr_count($html, '<table'));
	}

	public function testHtmlHasNoTableWhenNothingWasAnswered(): void {
		$this->assertStringNotContainsString('<table', Template::renderHtml('Hi {all_fields}', self::FORM, [], []));
	}
}
