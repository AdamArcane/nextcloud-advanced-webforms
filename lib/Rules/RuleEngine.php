<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Rules;

/**
 * Decides which fields are visible and required for a set of submitted values.
 * This is the enforcing evaluator; the browser uses its TypeScript twin in
 * src/rules/engine.ts for live feedback. Semantics: docs/SCHEMA.md section 6.
 */
final class RuleEngine {
	/**
	 * @param array<string, mixed> $definition form definition with `fields` and `rules`
	 * @param array<string, mixed> $values submitted values keyed by field ID
	 * @return array{visible: list<string>, required: list<string>} field IDs in document order
	 * @throws RuleCycleException
	 */
	public function evaluate(array $definition, array $values): array {
		/** @var array<string, array<string, mixed>> $fields */
		$fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
		/** @var list<array<string, mixed>> $rules */
		$rules = is_array($definition['rules'] ?? null) ? array_values($definition['rules']) : [];

		$fieldIds = array_map('strval', array_keys($fields));
		$order = RuleGraph::order($fieldIds, $rules);

		// Conditions grouped per target field and effect.
		$conditions = [];
		foreach ($rules as $rule) {
			if (!is_array($rule) || !is_array($rule['targets'] ?? null)) {
				continue;
			}
			foreach ($rule['targets'] as $target) {
				if (is_string($target)) {
					$conditions[$target][(string)($rule['effect'] ?? '')][] = $rule['when'] ?? null;
				}
			}
		}

		/** @var array<string, string|float|bool|list<string>|null> $effective */
		$effective = [];
		$visible = [];
		$required = [];

		foreach ($order as $id) {
			$field = $fields[$id];
			$type = is_array($field) && is_string($field['type'] ?? null) ? $field['type'] : '';
			$show = $conditions[$id]['show'] ?? [];
			$hide = $conditions[$id]['hide'] ?? [];
			$require = $conditions[$id]['require'] ?? [];

			$isVisible = ($show === [] || $this->anyMatches($show, $fields, $effective))
				&& !$this->anyMatches($hide, $fields, $effective);

			if (!$isVisible) {
				$effective[$id] = null;
				continue;
			}

			$visible[$id] = true;
			if (FieldType::isInput($type)) {
				$effective[$id] = ValueNormalizer::normalize($type, $values[$id] ?? null);
				if (($field['required'] ?? false) === true || $this->anyMatches($require, $fields, $effective)) {
					$required[$id] = true;
				}
			}
		}

		return [
			'visible' => array_values(array_filter($fieldIds, static fn (string $id): bool => isset($visible[$id]))),
			'required' => array_values(array_filter($fieldIds, static fn (string $id): bool => isset($required[$id]))),
		];
	}

	/**
	 * Evaluate one condition tree against submitted values, for after-submit
	 * action conditions (docs/SCHEMA.md section 7.1). The values are the entry's
	 * post-visibility data, so hidden fields are already absent and read as empty.
	 *
	 * @param array<string, array<string, mixed>> $fields the definition's fields
	 * @param array<string, mixed> $values answers keyed by field ID
	 */
	public function matchesCondition(array $fields, mixed $condition, array $values): bool {
		/** @var array<string, string|float|bool|list<string>|null> $effective */
		$effective = [];
		foreach ($fields as $id => $field) {
			$type = is_array($field) && is_string($field['type'] ?? null) ? $field['type'] : '';
			if (FieldType::isInput($type)) {
				$effective[(string)$id] = ValueNormalizer::normalize($type, $values[$id] ?? null);
			}
		}
		return $this->matches($condition, $fields, $effective);
	}

	/**
	 * @param list<mixed> $conditions
	 * @param array<string, array<string, mixed>> $fields
	 * @param array<string, string|float|bool|list<string>|null> $effective
	 */
	private function anyMatches(array $conditions, array $fields, array $effective): bool {
		foreach ($conditions as $condition) {
			if ($this->matches($condition, $fields, $effective)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 * @param array<string, string|float|bool|list<string>|null> $effective
	 */
	private function matches(mixed $node, array $fields, array $effective): bool {
		if (!is_array($node)) {
			return false;
		}

		if (array_key_exists('all', $node)) {
			if (!is_array($node['all'])) {
				return false;
			}
			foreach ($node['all'] as $member) {
				if (!$this->matches($member, $fields, $effective)) {
					return false;
				}
			}
			return true;
		}

		if (array_key_exists('any', $node)) {
			if (!is_array($node['any'])) {
				return false;
			}
			foreach ($node['any'] as $member) {
				if ($this->matches($member, $fields, $effective)) {
					return true;
				}
			}
			return false;
		}

		$id = $node['field'] ?? null;
		$op = $node['op'] ?? null;
		if (!is_string($id) || !is_string($op) || !isset($fields[$id])) {
			return false;
		}
		$type = is_string($fields[$id]['type'] ?? null) ? $fields[$id]['type'] : '';
		if (!in_array($op, FieldType::operatorsFor($type), true)) {
			return false;
		}

		return Comparator::compare($type, $op, $effective[$id] ?? null, $node['value'] ?? null);
	}
}
