<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Actions\TablesValue;
use OCA\Webforms\Rules\FieldType;
use OCA\Webforms\Rules\RuleValidator;

/**
 * Save-time validation of a whole form definition (docs/SCHEMA.md sections 3
 * to 7). Returns a list of ['code' => string, 'path' => string], empty when
 * valid. Unknown properties are rejected so stored definitions stay predictable.
 */
final class DefinitionValidator {
	public const ID_PATTERN = '/^[a-z][a-z0-9_]{2,31}$/D';
	public const SCHEMA_VERSION = 1;
	public const MAX_FIELDS = 100;
	public const MAX_RULES = 100;
	public const MAX_ACTIONS = 10;
	public const MAX_ROWS = 100;
	public const MAX_OPTIONS = 300;
	/** Option values may be two characters, so country and state codes (`us`, `de`) work as they are. */
	public const OPTION_VALUE_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/D';
	public const SPANS = [3, 4, 6, 8, 9, 12];
	public const LABEL_POSITIONS = ['above', 'inline', 'hidden'];
	public const MAX_TABLES_COLUMNS = 100;
	public const ACTION_TYPES = ['email', 'webhook', 'message', 'redirect', 'tables'];

	private const SETTINGS_KEYS = ['submitLabel', 'labelPosition', 'requireLogin', 'allowMultipleSubmissions', 'hideRequiredIndicator', 'hideTitle', 'captcha', 'closedMessage', 'design'];
	private const MAX_PAGES = 20;
	private const TOP_KEYS = ['schemaVersion', 'title', 'description', 'settings', 'fields', 'pages', 'rules', 'actions'];
	private const COMMON_INPUT_KEYS = ['type', 'label', 'description', 'required', 'defaultValue', 'labelPosition', 'hideLabel'];
	private const PLACEHOLDER_TYPES = [FieldType::TEXT, FieldType::TEXTAREA, FieldType::EMAIL, FieldType::URL, FieldType::NUMBER, FieldType::PHONE, FieldType::SELECT];

	/** @var list<array{code: string, path: string}> */
	private array $errors = [];

	public function __construct(
		private readonly RuleValidator $ruleValidator = new RuleValidator(),
	) {
	}

	/**
	 * @param array<string, mixed> $definition
	 * @return list<array{code: string, path: string}>
	 */
	public function validate(array $definition): array {
		$this->errors = [];

		foreach (array_keys($definition) as $key) {
			if (!in_array($key, self::TOP_KEYS, true)) {
				$this->err('unknown_property', (string)$key);
			}
		}

		if (($definition['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
			$this->err('schema_version_unsupported', 'schemaVersion');
		}
		$this->title($definition['title'] ?? null);
		$this->optionalString($definition, 'description', 2000, 'description_invalid');
		$this->settings($definition['settings'] ?? []);

		$fields = $this->fields($definition['fields'] ?? null);
		$this->layout($definition['pages'] ?? null, $fields);

		$checked = $definition;
		$checked['fields'] = $fields;
		foreach ($this->ruleValidator->validate($checked) as $error) {
			$this->errors[] = $error;
		}
		if (is_array($definition['rules'] ?? null) && count($definition['rules']) > self::MAX_RULES) {
			$this->err('too_many_rules', 'rules');
		}

		$this->actions($definition['actions'] ?? [], $fields);

		return $this->errors;
	}

	private function err(string $code, string $path): void {
		$this->errors[] = ['code' => $code, 'path' => $path];
	}

	private function title(mixed $title): void {
		if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
			$this->err('title_invalid', 'title');
		}
	}

	/**
	 * @param array<mixed> $container
	 */
	private function optionalString(array $container, string $key, int $max, string $code, string $prefix = ''): void {
		if (!array_key_exists($key, $container) || $container[$key] === null) {
			return;
		}
		if (!is_string($container[$key]) || mb_strlen($container[$key]) > $max) {
			$this->err($code, $prefix . $key);
		}
	}

	private function settings(mixed $settings): void {
		if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
			$this->err('settings_invalid', 'settings');
			return;
		}
		foreach ($settings as $key => $value) {
			$path = 'settings.' . $key;
			if (!in_array($key, self::SETTINGS_KEYS, true)) {
				$this->err('unknown_property', $path);
			} elseif ($key === 'design') {
				$this->design($value, $path);
			} elseif ($key === 'labelPosition') {
				if (!in_array($value, self::LABEL_POSITIONS, true)) {
					$this->err('settings_invalid', $path);
				}
			} elseif (in_array($key, ['requireLogin', 'allowMultipleSubmissions', 'hideRequiredIndicator', 'hideTitle', 'captcha'], true)) {
				if (!is_bool($value)) {
					$this->err('settings_invalid', $path);
				}
			} elseif (!is_string($value) || mb_strlen($value) > ($key === 'submitLabel' ? 64 : 500)) {
				$this->err('settings_invalid', $path);
			}
		}
	}

	private function design(mixed $design, string $path): void {
		if (!is_array($design) || ($design !== [] && array_is_list($design))) {
			$this->err('settings_invalid', $path);
			return;
		}
		foreach ($design as $key => $value) {
			$valid = match ($key) {
				'width' => in_array($value, ['narrow', 'standard', 'wide'], true),
				'card' => is_bool($value),
				'background' => in_array($value, ['default', 'plain', 'color'], true),
				'backgroundColor', 'accentColor', 'buttonColor' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1,
				default => null,
			};
			if ($valid === null) {
				$this->err('unknown_property', $path . '.' . $key);
			} elseif (!$valid) {
				$this->err('settings_invalid', $path . '.' . $key);
			}
		}
	}

	/**
	 * @return array<string, array<string, mixed>> only the fields that are structurally usable
	 */
	private function fields(mixed $fields): array {
		if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
			$this->err('fields_invalid', 'fields');
			return [];
		}
		if (count($fields) > self::MAX_FIELDS) {
			$this->err('too_many_fields', 'fields');
		}

		$usable = [];
		foreach ($fields as $id => $field) {
			$path = 'fields.' . $id;
			if (preg_match(self::ID_PATTERN, (string)$id) !== 1) {
				$this->err('field_bad_id', $path);
				continue;
			}
			if (!is_array($field) || !is_string($field['type'] ?? null)) {
				$this->err('field_invalid', $path);
				continue;
			}
			$type = $field['type'];
			if (isset(FieldType::OPERATORS[$type])) {
				$this->inputField($field, $type, $path);
			} elseif (in_array($type, FieldType::DISPLAY_TYPES, true)) {
				$this->displayField($field, $type, $path);
			} else {
				$this->err('field_unknown_type', $path . '.type');
				continue;
			}
			$usable[(string)$id] = $field;
		}
		return $usable;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function inputField(array $field, string $type, string $path): void {
		$allowed = self::COMMON_INPUT_KEYS;
		if (in_array($type, self::PLACEHOLDER_TYPES, true)) {
			$allowed[] = 'placeholder';
		}
		$extra = match ($type) {
			FieldType::TEXT => ['minLength', 'maxLength'],
			FieldType::TEXTAREA => ['minLength', 'maxLength', 'rows'],
			FieldType::NUMBER => ['min', 'max', 'step', 'prefix', 'suffix'],
			FieldType::DATE, FieldType::TIME => ['min', 'max'],
			FieldType::SELECT => ['options'],
			FieldType::RADIO => ['options', 'columns'],
			FieldType::CHECKBOXES => ['options', 'minSelected', 'maxSelected', 'columns'],
			FieldType::FILE => ['maxFiles', 'maxSizeMb', 'accept', 'audience', 'storage', 'folder'],
			default => [],
		};
		$allowed = array_merge($allowed, $extra);

		foreach (array_keys($field) as $key) {
			if (!in_array($key, $allowed, true)) {
				$this->err('unknown_property', $path . '.' . $key);
			}
		}

		$label = $field['label'] ?? null;
		if (!is_string($label) || trim($label) === '' || mb_strlen($label) > 255) {
			$this->err('field_label_invalid', $path . '.label');
		}
		$this->optionalString($field, 'description', 1000, 'field_description_invalid', $path . '.');
		$this->optionalString($field, 'placeholder', 255, 'field_placeholder_invalid', $path . '.');
		if (array_key_exists('labelPosition', $field) && !in_array($field['labelPosition'], self::LABEL_POSITIONS, true)) {
			$this->err('field_label_position_invalid', $path . '.labelPosition');
		}
		foreach (['required', 'hideLabel'] as $flag) {
			if (array_key_exists($flag, $field) && !is_bool($field[$flag])) {
				$this->err('field_flag_invalid', $path . '.' . $flag);
			}
		}

		if ($extra !== [] && in_array('options', $extra, true)) {
			$this->options($field['options'] ?? null, $path . '.options');
		}
		if ($type === FieldType::TEXT || $type === FieldType::TEXTAREA) {
			foreach (['minLength', 'maxLength'] as $key) {
				if (array_key_exists($key, $field) && (!is_int($field[$key]) || $field[$key] < 1)) {
					$this->err('field_limit_invalid', $path . '.' . $key);
				}
			}
			if (is_int($field['minLength'] ?? null) && is_int($field['maxLength'] ?? null) && $field['minLength'] > $field['maxLength']) {
				$this->err('field_limit_invalid', $path . '.minLength');
			}
			if ($type === FieldType::TEXTAREA && array_key_exists('rows', $field) && (!is_int($field['rows']) || $field['rows'] < 2 || $field['rows'] > 20)) {
				$this->err('field_limit_invalid', $path . '.rows');
			}
		}
		if ($type === FieldType::DATE || $type === FieldType::TIME) {
			$this->dateTimeLimits($field, $type, $path);
		}
		if (($type === FieldType::RADIO || $type === FieldType::CHECKBOXES)
			&& array_key_exists('columns', $field) && (!is_int($field['columns']) || $field['columns'] < 1 || $field['columns'] > 4)) {
			$this->err('field_limit_invalid', $path . '.columns');
		}
		if ($type === FieldType::NUMBER) {
			$this->numberLimits($field, $path);
			$this->optionalString($field, 'prefix', 16, 'field_limit_invalid', $path . '.');
			$this->optionalString($field, 'suffix', 16, 'field_limit_invalid', $path . '.');
		}
		if ($type === FieldType::CHECKBOXES) {
			$this->selectionLimits($field, $path);
		}

		if ($type === FieldType::FILE) {
			$this->uploadSettings($field, $path);
			if (array_key_exists('defaultValue', $field)) {
				$this->err('field_default_invalid', $path . '.defaultValue');
			}
		}

		// A default must be a value the field itself would accept.
		if ($type !== FieldType::FILE && array_key_exists('defaultValue', $field) && $field['defaultValue'] !== null) {
			[, $error] = FieldValueValidator::clean($field, $field['defaultValue']);
			if ($error !== null) {
				$this->err('field_default_invalid', $path . '.defaultValue');
			}
		}
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function uploadSettings(array $field, string $path): void {
		if (array_key_exists('maxFiles', $field) && (!is_int($field['maxFiles']) || $field['maxFiles'] < 1 || $field['maxFiles'] > UploadPolicy::MAX_FILES)) {
			$this->err('field_limit_invalid', $path . '.maxFiles');
		}
		if (array_key_exists('maxSizeMb', $field) && (!is_int($field['maxSizeMb']) || $field['maxSizeMb'] < 1 || $field['maxSizeMb'] > UploadPolicy::MAX_SIZE_MB)) {
			$this->err('field_limit_invalid', $path . '.maxSizeMb');
		}
		if (array_key_exists('audience', $field) && !in_array($field['audience'], [UploadPolicy::AUDIENCE_ANYONE, UploadPolicy::AUDIENCE_LOGIN], true)) {
			$this->err('field_upload_invalid', $path . '.audience');
		}
		if (array_key_exists('storage', $field) && !in_array($field['storage'], [UploadPolicy::STORAGE_FILES, UploadPolicy::STORAGE_APP], true)) {
			$this->err('field_upload_invalid', $path . '.storage');
		}
		if (array_key_exists('folder', $field) && (!is_string($field['folder']) || UploadPolicy::cleanFolder($field['folder']) === null)) {
			$this->err('field_upload_folder_invalid', $path . '.folder');
		}
		if (array_key_exists('accept', $field)) {
			$accept = $field['accept'];
			if (!is_array($accept) || !array_is_list($accept) || $accept === [] || count($accept) > 20) {
				$this->err('field_upload_accept_invalid', $path . '.accept');
				return;
			}
			foreach ($accept as $index => $entry) {
				if (!is_string($entry) || (!isset(UploadPolicy::GROUPS[$entry]) && preg_match('/^\.[a-z0-9]{1,10}$/D', $entry) !== 1)) {
					$this->err('field_upload_accept_invalid', $path . '.accept[' . $index . ']');
				} elseif ($entry[0] === '.' && in_array(substr($entry, 1), UploadPolicy::DENIED, true)) {
					$this->err('field_upload_type_denied', $path . '.accept[' . $index . ']');
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function numberLimits(array $field, string $path): void {
		foreach (['min', 'max', 'step'] as $key) {
			if (array_key_exists($key, $field) && !is_int($field[$key]) && !is_float($field[$key])) {
				$this->err('field_limit_invalid', $path . '.' . $key);
			}
		}
		if (isset($field['step']) && (is_int($field['step']) || is_float($field['step'])) && $field['step'] <= 0) {
			$this->err('field_limit_invalid', $path . '.step');
		}
		if (is_numeric($field['min'] ?? null) && is_numeric($field['max'] ?? null) && $field['min'] > $field['max']) {
			$this->err('field_limit_invalid', $path . '.min');
		}
	}

	/**
	 * Earliest and latest allowed date or time, written the way the value is stored.
	 *
	 * @param array<string, mixed> $field
	 */
	private function dateTimeLimits(array $field, string $type, string $path): void {
		$valid = $type === FieldType::DATE ? FieldValueValidator::isDate(...) : FieldValueValidator::isTime(...);
		foreach (['min', 'max'] as $key) {
			if (array_key_exists($key, $field) && !$valid($field[$key])) {
				$this->err('field_limit_invalid', $path . '.' . $key);
			}
		}
		if ($valid($field['min'] ?? null) && $valid($field['max'] ?? null) && $field['min'] > $field['max']) {
			$this->err('field_limit_invalid', $path . '.min');
		}
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function selectionLimits(array $field, string $path): void {
		foreach (['minSelected', 'maxSelected'] as $key) {
			if (array_key_exists($key, $field) && (!is_int($field[$key]) || $field[$key] < 0)) {
				$this->err('field_limit_invalid', $path . '.' . $key);
			}
		}
		if (is_int($field['minSelected'] ?? null) && is_int($field['maxSelected'] ?? null)
			&& $field['minSelected'] > $field['maxSelected']) {
			$this->err('field_limit_invalid', $path . '.minSelected');
		}
	}

	private function options(mixed $options, string $path): void {
		if (!is_array($options) || !array_is_list($options) || $options === [] || count($options) > self::MAX_OPTIONS) {
			$this->err('options_invalid', $path);
			return;
		}
		$seen = [];
		foreach ($options as $index => $option) {
			$optionPath = $path . '[' . $index . ']';
			if (!is_array($option) || array_diff(array_keys($option), ['value', 'label']) !== []) {
				$this->err('option_invalid', $optionPath);
				continue;
			}
			$value = $option['value'] ?? null;
			if (!is_string($value) || preg_match(self::OPTION_VALUE_PATTERN, $value) !== 1) {
				$this->err('option_bad_value', $optionPath . '.value');
			} elseif (isset($seen[$value])) {
				$this->err('option_duplicate_value', $optionPath . '.value');
			} else {
				$seen[$value] = true;
			}
			$label = $option['label'] ?? null;
			if (!is_string($label) || trim($label) === '' || mb_strlen($label) > 255) {
				$this->err('option_label_invalid', $optionPath . '.label');
			}
		}
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function displayField(array $field, string $type, string $path): void {
		$allowed = match ($type) {
			FieldType::HEADING => ['type', 'text', 'level', 'align'],
			FieldType::PARAGRAPH => ['type', 'text', 'align'],
			FieldType::DIVIDER => ['type', 'style'],
			default => ['type'],
		};
		foreach (array_keys($field) as $key) {
			if (!in_array($key, $allowed, true)) {
				$this->err('unknown_property', $path . '.' . $key);
			}
		}
		if ($type === FieldType::DIVIDER) {
			if (array_key_exists('style', $field) && !in_array($field['style'], ['solid', 'dashed', 'dotted'], true)) {
				$this->err('field_limit_invalid', $path . '.style');
			}
			return;
		}
		$text = $field['text'] ?? null;
		$max = $type === FieldType::HEADING ? 255 : 2000;
		if (!is_string($text) || trim($text) === '' || mb_strlen($text) > $max) {
			$this->err('field_text_invalid', $path . '.text');
		}
		if (array_key_exists('align', $field) && !in_array($field['align'], ['start', 'center', 'end'], true)) {
			$this->err('field_align_invalid', $path . '.align');
		}
		if ($type === FieldType::HEADING && !in_array($field['level'] ?? 2, [1, 2, 3, 4, 5, 6], true)) {
			$this->err('field_level_invalid', $path . '.level');
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function layout(mixed $pages, array $fields): void {
		if (!is_array($pages) || !array_is_list($pages) || $pages === [] || count($pages) > self::MAX_PAGES) {
			$this->err('pages_invalid', 'pages');
			return;
		}
		$ids = [];
		$placed = [];
		foreach ($pages as $p => $page) {
			$pagePath = 'pages[' . $p . ']';
			if (!is_array($page) || array_diff(array_keys($page), ['id', 'title', 'rows']) !== []) {
				$this->err('page_invalid', $pagePath);
				continue;
			}
			$this->layoutId($page['id'] ?? null, $pagePath . '.id', $ids);
			if (array_key_exists('title', $page) && $page['title'] !== null
				&& (!is_string($page['title']) || mb_strlen($page['title']) > 255)) {
				$this->err('page_invalid', $pagePath . '.title');
			}
			$this->pageRows($page['rows'] ?? null, $pagePath, $fields, $ids, $placed);
		}

		foreach (array_keys($fields) as $id) {
			if (!isset($placed[$id])) {
				$this->err('field_not_placed', 'fields.' . $id);
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 * @param array<string, true> $ids
	 * @param array<string, true> $placed
	 */
	private function pageRows(mixed $rows, string $pagePath, array $fields, array &$ids, array &$placed): void {
		if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ROWS) {
			$this->err('rows_invalid', $pagePath . '.rows');
			return;
		}
		foreach ($rows as $r => $row) {
			$rowPath = $pagePath . '.rows[' . $r . ']';
			if (!is_array($row) || array_diff(array_keys($row), ['id', 'cells']) !== []) {
				$this->err('row_invalid', $rowPath);
				continue;
			}
			$this->layoutId($row['id'] ?? null, $rowPath . '.id', $ids);
			$cells = $row['cells'] ?? null;
			if (!is_array($cells) || !array_is_list($cells) || $cells === [] || count($cells) > 4) {
				$this->err('cells_invalid', $rowPath . '.cells');
				continue;
			}
			$sum = 0;
			foreach ($cells as $c => $cell) {
				$cellPath = $rowPath . '.cells[' . $c . ']';
				if (!is_array($cell) || array_diff(array_keys($cell), ['id', 'span', 'field']) !== []) {
					$this->err('cell_invalid', $cellPath);
					continue;
				}
				$this->layoutId($cell['id'] ?? null, $cellPath . '.id', $ids);
				$span = $cell['span'] ?? null;
				if (!is_int($span) || !in_array($span, self::SPANS, true)) {
					$this->err('cell_bad_span', $cellPath . '.span');
				} else {
					$sum += $span;
				}
				$field = $cell['field'] ?? null;
				if (!is_string($field) || !isset($fields[$field])) {
					$this->err('cell_unknown_field', $cellPath . '.field');
				} elseif (isset($placed[$field])) {
					$this->err('field_placed_twice', $cellPath . '.field');
				} else {
					$placed[$field] = true;
				}
			}
			if ($sum > 12) {
				$this->err('row_too_wide', $rowPath);
			}
		}
	}

	/**
	 * @param array<string, true> $seen
	 */
	private function layoutId(mixed $id, string $path, array &$seen): void {
		if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
			$this->err('layout_bad_id', $path);
		} elseif (isset($seen[$id])) {
			$this->err('layout_duplicate_id', $path);
		} else {
			$seen[$id] = true;
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function actions(mixed $actions, array $fields): void {
		if (!is_array($actions) || !array_is_list($actions)) {
			$this->err('actions_invalid', 'actions');
			return;
		}
		if (count($actions) > self::MAX_ACTIONS) {
			$this->err('too_many_actions', 'actions');
		}

		$seen = [];
		foreach ($actions as $index => $action) {
			$path = 'actions[' . $index . ']';
			if (!is_array($action) || !is_string($action['type'] ?? null)
				|| !in_array($action['type'], self::ACTION_TYPES, true)) {
				$this->err('action_invalid', $path);
				continue;
			}
			$id = $action['id'] ?? null;
			if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
				$this->err('action_bad_id', $path . '.id');
			} elseif (isset($seen[$id])) {
				$this->err('action_duplicate_id', $path . '.id');
			} else {
				$seen[$id] = true;
			}

			if (array_key_exists('when', $action)) {
				foreach ($this->ruleValidator->validateCondition($action['when'], $fields, $path . '.when') as $error) {
					$this->errors[] = $error;
				}
			}

			$allowed = ['id', 'type', 'when'];
			switch ($action['type']) {
				case 'email':
					$allowed = array_merge($allowed, ['to', 'subject', 'body']);
					$this->emailAction($action, $fields, $path);
					break;
				case 'webhook':
					$allowed = array_merge($allowed, ['url', 'secretRef']);
					$this->webhookAction($action, $path);
					break;
				case 'tables':
					$allowed = array_merge($allowed, ['tableId', 'map']);
					$this->tablesAction($action, $fields, $path);
					break;
				case 'message':
					$allowed[] = 'text';
					$this->textProp($action, 'text', 1000, $fields, $path);
					break;
				case 'redirect':
					$allowed[] = 'url';
					$this->redirectAction($action, $fields, $path);
					break;
			}
			foreach (array_keys($action) as $key) {
				if (!in_array($key, $allowed, true)) {
					$this->err('unknown_property', $path . '.' . $key);
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $action
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function emailAction(array $action, array $fields, string $path): void {
		$to = $action['to'] ?? null;
		if (is_string($to)) {
			if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || strlen($to) > FieldValueValidator::MAX_EMAIL) {
				$this->err('action_bad_recipient', $path . '.to');
			}
		} elseif (is_array($to) && (array_keys($to) === ['owner'] || array_keys($to) === ['respondent'])) {
			// "The form owner" and "the signed-in respondent": resolved to their account email when sent.
			if (reset($to) !== true) {
				$this->err('action_bad_recipient', $path . '.to');
			}
		} elseif (is_array($to) && array_keys($to) === ['field']) {
			$source = is_string($to['field']) ? ($fields[$to['field']] ?? null) : null;
			if ($source === null || ($source['type'] ?? null) !== FieldType::EMAIL) {
				$this->err('action_bad_recipient', $path . '.to.field');
			}
		} else {
			$this->err('action_bad_recipient', $path . '.to');
		}
		$this->textProp($action, 'subject', 255, $fields, $path);
		$this->textProp($action, 'body', 5000, $fields, $path);
	}

	/**
	 * Shape only. Whether the table exists, the owner may add rows to it and each column can take its
	 * source is checked when the form is published, because it needs Tables.
	 *
	 * @param array<string, mixed> $action
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function tablesAction(array $action, array $fields, string $path): void {
		$tableId = $action['tableId'] ?? null;
		if (!is_int($tableId) || $tableId < 1) {
			$this->err('action_bad_table', $path . '.tableId');
		}
		$map = $action['map'] ?? null;
		if (!is_array($map) || $map === [] || count($map) > self::MAX_TABLES_COLUMNS) {
			$this->err('action_bad_table_map', $path . '.map');
			return;
		}
		foreach ($map as $columnId => $source) {
			$sourceOk = is_string($source) && (in_array($source, TablesValue::META, true)
				|| (isset($fields[$source]) && FieldType::isInput((string)($fields[$source]['type'] ?? ''))));
			if (preg_match('/^[1-9][0-9]{0,9}$/D', (string)$columnId) !== 1 || !$sourceOk) {
				$this->err('action_bad_table_map', $path . '.map.' . $columnId);
			}
		}
	}

	/**
	 * @param array<string, mixed> $action
	 */
	private function webhookAction(array $action, string $path): void {
		$url = $action['url'] ?? null;
		// No placeholders in webhook URLs, and no userinfo.
		if (!is_string($url) || strlen($url) > 2000
			|| preg_match('~^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?([/?#][^\s{}]*)?$~D', $url) !== 1) {
			$this->err('action_bad_url', $path . '.url');
		}
		if (array_key_exists('secretRef', $action) && (!is_string($action['secretRef']) || mb_strlen($action['secretRef']) > 64)) {
			$this->err('action_bad_secret', $path . '.secretRef');
		}
	}

	/**
	 * @param array<string, mixed> $action
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function redirectAction(array $action, array $fields, string $path): void {
		$url = $action['url'] ?? null;
		// Placeholders are only allowed after the host (section 7.2).
		if (!is_string($url) || strlen($url) > 2000
			|| preg_match('~^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?([/?#].*)?$~Ds', $url) !== 1) {
			$this->err('action_bad_url', $path . '.url');
			return;
		}
		$this->checkPlaceholders($url, $fields, $path . '.url');
	}

	/**
	 * @param array<string, mixed> $action
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function textProp(array $action, string $key, int $max, array $fields, string $path): void {
		$value = $action[$key] ?? null;
		if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
			$this->err('action_text_invalid', $path . '.' . $key);
			return;
		}
		$this->checkPlaceholders($value, $fields, $path . '.' . $key);
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function checkPlaceholders(string $text, array $fields, string $path): void {
		if (preg_match_all('/\{([^{}]*)\}/', $text, $matches) === false) {
			return;
		}
		foreach ($matches[1] as $name) {
			$field = $fields[$name] ?? null;
			if ($field === null || !isset(FieldType::OPERATORS[(string)($field['type'] ?? '')])) {
				$this->err('action_unknown_placeholder', $path);
				return;
			}
		}
	}
}
