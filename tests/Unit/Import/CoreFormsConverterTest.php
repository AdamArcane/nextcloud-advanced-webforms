<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Import;

use OCA\Webforms\Forms\DefinitionValidator;
use OCA\Webforms\Import\CoreForms\ConversionResult;
use OCA\Webforms\Import\CoreForms\CoreFormsConverter;
use PHPUnit\Framework\TestCase;

class CoreFormsConverterTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private static function form(array $over = []): array {
		return $over + [
			'title' => 'Workshop signup',
			'description' => 'Tell us about you.',
			'submissionMessage' => 'Thanks, see you there.',
			'opens' => 0,
			'expires' => 0,
			'isAnonymous' => false,
			'submitMultiple' => true,
			'confirmationEmailEnabled' => false,
		];
	}

	/**
	 * @param list<array<string, mixed>> $options
	 * @return array<string, mixed>
	 */
	private static function q(int $id, string $type, string $text, array $extra = [], array $options = [], bool $required = false): array {
		return [
			'id' => $id,
			'order' => $id,
			'type' => $type,
			'isRequired' => $required,
			'text' => $text,
			'description' => '',
			'extraSettings' => $extra,
			'options' => $options,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function choices(int $firstId, string ...$texts): array {
		$options = [];
		foreach ($texts as $i => $text) {
			$options[] = ['id' => $firstId + $i, 'text' => $text, 'order' => $i + 1, 'optionType' => 'choice'];
		}
		return $options;
	}

	/**
	 * @param list<array{int, string}> $answers question id and text
	 * @return array<string, mixed>
	 */
	private static function submission(int $id, array $answers, ?string $user = 'jane', int $at = 1700000000): array {
		$list = [];
		foreach ($answers as $i => [$questionId, $text]) {
			$list[] = ['id' => $id * 100 + $i, 'questionId' => $questionId, 'text' => $text, 'fileId' => null];
		}
		return ['id' => $id, 'userId' => $user, 'timestamp' => $at, 'answers' => $list];
	}

	private static function codes(ConversionResult $result, string $scope): array {
		return array_values(array_map(
			static fn (array $item): string => $item['ref'] . ':' . $item['code'],
			array_filter($result->skipped, static fn (array $item): bool => $item['scope'] === $scope),
		));
	}

	public function testTheDefinitionPassesTheValidatorForEveryTypeThatHasAnEquivalent(): void {
		$questions = [
			self::q(1, 'short', 'Your name', [], [], true),
			self::q(2, 'short', 'E-mail', ['validationType' => 'email']),
			self::q(3, 'short', 'Phone', ['validationType' => 'phone']),
			self::q(4, 'short', 'Age', ['validationType' => 'number']),
			self::q(5, 'long', 'Anything else?'),
			self::q(6, 'multiple', 'Topics', ['optionsLimitMin' => 1, 'optionsLimitMax' => 2], self::choices(60, 'PHP', 'Vue', 'Nextcloud')),
			self::q(7, 'multiple_unique', 'Level', [], self::choices(70, 'Beginner', 'Advanced')),
			self::q(8, 'dropdown', 'City', [], self::choices(80, 'Hartford', 'Marlborough')),
			self::q(9, 'date', 'Day', ['dateMin' => '2026-01-01', 'dateMax' => 1798761600]),
			self::q(10, 'date', 'Stay', ['dateRange' => true]),
			self::q(11, 'time', 'Arrival', ['timeMin' => '08:00', 'timeMax' => '18:00']),
			self::q(12, 'datetime', 'Call back'),
			self::q(13, 'linearscale', 'Recommend us?', ['optionsLowest' => 0, 'optionsHighest' => 10, 'optionsLabelLowest' => 'No', 'optionsLabelHighest' => 'Yes']),
			self::q(14, 'linearscale', 'Rate it', ['questionType' => 'rating', 'optionsHighest' => 7]),
			self::q(15, 'color', 'Favourite colour'),
			self::q(16, 'file', 'Attach', ['maxAllowedFilesCount' => 3, 'maxFileSize' => 5 * 1048576, 'allowedFileTypes' => ['image', 'x-office/document'], 'allowedFileExtensions' => ['dwg']]),
			self::q(17, 'grid', 'Days you can come', ['questionType' => 'checkbox'], [
				['id' => 170, 'text' => 'Monday', 'order' => 1, 'optionType' => 'row'],
				['id' => 171, 'text' => 'Tuesday', 'order' => 2, 'optionType' => 'row'],
				['id' => 172, 'text' => 'Morning', 'order' => 1, 'optionType' => 'column'],
				['id' => 173, 'text' => 'Evening', 'order' => 2, 'optionType' => 'column'],
			]),
		];

		$result = (new CoreFormsConverter())->convert(self::form(['opens' => 1700000000, 'expires' => 1800000000]), $questions, []);

		$this->assertSame([], (new DefinitionValidator())->validate($result->definition));
		$fields = $result->definition['fields'];
		$this->assertCount(17, $fields);
		$types = array_column($fields, 'type');
		$this->assertSame(['text', 'email', 'phone', 'number', 'textarea', 'checkboxes', 'radio', 'select', 'date', 'daterange', 'time', 'datetime', 'scale', 'rating', 'color', 'file', 'matrix'], $types);
		$this->assertSame(1700000000, $result->definition['settings']['opensAt']);
		$this->assertSame(1800000000, $result->definition['settings']['closesAt']);
		$this->assertSame('message', $result->definition['actions'][0]['type']);
	}

	public function testFieldIdsAndOptionValuesAreMadeFromTheTextAndNeverClash(): void {
		$questions = [
			self::q(1, 'short', 'Name'),
			self::q(2, 'short', 'Name'),
			self::q(3, 'short', '???'),
			self::q(4, 'multiple_unique', 'Size', [], self::choices(40, '1 person', '1 person', 'Plain text', '')),
		];
		$result = (new CoreFormsConverter())->convert(self::form(), $questions, []);

		$this->assertSame(['f_name', 'f_name_2', 'f_q3', 'f_size'], array_keys($result->definition['fields']));
		$values = array_column($result->definition['fields']['f_size']['options'], 'value');
		$this->assertSame(['o_1_person', 'o_1_person_2', 'plain_text', 'o_4'], $values);
		$this->assertSame([], (new DefinitionValidator())->validate($result->definition));
		$this->assertSame([1 => 'f_name', 2 => 'f_name_2', 3 => 'f_q3', 4 => 'f_size'], $result->fieldIds);
	}

	public function testResponsesCarryOverKeyedByFieldIdWithTheRightShapes(): void {
		$questions = [
			self::q(1, 'short', 'Your name'),
			self::q(4, 'short', 'Age', ['validationType' => 'number']),
			self::q(6, 'multiple', 'Topics', [], self::choices(60, 'PHP', 'Vue', 'Nextcloud')),
			self::q(7, 'multiple_unique', 'Level', [], self::choices(70, 'Beginner', 'Advanced')),
			self::q(9, 'date', 'Day'),
			self::q(10, 'date', 'Stay', ['dateRange' => true]),
			self::q(11, 'time', 'Arrival'),
			self::q(12, 'datetime', 'Call back'),
			self::q(13, 'linearscale', 'Score', ['optionsHighest' => 5]),
			self::q(15, 'color', 'Colour'),
			self::q(17, 'grid', 'Days', ['questionType' => 'checkbox'], [
				['id' => 170, 'text' => 'Monday', 'order' => 1, 'optionType' => 'row'],
				['id' => 171, 'text' => 'Tuesday', 'order' => 2, 'optionType' => 'row'],
				['id' => 172, 'text' => 'Morning', 'order' => 1, 'optionType' => 'column'],
				['id' => 173, 'text' => 'Evening', 'order' => 2, 'optionType' => 'column'],
			]),
			self::q(18, 'grid', 'Pick one per row', ['questionType' => 'radio'], [
				['id' => 180, 'text' => 'Soup', 'order' => 1, 'optionType' => 'row'],
				['id' => 181, 'text' => 'Yes', 'order' => 1, 'optionType' => 'column'],
				['id' => 182, 'text' => 'No', 'order' => 2, 'optionType' => 'column'],
			]),
		];
		$submission = self::submission(1, [
			[1, '  Jane  '],
			[4, '42'],
			[6, 'PHP'],
			[6, 'nextcloud'],
			[7, 'Advanced'],
			[9, '2026-12-01'],
			[10, '2026-12-01'],
			[10, '2026-12-03'],
			[11, '09:30'],
			[12, '2026-12-01 14:15'],
			[13, '4'],
			[15, '#FF8800'],
			[17, '{"170":[172,173],"171":[173]}'],
			[18, '{"180":182}'],
		]);

		$result = (new CoreFormsConverter())->convert(self::form(), $questions, [$submission]);

		$this->assertCount(1, $result->entries);
		$entry = $result->entries[0];
		$this->assertSame('jane', $entry['userId']);
		$this->assertSame(1700000000, $entry['submittedAt']);
		$this->assertSame([
			'f_your_name' => 'Jane',
			'f_age' => 42,
			'f_topics' => ['php', 'nextcloud'],
			'f_level' => 'advanced',
			'f_day' => '2026-12-01',
			'f_stay' => ['start' => '2026-12-01', 'end' => '2026-12-03'],
			'f_arrival' => '09:30',
			'f_call_back' => '2026-12-01T14:15',
			'f_score' => 4,
			'f_colour' => '#ff8800',
			'f_days' => ['monday' => ['morning', 'evening'], 'tuesday' => ['evening']],
			'f_pick_one_per_row' => ['soup' => 'no'],
		], $entry['data']);
		$this->assertSame([], self::codes($result, 'answer'));
	}

	public function testWhatHasNoEquivalentIsLeftOutAndReported(): void {
		$questions = [
			self::q(1, 'ranking', 'Rank these', [], self::choices(10, 'A one', 'B two')),
			self::q(2, 'time', 'Window', ['timeRange' => true]),
			self::q(3, 'grid', 'Numbers', ['questionType' => 'number']),
			self::q(4, 'section', 'Part two'),
			self::q(5, 'multiple', 'Pick', ['allowOtherAnswer' => true, 'shuffleOptions' => true], self::choices(50, 'Red', 'Blue')),
			self::q(6, 'short', 'Code', ['validationType' => 'regex', 'validationRegex' => '^[A-Z]+$']),
			self::q(7, 'dropdown', 'Empty'),
			self::q(8, 'file', 'Upload'),
		];
		$submission = self::submission(1, [
			[1, '[10,11]'],
			[5, 'Red'],
			[5, 'system-other-answer:Green'],
			[8, 'photo.jpg'],
			[999, 'orphan'],
		]);

		$result = (new CoreFormsConverter())->convert(self::form(['confirmationEmailEnabled' => true, 'isAnonymous' => true]), $questions, [$submission]);

		$this->assertSame(['f_pick', 'f_code', 'f_upload'], array_keys($result->definition['fields']));
		$this->assertSame([], (new DefinitionValidator())->validate($result->definition));

		$question = self::codes($result, 'question');
		foreach (['1:ranking_unsupported', '2:time_range', '3:grid_numbers', '4:unsupported_type', '5:allow_other_answer', '5:shuffle_options', '6:validation_regex', '7:no_options', '8:files_not_carried'] as $expected) {
			$this->assertContains($expected, $question);
		}
		$form = self::codes($result, 'form');
		$this->assertContains('confirmationEmail:confirmation_email', $form);
		$this->assertContains('isAnonymous:anonymous_form', $form);
		$this->assertContains('access:access_not_carried', $form);

		$answer = self::codes($result, 'answer');
		$this->assertContains('1:question_not_carried', $answer);
		$this->assertContains('5:answer_not_an_option', $answer);
		$this->assertContains('8:files_not_carried', $answer);
		$this->assertContains('999:question_not_carried', $answer);
		$this->assertSame(['f_pick' => ['red']], $result->entries[0]['data']);
	}

	public function testAnAnonymousRespondentIsDroppedAndBadAnswersAreCountedNotStored(): void {
		$questions = [
			self::q(1, 'short', 'Age', ['validationType' => 'number']),
			self::q(2, 'linearscale', 'Score', ['optionsHighest' => 5]),
			self::q(3, 'date', 'Day'),
			self::q(4, 'color', 'Colour'),
		];
		$submissions = [
			self::submission(1, [[1, 'abc'], [2, '9'], [3, '2026-02-31'], [4, 'red']], 'anon-user-5f3a'),
			self::submission(2, [[1, '7']], null),
		];

		$result = (new CoreFormsConverter())->convert(self::form(), $questions, $submissions);

		$this->assertNull($result->entries[0]['userId']);
		$this->assertSame([], $result->entries[0]['data']);
		$this->assertSame(['f_age' => 7], $result->entries[1]['data']);
		$answer = self::codes($result, 'answer');
		foreach (['1:answer_invalid', '2:answer_out_of_range', '3:answer_invalid', '4:answer_invalid'] as $expected) {
			$this->assertContains($expected, $answer);
		}
	}

	public function testAQuestionShownOnlyAfterAnAnswerIsNotRequiredHere(): void {
		$sub = self::q(2, 'short', 'Which one?', [], [], true);
		$sub['parentQuestionId'] = 1;
		$result = (new CoreFormsConverter())->convert(self::form(), [self::q(1, 'short', 'Hello'), $sub], []);

		$this->assertArrayNotHasKey('required', $result->definition['fields']['f_which_one']);
		$this->assertContains('2:conditional_not_carried', self::codes($result, 'question'));
	}

	public function testAWindowThatEndsBeforeItOpensKeepsOnlyTheEnd(): void {
		$result = (new CoreFormsConverter())->convert(self::form(['opens' => 1800000000, 'expires' => 1700000000]), [self::q(1, 'short', 'A')], []);

		$this->assertArrayNotHasKey('opensAt', $result->definition['settings']);
		$this->assertSame(1700000000, $result->definition['settings']['closesAt']);
		$this->assertContains('opens:window_invalid', self::codes($result, 'form'));
		$this->assertSame([], (new DefinitionValidator())->validate($result->definition));
	}

	public function testAConverterCanBeUsedForSeveralForms(): void {
		$converter = new CoreFormsConverter();
		$converter->convert(self::form(['isAnonymous' => true]), [self::q(1, 'ranking', 'X', [], self::choices(1, 'a b', 'c d'))], []);
		$second = $converter->convert(self::form(), [self::q(1, 'short', 'Name')], []);

		$this->assertNotContains('isAnonymous:anonymous_form', self::codes($second, 'form'));
		$this->assertNotContains('1:ranking_unsupported', self::codes($second, 'question'));
	}
}
