<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Import\CoreForms;

use OCA\Webforms\Forms\DefinitionValidator;

/**
 * Turns one form of the core Nextcloud Forms app into a draft definition and its responses
 * (docs/SCHEMA.md section 13.3).
 *
 * It knows nothing about the database: it takes the form, questions and submissions as plain arrays in
 * the shape core Forms documents for its API, which `CoreFormsReader` builds from the tables. Anything
 * that has no equivalent here is left out and reported, never guessed at.
 *
 * Input shapes:
 *  - form: title, description, submissionMessage, opens, expires, isAnonymous, submitMultiple,
 *    confirmationEmailEnabled
 *  - question: id, order, type, isRequired, text, description, extraSettings, parentQuestionId (optional),
 *    options (each: id, text, order, optionType)
 *  - submission: id, userId, timestamp, answers (each: questionId, text, fileId)
 */
final class CoreFormsConverter {
	private const ANONYMOUS_PREFIX = 'anon-user-';

	/** @var list<array{scope: string, ref: string, label: string, code: string, detail: string, count: int}> */
	private array $skipped = [];

	/** @var array<string, int> how many answers each `answer` report line stands for */
	private array $answerCounts = [];

	/** @var array<string, string> question id => its text, so the report can name the question */
	private array $labels = [];

	/**
	 * @param array<string, mixed> $form
	 * @param list<array<string, mixed>> $questions
	 * @param list<array<string, mixed>> $submissions
	 */
	public function convert(array $form, array $questions, array $submissions): ConversionResult {
		$this->skipped = [];
		$this->answerCounts = [];
		$this->labels = [];
		foreach ($questions as $question) {
			$this->labels[(string)($question['id'] ?? '')] = mb_substr(trim((string)($question['text'] ?? '')), 0, 80);
		}

		usort($questions, static fn (array $a, array $b): int => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));

		$usedIds = [];
		$fields = [];
		/** @var array<int, array<string, mixed>> $meta */
		$meta = [];
		foreach ($questions as $question) {
			$qid = (int)($question['id'] ?? 0);
			if (count($fields) >= DefinitionValidator::MAX_FIELDS) {
				$this->skip('question', (string)$qid, 'too_many_fields', 'A form holds at most ' . DefinitionValidator::MAX_FIELDS . ' fields.');
				continue;
			}
			$converted = $this->question($question, $usedIds);
			if ($converted === null) {
				$meta[$qid] = ['kind' => 'skipped'];
				continue;
			}
			$fieldId = $converted['id'];
			$usedIds[$fieldId] = true;
			$fields[$fieldId] = $converted['field'];
			$meta[$qid] = $converted['meta'] + ['id' => $fieldId];
		}

		$definition = $this->definition($form, $fields);

		$entries = [];
		foreach ($submissions as $submission) {
			$entries[] = $this->entry($submission, $meta);
		}

		$fieldIds = [];
		foreach ($meta as $qid => $m) {
			if (isset($m['id'])) {
				$fieldIds[$qid] = $m['id'];
			}
		}

		return new ConversionResult($definition, $entries, $this->report(), $fieldIds);
	}

	/**
	 * @param array<string, mixed> $form
	 * @param array<string, array<string, mixed>> $fields
	 * @return array<string, mixed>
	 */
	private function definition(array $form, array $fields): array {
		$title = trim((string)($form['title'] ?? ''));
		$title = $title === '' ? 'Imported form' : mb_substr($title, 0, 255);

		$settings = ['submitLabel' => 'Submit'];
		if (array_key_exists('submitMultiple', $form) && $form['submitMultiple'] !== null) {
			$settings['allowMultipleSubmissions'] = (bool)$form['submitMultiple'];
		}
		$opens = (int)($form['opens'] ?? 0);
		$expires = (int)($form['expires'] ?? 0);
		if ($expires > 0) {
			$settings['closesAt'] = $expires;
		}
		if ($opens > 0) {
			if ($expires > 0 && $expires <= $opens) {
				$this->skip('form', 'opens', 'window_invalid', 'The opening time is not before the closing time, so only the closing time was kept.');
			} else {
				$settings['opensAt'] = $opens;
			}
		}

		$definition = [
			'schemaVersion' => DefinitionValidator::SCHEMA_VERSION,
			'title' => $title,
			'settings' => $settings,
			'fields' => $fields,
			'pages' => [['id' => 'p_1', 'title' => null, 'rows' => $this->rows(array_keys($fields))]],
			'rules' => [],
			'actions' => [],
		];

		$description = trim((string)($form['description'] ?? ''));
		if ($description !== '') {
			$definition['description'] = mb_substr($description, 0, 500);
			if (mb_strlen($description) > 500) {
				$this->skip('form', 'description', 'text_cut', 'The description was cut to 500 characters.');
			}
		}

		$message = trim((string)($form['submissionMessage'] ?? ''));
		if ($message !== '') {
			$definition['actions'][] = ['id' => 'act_1', 'type' => 'message', 'text' => mb_substr($message, 0, 500)];
		}

		if (!empty($form['confirmationEmailEnabled'])) {
			$this->skip('form', 'confirmationEmail', 'confirmation_email', 'The confirmation email was not carried over. Add an email action to the draft.');
		}
		if (!empty($form['isAnonymous'])) {
			$this->skip('form', 'isAnonymous', 'anonymous_form', 'The form hid who answered. Responses were imported without a respondent.');
		}
		$this->skip('form', 'access', 'access_not_carried', 'Sharing and access settings were not carried over. The form is an unpublished draft; set who may answer before publishing.');

		return $definition;
	}

	/**
	 * @param list<string> $fieldIds
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $fieldIds): array {
		$rows = [];
		foreach (array_values($fieldIds) as $i => $fieldId) {
			$n = $i + 1;
			$rows[] = ['id' => 'r_' . $n, 'cells' => [['id' => 'c_' . $n, 'span' => 12, 'field' => $fieldId]]];
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $q
	 * @param array<string, true> $usedIds
	 * @return array{id: string, field: array<string, mixed>, meta: array<string, mixed>}|null
	 */
	private function question(array $q, array $usedIds): ?array {
		$qid = (string)($q['id'] ?? '');
		$type = (string)($q['type'] ?? '');
		$extra = is_array($q['extraSettings'] ?? null) ? $q['extraSettings'] : [];
		$options = is_array($q['options'] ?? null) ? $q['options'] : [];
		$label = trim((string)($q['text'] ?? ''));
		if ($label === '') {
			$label = 'Question ' . $qid;
		}

		$field = ['label' => mb_substr($label, 0, 255)];
		$description = trim((string)($q['description'] ?? ''));
		if ($description !== '') {
			$field['description'] = mb_substr($description, 0, 500);
		}
		$required = !empty($q['isRequired']);
		if (isset($q['parentQuestionId']) && $q['parentQuestionId'] !== null) {
			// It was only shown for one answer of another question. Always shown and required would be wrong.
			$required = false;
			$this->skip('question', $qid, 'conditional_not_carried', 'This question was only shown after a certain answer. It is now always shown and not required.');
		}
		if ($required) {
			$field['required'] = true;
		}

		$meta = [];
		switch ($type) {
			case 'short':
				$validation = $extra['validationType'] ?? null;
				$field['type'] = match ($validation) {
					'email' => 'email',
					'phone' => 'phone',
					'number' => 'number',
					default => 'text',
				};
				$meta['kind'] = $validation === 'number' ? 'number' : 'text';
				if ($validation === 'regex') {
					$this->skip('question', $qid, 'validation_regex', 'The pattern check has no equivalent. It is a plain text field.');
				}
				break;
			case 'long':
				$field['type'] = 'textarea';
				$meta['kind'] = 'text';
				break;
			case 'multiple':
				if (!$this->choices($options, $field, $meta, $qid, $usedIds)) {
					return null;
				}
				$field['type'] = 'checkboxes';
				$meta['kind'] = 'multi';
				foreach (['optionsLimitMin' => 'minSelected', 'optionsLimitMax' => 'maxSelected'] as $from => $to) {
					if (isset($extra[$from]) && (int)$extra[$from] > 0) {
						$field[$to] = (int)$extra[$from];
					}
				}
				$this->choiceExtras($extra, $qid);
				break;
			case 'multiple_unique':
			case 'dropdown':
				if (!$this->choices($options, $field, $meta, $qid, $usedIds)) {
					return null;
				}
				$field['type'] = $type === 'dropdown' ? 'select' : 'radio';
				$meta['kind'] = 'single';
				$this->choiceExtras($extra, $qid);
				break;
			case 'date':
				if (!empty($extra['dateRange'])) {
					$field['type'] = 'daterange';
					$meta['kind'] = 'daterange';
				} else {
					$field['type'] = 'date';
					$meta['kind'] = 'date';
				}
				foreach (['dateMin' => 'min', 'dateMax' => 'max'] as $from => $to) {
					$day = $this->day($extra[$from] ?? null);
					if ($day !== null) {
						$field[$to] = $day;
					}
				}
				break;
			case 'time':
				if (!empty($extra['timeRange'])) {
					$this->skip('question', $qid, 'time_range', 'A time range has no equivalent, so the question was left out.');
					return null;
				}
				$field['type'] = 'time';
				$meta['kind'] = 'time';
				foreach (['timeMin' => 'min', 'timeMax' => 'max'] as $from => $to) {
					$time = $this->clock($extra[$from] ?? null);
					if ($time !== null) {
						$field[$to] = $time;
					}
				}
				break;
			case 'datetime':
				$field['type'] = 'datetime';
				$meta['kind'] = 'datetime';
				break;
			case 'linearscale':
				$high = isset($extra['optionsHighest']) ? (int)$extra['optionsHighest'] : (isset($extra['maxRating']) ? (int)$extra['maxRating'] : 5);
				if (($extra['questionType'] ?? null) === 'rating') {
					$field['type'] = 'rating';
					$field['max'] = max(3, min(10, $high));
					$meta['kind'] = 'number';
					$meta['min'] = 1;
					$meta['max'] = $field['max'];
					break;
				}
				$low = isset($extra['optionsLowest']) && (int)$extra['optionsLowest'] === 0 ? 0 : 1;
				$high = max(2, min(10, $high));
				$field['type'] = 'scale';
				$field['min'] = $low;
				$field['max'] = $high;
				foreach (['optionsLabelLowest' => 'minLabel', 'optionsLabelHighest' => 'maxLabel'] as $from => $to) {
					$text = trim((string)($extra[$from] ?? ''));
					if ($text !== '') {
						$field[$to] = mb_substr($text, 0, 50);
					}
				}
				$meta['kind'] = 'number';
				$meta['min'] = $low;
				$meta['max'] = $high;
				break;
			case 'color':
				$field['type'] = 'color';
				$meta['kind'] = 'color';
				break;
			case 'file':
				$field['type'] = 'file';
				$count = (int)($extra['maxAllowedFilesCount'] ?? 0);
				$field['maxFiles'] = $count < 1 ? 10 : min(10, $count);
				$bytes = (int)($extra['maxFileSize'] ?? 0);
				$field['maxSizeMb'] = $bytes < 1 ? 100 : max(1, min(100, (int)ceil($bytes / 1048576)));
				$accept = $this->accept($extra);
				if ($accept !== []) {
					$field['accept'] = $accept;
				}
				$meta['kind'] = 'file';
				$this->skip('question', $qid, 'files_not_carried', 'The question was kept, but uploaded files stay in core Forms and are not copied.');
				break;
			case 'grid':
				if (!$this->grid($options, $extra, $field, $meta, $qid, $usedIds)) {
					return null;
				}
				break;
			case 'ranking':
				$this->skip('question', $qid, 'ranking_unsupported', 'Ranking has no equivalent, so the question and its answers were left out.');
				return null;
			default:
				$this->skip('question', $qid, 'unsupported_type', 'The question type "' . $type . '" has no equivalent, so it was left out.');
				return null;
		}

		return ['id' => $this->fieldId($label, (int)$qid, $usedIds), 'field' => $field, 'meta' => $meta];
	}

	/**
	 * @param list<array<string, mixed>> $options
	 * @param array<string, mixed> $field
	 * @param array<string, mixed> $meta
	 * @param array<string, true> $usedIds
	 */
	private function choices(array $options, array &$field, array &$meta, string $qid, array $usedIds): bool {
		$choices = array_values(array_filter($options, static fn (array $o): bool => ($o['optionType'] ?? 'choice') === 'choice'));
		usort($choices, static fn (array $a, array $b): int => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));
		if ($choices === []) {
			$this->skip('question', $qid, 'no_options', 'The question has no options, so it was left out.');
			return false;
		}
		if (count($choices) > DefinitionValidator::MAX_OPTIONS) {
			$this->skip('question', $qid, 'too_many_options', 'More than ' . DefinitionValidator::MAX_OPTIONS . ' options, so it was left out.');
			return false;
		}
		$list = [];
		$byText = [];
		$used = [];
		foreach ($choices as $choice) {
			$text = trim((string)($choice['text'] ?? ''));
			$value = $this->optionValue($text, count($list) + 1, $used);
			$used[$value] = true;
			$list[] = ['value' => $value, 'label' => mb_substr($text === '' ? $value : $text, 0, 255)];
			$byText[mb_strtolower($text)] ??= $value;
		}
		$field['options'] = $list;
		$meta['byText'] = $byText;
		return true;
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function choiceExtras(array $extra, string $qid): void {
		if (!empty($extra['allowOtherAnswer'])) {
			$this->skip('question', $qid, 'allow_other_answer', 'The "other" answer has no equivalent. Responses that used it keep no value for this question.');
		}
		if (!empty($extra['shuffleOptions'])) {
			$this->skip('question', $qid, 'shuffle_options', 'Shuffling the options has no equivalent.');
		}
	}

	/**
	 * @param list<array<string, mixed>> $options
	 * @param array<string, mixed> $extra
	 * @param array<string, mixed> $field
	 * @param array<string, mixed> $meta
	 * @param array<string, true> $usedIds
	 */
	private function grid(array $options, array $extra, array &$field, array &$meta, string $qid, array $usedIds): bool {
		$cell = $extra['questionType'] ?? null;
		if ($cell !== 'radio' && $cell !== 'checkbox') {
			$this->skip('question', $qid, 'grid_numbers', 'A grid of numbers has no equivalent, so the question was left out.');
			return false;
		}
		$rows = array_values(array_filter($options, static fn (array $o): bool => ($o['optionType'] ?? '') === 'row'));
		$columns = array_values(array_filter($options, static fn (array $o): bool => ($o['optionType'] ?? '') === 'column'));
		if ($rows === [] || $columns === []) {
			$this->skip('question', $qid, 'no_options', 'The grid has no rows or no columns, so it was left out.');
			return false;
		}
		if (count($rows) > DefinitionValidator::MAX_MATRIX_ROWS || count($columns) > DefinitionValidator::MAX_MATRIX_COLUMNS) {
			$this->skip('question', $qid, 'grid_too_large', 'More than ' . DefinitionValidator::MAX_MATRIX_ROWS . ' rows or ' . DefinitionValidator::MAX_MATRIX_COLUMNS . ' columns, so it was left out.');
			return false;
		}
		$byOrder = static fn (array $a, array $b): int => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0));
		usort($rows, $byOrder);
		usort($columns, $byOrder);

		$rowList = [];
		$rowValues = [];
		$used = [];
		foreach ($rows as $row) {
			$text = trim((string)($row['text'] ?? ''));
			$value = $this->optionValue($text, count($rowList) + 1, $used);
			$used[$value] = true;
			$rowList[] = ['value' => $value, 'label' => mb_substr($text === '' ? $value : $text, 0, 255)];
			$rowValues[(int)($row['id'] ?? 0)] = $value;
		}
		$columnList = [];
		$columnValues = [];
		$used = [];
		foreach ($columns as $column) {
			$text = trim((string)($column['text'] ?? ''));
			$value = $this->optionValue($text, count($columnList) + 1, $used);
			$used[$value] = true;
			$columnList[] = ['value' => $value, 'label' => mb_substr($text === '' ? $value : $text, 0, 255)];
			$columnValues[(int)($column['id'] ?? 0)] = $value;
		}
		$field['type'] = 'matrix';
		$field['questions'] = $rowList;
		$field['options'] = $columnList;
		if ($cell === 'checkbox') {
			$field['multiple'] = true;
		}
		$meta['kind'] = 'grid';
		$meta['multiple'] = $cell === 'checkbox';
		$meta['rows'] = $rowValues;
		$meta['columns'] = $columnValues;
		return true;
	}

	/**
	 * Core Forms accepts mime groups (`image`, `x-office/document`) and extensions; this app has a few
	 * named groups and extensions.
	 *
	 * @param array<string, mixed> $extra
	 * @return list<string>
	 */
	private function accept(array $extra): array {
		$accept = [];
		foreach ((array)($extra['allowedFileTypes'] ?? []) as $type) {
			$type = strtolower((string)$type);
			$group = match (true) {
				str_starts_with($type, 'image') => 'images',
				str_starts_with($type, 'audio') => 'audio',
				str_starts_with($type, 'video') => 'video',
				str_contains($type, 'document'), str_contains($type, 'pdf'), str_contains($type, 'text'), str_contains($type, 'x-office') => 'documents',
				str_contains($type, 'zip'), str_contains($type, 'archive') => 'archives',
				default => null,
			};
			if ($group !== null) {
				$accept[$group] = $group;
			}
		}
		foreach ((array)($extra['allowedFileExtensions'] ?? []) as $extension) {
			$extension = '.' . ltrim(strtolower((string)$extension), '.');
			if (preg_match('/^\.[a-z0-9]{1,10}$/', $extension) === 1) {
				$accept[$extension] = $extension;
			}
		}
		return array_values($accept);
	}

	/**
	 * @param array<string, mixed> $submission
	 * @param array<int, array<string, mixed>> $meta
	 * @return array{submittedAt: int, userId: ?string, data: array<string, mixed>}
	 */
	private function entry(array $submission, array $meta): array {
		$byQuestion = [];
		foreach ((array)($submission['answers'] ?? []) as $answer) {
			if (is_array($answer)) {
				$byQuestion[(int)($answer['questionId'] ?? 0)][] = $answer;
			}
		}

		$data = [];
		foreach ($byQuestion as $qid => $answers) {
			$m = $meta[$qid] ?? null;
			if ($m === null || ($m['kind'] ?? 'skipped') === 'skipped') {
				$this->countAnswers((string)$qid, 'question_not_carried', count($answers));
				continue;
			}
			if ($m['kind'] === 'file') {
				$this->countAnswers((string)$qid, 'files_not_carried', count($answers));
				continue;
			}
			$value = $this->value($m, $answers, (string)$qid);
			if ($value !== null) {
				$data[$m['id']] = $value;
			}
		}

		$userId = $submission['userId'] ?? null;
		if (!is_string($userId) || $userId === '' || str_starts_with($userId, self::ANONYMOUS_PREFIX)) {
			$userId = null;
		}

		return [
			'submittedAt' => (int)($submission['timestamp'] ?? 0),
			'userId' => $userId,
			'data' => $data,
		];
	}

	/**
	 * @param array<string, mixed> $m
	 * @param list<array<string, mixed>> $answers
	 */
	private function value(array $m, array $answers, string $qid): mixed {
		$texts = array_map(static fn (array $a): string => trim((string)($a['text'] ?? '')), $answers);
		$first = $texts[0] ?? '';

		switch ($m['kind']) {
			case 'text':
				return $first === '' ? null : $first;
			case 'number':
				if ($first === '') {
					return null;
				}
				if (!is_numeric($first)) {
					$this->countAnswers($qid, 'answer_invalid', 1);
					return null;
				}
				$number = $first + 0;
				if (isset($m['min'], $m['max']) && (!is_int($number) || $number < $m['min'] || $number > $m['max'])) {
					$this->countAnswers($qid, 'answer_out_of_range', 1);
					return null;
				}
				return $number;
			case 'single':
				if ($first === '') {
					return null;
				}
				$value = $m['byText'][mb_strtolower($first)] ?? null;
				if ($value === null) {
					$this->countAnswers($qid, 'answer_not_an_option', 1);
				}
				return $value;
			case 'multi':
				$values = [];
				foreach ($texts as $text) {
					if ($text === '') {
						continue;
					}
					$value = $m['byText'][mb_strtolower($text)] ?? null;
					if ($value === null) {
						$this->countAnswers($qid, 'answer_not_an_option', 1);
					} elseif (!in_array($value, $values, true)) {
						$values[] = $value;
					}
				}
				return $values === [] ? null : $values;
			case 'date':
				return $this->answerDate($first, '!Y-m-d', 'Y-m-d', $qid);
			case 'time':
				return $this->answerDate($first, '!H:i', 'H:i', $qid);
			case 'datetime':
				return $this->answerDate($first, '!Y-m-d H:i', 'Y-m-d\TH:i', $qid);
			case 'daterange':
				$start = $this->answerDate($texts[0] ?? '', '!Y-m-d', 'Y-m-d', $qid);
				$end = $this->answerDate($texts[1] ?? '', '!Y-m-d', 'Y-m-d', $qid);
				if ($start === null || $end === null || $end < $start) {
					if ($start !== null || $end !== null) {
						$this->countAnswers($qid, 'answer_invalid', 1);
					}
					return null;
				}
				return ['start' => $start, 'end' => $end];
			case 'color':
				if ($first === '') {
					return null;
				}
				if (preg_match('/^#[a-f0-9]{6}$/i', $first) !== 1) {
					$this->countAnswers($qid, 'answer_invalid', 1);
					return null;
				}
				return strtolower($first);
			case 'grid':
				return $this->gridValue($m, $first, $qid);
		}
		return null;
	}

	private function answerDate(string $text, string $in, string $out, string $qid): ?string {
		if ($text === '') {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat($in, $text);
		$errors = \DateTimeImmutable::getLastErrors();
		if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			$this->countAnswers($qid, 'answer_invalid', 1);
			return null;
		}
		return $parsed->format($out);
	}

	/**
	 * @param array<string, mixed> $m
	 * @return array<string, mixed>|null
	 */
	private function gridValue(array $m, string $json, string $qid): ?array {
		$decoded = $json === '' ? null : json_decode($json, true);
		if (!is_array($decoded)) {
			if ($json !== '') {
				$this->countAnswers($qid, 'answer_invalid', 1);
			}
			return null;
		}
		$result = [];
		// Keep the rows in the order of the field, as the stored format wants.
		foreach ($m['rows'] as $rowId => $rowValue) {
			if (!array_key_exists($rowId, $decoded)) {
				continue;
			}
			$picked = $m['multiple'] ? (array)$decoded[$rowId] : [$decoded[$rowId]];
			$values = [];
			foreach ($picked as $columnId) {
				$column = $m['columns'][(int)$columnId] ?? null;
				if ($column !== null && !in_array($column, $values, true)) {
					$values[] = $column;
				}
			}
			if ($values !== []) {
				$result[$rowValue] = $m['multiple'] ? $values : $values[0];
			}
		}
		return $result === [] ? null : $result;
	}

	private function day(mixed $value): ?string {
		if (is_int($value) || (is_string($value) && ctype_digit($value))) {
			return (int)$value > 0 ? gmdate('Y-m-d', (int)$value) : null;
		}
		if (is_string($value)) {
			$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
			return $parsed === false ? null : $parsed->format('Y-m-d');
		}
		return null;
	}

	private function clock(mixed $value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat('!H:i', $value);
		return $parsed === false ? null : $parsed->format('H:i');
	}

	/**
	 * Field ids are `^[a-z][a-z0-9_]{2,31}$`: made from the label, numbered when taken.
	 *
	 * @param array<string, true> $used
	 */
	private function fieldId(string $label, int $questionId, array $used): string {
		$slug = self::slug($label);
		$base = 'f_' . ($slug === '' ? 'q' . $questionId : $slug);
		$base = rtrim(mb_substr($base, 0, 32), '_');
		return self::unique($base, 32, static fn (string $id): bool => isset($used[$id]));
	}

	/**
	 * Option values are `^[a-z][a-z0-9_]{1,31}$`.
	 *
	 * @param array<string, true> $used
	 */
	private function optionValue(string $text, int $position, array $used): string {
		$slug = self::slug($text);
		if ($slug === '' || !ctype_alpha($slug[0])) {
			$slug = 'o_' . ($slug === '' ? (string)$position : $slug);
		}
		$slug = rtrim(substr($slug, 0, 32), '_');
		return self::unique($slug, 32, static fn (string $v): bool => isset($used[$v]));
	}

	private static function slug(string $text): string {
		$text = mb_strtolower(trim($text));
		if (function_exists('transliterator_transliterate')) {
			$text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: $text;
		}
		$text = preg_replace('/[^a-z0-9]+/', '_', $text) ?? '';
		return trim($text, '_');
	}

	/**
	 * @param callable(string): bool $taken
	 */
	private static function unique(string $base, int $max, callable $taken): string {
		if (!$taken($base)) {
			return $base;
		}
		for ($n = 2; $n < 10000; $n++) {
			$suffix = '_' . $n;
			$candidate = substr($base, 0, $max - strlen($suffix)) . $suffix;
			if (!$taken($candidate)) {
				return $candidate;
			}
		}
		return $base . '_x';
	}

	private function skip(string $scope, string $ref, string $code, string $detail): void {
		$this->skipped[] = [
			'scope' => $scope,
			'ref' => $ref,
			'label' => $scope === 'question' ? ($this->labels[$ref] ?? '') : '',
			'code' => $code,
			'detail' => $detail,
			'count' => 1,
		];
	}

	private function countAnswers(string $qid, string $code, int $count): void {
		$key = $qid . '|' . $code;
		$this->answerCounts[$key] = ($this->answerCounts[$key] ?? 0) + $count;
	}

	/**
	 * @return list<array{scope: string, ref: string, label: string, code: string, detail: string, count: int}>
	 */
	private function report(): array {
		$report = $this->skipped;
		foreach ($this->answerCounts as $key => $count) {
			[$qid, $code] = explode('|', $key, 2);
			$report[] = [
				'scope' => 'answer',
				'ref' => $qid,
				'label' => $this->labels[$qid] ?? '',
				'code' => $code,
				'detail' => $count . ($count === 1 ? ' answer was' : ' answers were') . ' not imported.',
				'count' => $count,
			];
		}
		return $report;
	}
}
