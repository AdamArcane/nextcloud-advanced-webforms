<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Tests\Unit\Forms;

use OCA\AdvancedForms\Forms\DefinitionValidator;
use PHPUnit\Framework\TestCase;

class DefinitionValidatorTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	public static function specExample(): array {
		$spec = file_get_contents(__DIR__ . '/../../../docs/SCHEMA.md');
		preg_match('/```json\n(.*?)```/s', (string)$spec, $m);
		return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param callable(array<string, mixed>): array<string, mixed> $mutate
	 * @return list<string>
	 */
	private function codes(callable $mutate): array {
		$errors = (new DefinitionValidator())->validate($mutate(self::specExample()));
		$codes = array_map(static fn (array $e): string => $e['code'], $errors);
		sort($codes);
		return $codes;
	}

	public function testSpecExampleIsValid(): void {
		$this->assertSame([], (new DefinitionValidator())->validate(self::specExample()));
	}

	public function testUnknownTopLevelProperty(): void {
		$this->assertSame(['unknown_property'], $this->codes(static function (array $d): array {
			$d['theme'] = 'dark';
			return $d;
		}));
	}

	public function testWrongSchemaVersion(): void {
		$this->assertSame(['schema_version_unsupported'], $this->codes(static function (array $d): array {
			$d['schemaVersion'] = 2;
			return $d;
		}));
	}

	public function testEmptyTitle(): void {
		$this->assertSame(['title_invalid'], $this->codes(static function (array $d): array {
			$d['title'] = '  ';
			return $d;
		}));
	}

	public function testTwoPagesRejected(): void {
		$this->assertSame(['pages_must_be_single'], $this->codes(static function (array $d): array {
			$d['pages'][] = $d['pages'][0];
			return $d;
		}));
	}

	public function testBadSpan(): void {
		$this->assertSame(['cell_bad_span'], $this->codes(static function (array $d): array {
			$d['pages'][0]['rows'][0]['cells'][0]['span'] = 5;
			return $d;
		}));
	}

	public function testRowTooWide(): void {
		$this->assertSame(['row_too_wide'], $this->codes(static function (array $d): array {
			$d['pages'][0]['rows'][0]['cells'][0]['span'] = 8;
			return $d;
		}));
	}

	public function testFieldNotPlaced(): void {
		$this->assertSame(['field_not_placed'], $this->codes(static function (array $d): array {
			$d['fields']['f_extra'] = ['type' => 'text', 'label' => 'Extra'];
			return $d;
		}));
	}

	public function testFieldPlacedTwice(): void {
		// f_url's cell now points at f_name, so f_name is placed twice and f_url nowhere.
		$this->assertSame(['field_not_placed', 'field_placed_twice'], $this->codes(static function (array $d): array {
			$d['pages'][0]['rows'][2]['cells'][0]['field'] = 'f_name';
			return $d;
		}));
	}

	public function testCellPointsAtUnknownField(): void {
		$codes = $this->codes(static function (array $d): array {
			$d['pages'][0]['rows'][2]['cells'][0]['field'] = 'f_ghost';
			return $d;
		});
		$this->assertContains('cell_unknown_field', $codes);
		$this->assertContains('field_not_placed', $codes);
	}

	public function testDuplicateLayoutId(): void {
		$this->assertSame(['layout_duplicate_id'], $this->codes(static function (array $d): array {
			$d['pages'][0]['rows'][1]['id'] = 'r_1';
			return $d;
		}));
	}

	public function testUnknownFieldType(): void {
		$codes = $this->codes(static function (array $d): array {
			$d['fields']['f_url']['type'] = 'signature';
			return $d;
		});
		$this->assertContains('field_unknown_type', $codes);
	}

	public function testLabelRequired(): void {
		$this->assertSame(['field_label_invalid'], $this->codes(static function (array $d): array {
			unset($d['fields']['f_name']['label']);
			return $d;
		}));
	}

	public function testPlaceholderNotAllowedOnCheckbox(): void {
		$this->assertSame(['unknown_property'], $this->codes(static function (array $d): array {
			$d['fields']['f_agree'] = ['type' => 'checkbox', 'label' => 'Agree', 'placeholder' => 'x'];
			$d['pages'][0]['rows'][] = ['id' => 'r_9', 'cells' => [['id' => 'c_9', 'span' => 12, 'field' => 'f_agree']]];
			return $d;
		}));
	}

	public function testDuplicateOptionValue(): void {
		$this->assertSame(['option_duplicate_value'], $this->codes(static function (array $d): array {
			$d['fields']['f_kind']['options'][1]['value'] = 'new';
			// The rule compares against "support", which no longer exists.
			$d['rules'][0]['when']['all'][0]['value'] = 'new';
			return $d;
		}));
	}

	public function testSelectNeedsOptions(): void {
		$codes = $this->codes(static function (array $d): array {
			$d['fields']['f_kind']['options'] = [];
			return $d;
		});
		$this->assertContains('options_invalid', $codes);
	}

	public function testNumberLimitsMustBeOrdered(): void {
		$this->assertSame(['field_limit_invalid'], $this->codes(static function (array $d): array {
			$d['fields']['f_qty'] = ['type' => 'number', 'label' => 'Qty', 'min' => 10, 'max' => 1];
			$d['pages'][0]['rows'][] = ['id' => 'r_9', 'cells' => [['id' => 'c_9', 'span' => 12, 'field' => 'f_qty']]];
			return $d;
		}));
	}

	public function testDefaultValueMustBeAcceptable(): void {
		$this->assertSame(['field_default_invalid'], $this->codes(static function (array $d): array {
			$d['fields']['f_kind']['defaultValue'] = 'nope';
			return $d;
		}));
	}

	public function testHeadingLevel(): void {
		$this->assertSame(['field_level_invalid'], $this->codes(static function (array $d): array {
			$d['fields']['f_head'] = ['type' => 'heading', 'text' => 'Hi', 'level' => 1];
			$d['pages'][0]['rows'][] = ['id' => 'r_9', 'cells' => [['id' => 'c_9', 'span' => 12, 'field' => 'f_head']]];
			return $d;
		}));
	}

	public function testRuleErrorsAreIncluded(): void {
		$this->assertContains('rule_unknown_target', $this->codes(static function (array $d): array {
			$d['rules'][0]['targets'] = ['f_ghost'];
			return $d;
		}));
	}

	public function testTooManyFields(): void {
		$this->assertContains('too_many_fields', $this->codes(static function (array $d): array {
			for ($i = 0; $i < 101; $i++) {
				$id = 'f_x' . $i;
				$d['fields'][$id] = ['type' => 'divider'];
				$d['pages'][0]['rows'][] = ['id' => 'r_x' . $i, 'cells' => [['id' => 'c_x' . $i, 'span' => 12, 'field' => $id]]];
			}
			return $d;
		}));
	}

	public function testEmailActionNeedsEmailField(): void {
		$this->assertSame(['action_bad_recipient'], $this->codes(static function (array $d): array {
			$d['actions'][0]['to'] = ['field' => 'f_name'];
			return $d;
		}));
	}

	public function testEmailActionStaticRecipient(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['actions'][0]['to'] = 'owner@example.com';
			return $d;
		}));
	}

	public function testPlaceholderMustReferenceInputField(): void {
		$this->assertSame(['action_unknown_placeholder'], $this->codes(static function (array $d): array {
			$d['actions'][0]['body'] = 'Hi {f_note}';
			return $d;
		}));
	}

	public function testWebhookMustBeHttpsWithoutPlaceholders(): void {
		$this->assertSame(['action_bad_url'], $this->codes(static function (array $d): array {
			$d['actions'][1]['url'] = 'http://n8n.example.com/hook';
			return $d;
		}));
		$this->assertSame(['action_bad_url'], $this->codes(static function (array $d): array {
			$d['actions'][1]['url'] = 'https://n8n.example.com/hook/{f_name}';
			return $d;
		}));
		$this->assertSame(['action_bad_url'], $this->codes(static function (array $d): array {
			$d['actions'][1]['url'] = 'https://user:pw@n8n.example.com/hook';
			return $d;
		}));
	}

	public function testRedirectPlaceholderNotAllowedInHost(): void {
		$this->assertSame(['action_bad_url'], $this->codes(static function (array $d): array {
			$d['actions'][] = ['id' => 'act_9', 'type' => 'redirect', 'url' => 'https://{f_name}.example.com/'];
			return $d;
		}));
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['actions'][] = ['id' => 'act_9', 'type' => 'redirect', 'url' => 'https://example.com/thanks?n={f_name}'];
			return $d;
		}));
	}

	public function testActionConditionIsValidated(): void {
		$this->assertContains('cond_unknown_field', $this->codes(static function (array $d): array {
			$d['actions'][0]['when'] = ['all' => [['field' => 'f_ghost', 'op' => 'eq', 'value' => 'x']]];
			return $d;
		}));
	}

	public function testDuplicateActionId(): void {
		$this->assertSame(['action_duplicate_id'], $this->codes(static function (array $d): array {
			$d['actions'][1]['id'] = 'act_1';
			return $d;
		}));
	}
}
