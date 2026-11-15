<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Rules;

/**
 * Save-time checks for the `rules` section of a form definition
 * (docs/SCHEMA.md section 6). Returns a list of errors, empty when valid.
 *
 * Each error is ['code' => string, 'path' => string]. Paths look like
 * `rules[2].when.all[0]`.
 */
final class RuleValidator {
	public const ID_PATTERN = '/^[a-z][a-z0-9_]{2,31}$/D';
	public const MAX_GROUP_DEPTH = 5;
	public const EFFECTS = ['show', 'hide', 'require'];

	/**
	 * @param array<string, mixed> $definition
	 * @return list<array{code: string, path: string}>
	 */
	public function validate(array $definition): array {
		$errors = [];

		/** @var array<string, array<string, mixed>> $fields */
		$fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
		$rules = $definition['rules'] ?? [];

		if (!is_array($rules) || !array_is_list($rules)) {
			return [['code' => 'rules_not_a_list', 'path' => 'rules']];
		}

		$seenIds = [];
		foreach ($rules as $index => $rule) {
			$path = 'rules[' . $index . ']';
			if (!is_array($rule)) {
				$errors[] = ['code' => 'rule_invalid', 'path' => $path];
				continue;
			}

			$id = $rule['id'] ?? null;
			if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
				$errors[] = ['code' => 'rule_bad_id', 'path' => $path . '.id'];
			} elseif (isset($seenIds[$id])) {
				$errors[] = ['code' => 'rule_duplicate_id', 'path' => $path . '.id'];
			} else {
				$seenIds[$id] = true;
			}

			$effect = $rule['effect'] ?? null;
			if (!is_string($effect) || !in_array($effect, self::EFFECTS, true)) {
				$errors[] = ['code' => 'rule_bad_effect', 'path' => $path . '.effect'];
			}

			$targets = $rule['targets'] ?? null;
			if (!is_array($targets) || !array_is_list($targets) || $targets === []) {
				$errors[] = ['code' => 'rule_no_targets', 'path' => $path . '.targets'];
			} else {
				foreach ($targets as $targetIndex => $target) {
					$targetPath = $path . '.targets[' . $targetIndex . ']';
					if (!is_string($target) || !isset($fields[$target])) {
						$errors[] = ['code' => 'rule_unknown_target', 'path' => $targetPath];
					} elseif ($effect === 'require' && !FieldType::isInput($this->typeOf($fields[$target]))) {
						$errors[] = ['code' => 'rule_require_on_display_field', 'path' => $targetPath];
					}
				}
			}

			$this->validateNode($rule['when'] ?? null, $fields, $path . '.when', 1, $errors);
		}

		// The graph is only meaningful when the structure above is sound.
		if ($errors === []) {
			try {
				RuleGraph::order(array_map('strval', array_keys($fields)), $rules);
			} catch (RuleCycleException) {
				$errors[] = ['code' => 'rules_cycle', 'path' => 'rules'];
			}
		}

		return $errors;
	}

	/**
	 * Validate one condition tree on its own (used for action conditions).
	 *
	 * @param array<string, array<string, mixed>> $fields
	 * @return list<array{code: string, path: string}>
	 */
	public function validateCondition(mixed $node, array $fields, string $path): array {
		$errors = [];
		$this->validateNode($node, $fields, $path, 1, $errors);
		return $errors;
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 * @param list<array{code: string, path: string}> $errors
	 */
	private function validateNode(mixed $node, array $fields, string $path, int $depth, array &$errors): void {
		if (!is_array($node)) {
			$errors[] = ['code' => 'cond_bad_shape', 'path' => $path];
			return;
		}

		$hasAll = array_key_exists('all', $node);
		$hasAny = array_key_exists('any', $node);

		if ($hasAll || $hasAny) {
			if (($hasAll && $hasAny) || array_key_exists('field', $node)) {
				$errors[] = ['code' => 'cond_bad_shape', 'path' => $path];
				return;
			}
			if ($depth > self::MAX_GROUP_DEPTH) {
				$errors[] = ['code' => 'cond_too_deep', 'path' => $path];
				return;
			}
			$key = $hasAll ? 'all' : 'any';
			$members = $node[$key];
			if (!is_array($members) || !array_is_list($members) || $members === []) {
				$errors[] = ['code' => 'cond_bad_shape', 'path' => $path . '.' . $key];
				return;
			}
			foreach ($members as $index => $member) {
				$this->validateNode($member, $fields, $path . '.' . $key . '[' . $index . ']', $depth + 1, $errors);
			}
			return;
		}

		$this->validateLeaf($node, $fields, $path, $errors);
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, array<string, mixed>> $fields
	 * @param list<array{code: string, path: string}> $errors
	 */
	private function validateLeaf(array $node, array $fields, string $path, array &$errors): void {
		$fieldId = $node['field'] ?? null;
		if (!is_string($fieldId) || !isset($fields[$fieldId])) {
			$errors[] = ['code' => 'cond_unknown_field', 'path' => $path . '.field'];
			return;
		}

		$field = $fields[$fieldId];
		$type = $this->typeOf($field);
		if (!FieldType::isInput($type)) {
			$errors[] = ['code' => 'cond_source_not_input', 'path' => $path . '.field'];
			return;
		}

		$op = $node['op'] ?? null;
		if (!is_string($op) || !in_array($op, FieldType::operatorsFor($type), true)) {
			$errors[] = ['code' => 'cond_bad_operator', 'path' => $path . '.op'];
			return;
		}

		$hasValue = array_key_exists('value', $node);
		if ($op === 'empty' || $op === 'not_empty') {
			if ($hasValue) {
				$errors[] = ['code' => 'cond_bad_value', 'path' => $path . '.value'];
			}
			return;
		}

		if (!$hasValue) {
			$errors[] = ['code' => 'cond_bad_value', 'path' => $path . '.value'];
			return;
		}

		$value = $node['value'];
		if (in_array($op, ['in', 'not_in', 'contains_any', 'contains_all', 'contains_none'], true)) {
			if (!is_array($value) || !array_is_list($value) || $value === []) {
				$errors[] = ['code' => 'cond_bad_value', 'path' => $path . '.value'];
				return;
			}
			foreach ($value as $index => $item) {
				if (!$this->isValidRuleValue($type, $field, $item)) {
					$errors[] = ['code' => 'cond_bad_value', 'path' => $path . '.value[' . $index . ']'];
				}
			}
			return;
		}

		if (!$this->isValidRuleValue($type, $field, $value)) {
			$errors[] = ['code' => 'cond_bad_value', 'path' => $path . '.value'];
		}
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function isValidRuleValue(string $type, array $field, mixed $value): bool {
		switch ($type) {
			case FieldType::TEXT:
			case FieldType::TEXTAREA:
			case FieldType::EMAIL:
			case FieldType::PHONE:
			case FieldType::URL:
				return ValueNormalizer::normalize($type, $value) !== null;
			case FieldType::NUMBER:
				return is_int($value) || (is_float($value) && is_finite($value));
			case FieldType::SELECT:
			case FieldType::RADIO:
			case FieldType::CHECKBOXES:
				return is_string($value) && in_array($value, $this->optionValues($field), true);
			case FieldType::CHECKBOX:
				return is_bool($value);
			case FieldType::DATE:
			case FieldType::TIME:
				return ValueNormalizer::normalize($type, $value) !== null;
			default:
				return false;
		}
	}

	/**
	 * @param array<string, mixed> $field
	 * @return list<string>
	 */
	private function optionValues(array $field): array {
		$values = [];
		$options = $field['options'] ?? [];
		if (is_array($options)) {
			foreach ($options as $option) {
				if (is_array($option) && is_string($option['value'] ?? null)) {
					$values[] = $option['value'];
				}
			}
		}
		return $values;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function typeOf(array $field): string {
		return is_string($field['type'] ?? null) ? $field['type'] : '';
	}
}
