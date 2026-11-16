<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Rules\FieldType;
use OCA\Webforms\Rules\RuleEngine;

/**
 * Server-side check of a submission against a published definition
 * (docs/SCHEMA.md section 10, steps 3 to 7). The server never trusts the
 * client's idea of visibility: it recomputes it from the submitted values.
 */
final class SubmissionValidator {
	public function __construct(
		private readonly RuleEngine $engine = new RuleEngine(),
	) {
	}

	/**
	 * @param array<string, mixed> $definition a published form definition
	 * @param array<string, mixed> $payload submitted values keyed by field ID
	 * @return array{data: array<string, mixed>, errors: list<array{field: string, code: string}>}
	 *   `data` holds canonical values of visible, answered fields only. It is
	 *   meaningless when `errors` is not empty.
	 */
	public function validate(array $definition, array $payload): array {
		/** @var array<string, array<string, mixed>> $fields */
		$fields = $definition['fields'];
		$errors = [];

		foreach (array_keys($payload) as $id) {
			$id = (string)$id;
			$type = $fields[$id]['type'] ?? null;
			if (!is_string($type) || !isset(FieldType::OPERATORS[$type])) {
				$errors[] = ['field' => $id, 'code' => 'unknown_field'];
			}
		}

		$state = $this->engine->evaluate($definition, $payload);
		$visible = array_flip($state['visible']);
		$required = array_flip($state['required']);

		$data = [];
		foreach ($fields as $id => $field) {
			$id = (string)$id;
			$type = (string)($field['type'] ?? '');
			// Hidden fields and display fields never carry a value.
			if (!isset($visible[$id]) || !isset(FieldType::OPERATORS[$type])) {
				continue;
			}

			[$value, $error] = FieldValueValidator::clean($field, $payload[$id] ?? null);
			if ($error !== null) {
				$errors[] = ['field' => $id, 'code' => $error];
				continue;
			}

			// A required checkbox or consent must be ticked; for other types, empty is missing.
			$missing = $value === null || (FieldType::isBoolean($type) && $value === false);
			if ($missing && isset($required[$id])) {
				$errors[] = ['field' => $id, 'code' => 'required'];
				continue;
			}

			if ($value !== null) {
				$data[$id] = $value;
			}
		}

		return ['data' => $data, 'errors' => $errors];
	}
}
