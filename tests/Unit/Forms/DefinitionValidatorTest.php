<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\DefinitionValidator;
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

	public function testSecondPageMayHoldFields(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$page = ['id' => 'p_2', 'title' => 'More', 'rows' => $d['pages'][0]['rows']];
			$d['pages'][0]['rows'] = [];
			$d['pages'][] = $page;
			return $d;
		}));
	}

	public function testCopiedPageRejected(): void {
		$codes = $this->codes(static function (array $d): array {
			$d['pages'][] = $d['pages'][0];
			return $d;
		});
		$this->assertContains('layout_duplicate_id', $codes);
		$this->assertContains('field_placed_twice', $codes);
	}

	public function testNoPagesRejected(): void {
		$this->assertSame(['pages_invalid'], $this->codes(static function (array $d): array {
			$d['pages'] = [];
			return $d;
		}));
	}

	public function testHideTitleSetting(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['settings']['hideTitle'] = true;
			return $d;
		}));
		$this->assertSame(['settings_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['hideTitle'] = 'yes';
			return $d;
		}));
	}

	public function testCaptchaSetting(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['settings']['captcha'] = false;
			return $d;
		}));
		$this->assertSame(['settings_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['captcha'] = 'off';
			return $d;
		}));
	}

	public function testHideRequiredIndicatorSetting(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['settings']['hideRequiredIndicator'] = true;
			return $d;
		}));
		$this->assertSame(['settings_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['hideRequiredIndicator'] = 'yes';
			return $d;
		}));
	}

	public function testDesignSettings(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['settings']['design'] = ['width' => 'wide', 'card' => false, 'background' => 'color', 'backgroundColor' => '#112233', 'accentColor' => '#FF8800', 'buttonColor' => '#00aa55'];
			return $d;
		}));
		$this->assertSame(['settings_invalid', 'settings_invalid', 'unknown_property'], $this->codes(static function (array $d): array {
			$d['settings']['design'] = ['width' => 'huge', 'accentColor' => 'red; background:url(x)', 'logo' => 'x'];
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

	public function testOptionValuesMayBeTwoCharactersButNotOne(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['fields']['f_kind']['options'][1]['value'] = 'us';
			$d['rules'][0]['when']['all'][0]['value'] = 'us';
			return $d;
		}));
		$this->assertContains('option_bad_value', $this->codes(static function (array $d): array {
			$d['fields']['f_kind']['options'][1]['value'] = 'u';
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
			$d['fields']['f_head'] = ['type' => 'heading', 'text' => 'Hi', 'level' => 7];
			$d['pages'][0]['rows'][] = ['id' => 'r_9', 'cells' => [['id' => 'c_9', 'span' => 12, 'field' => 'f_head']]];
			return $d;
		}));
	}

	public function testHeadingLevelsOneToSixAreAccepted(): void {
		foreach ([1, 2, 3, 4, 5, 6] as $level) {
			$this->assertSame([], $this->codes(static function (array $d) use ($level): array {
				$d['fields']['f_head'] = ['type' => 'heading', 'text' => 'Hi', 'level' => $level];
				$d['pages'][0]['rows'][] = ['id' => 'r_9', 'cells' => [['id' => 'c_9', 'span' => 12, 'field' => 'f_head']]];
				return $d;
			}), 'level ' . $level);
		}
	}

	/**
	 * @return array<string, array{array<string, mixed>, list<string>}>
	 */
	public static function typeProperties(): array {
		return [
			'text min length' => [['type' => 'text', 'label' => 'A', 'minLength' => 2, 'maxLength' => 5], []],
			'text min above max' => [['type' => 'text', 'label' => 'A', 'minLength' => 6, 'maxLength' => 5], ['field_limit_invalid']],
			'text min length zero' => [['type' => 'text', 'label' => 'A', 'minLength' => 0], ['field_limit_invalid']],
			'textarea rows' => [['type' => 'textarea', 'label' => 'A', 'rows' => 8], []],
			'textarea rows too many' => [['type' => 'textarea', 'label' => 'A', 'rows' => 21], ['field_limit_invalid']],
			'text has no rows' => [['type' => 'text', 'label' => 'A', 'rows' => 8], ['unknown_property']],
			'number affixes' => [['type' => 'number', 'label' => 'A', 'prefix' => '$', 'suffix' => 'kg'], []],
			'number affix too long' => [['type' => 'number', 'label' => 'A', 'prefix' => str_repeat('x', 17)], ['field_limit_invalid']],
			'date limits' => [['type' => 'date', 'label' => 'A', 'min' => '2026-01-01', 'max' => '2026-12-31'], []],
			'date limits reversed' => [['type' => 'date', 'label' => 'A', 'min' => '2026-12-31', 'max' => '2026-01-01'], ['field_limit_invalid']],
			'date limit not a date' => [['type' => 'date', 'label' => 'A', 'min' => '2026-02-30'], ['field_limit_invalid']],
			'time limits' => [['type' => 'time', 'label' => 'A', 'min' => '09:00', 'max' => '17:30'], []],
			'time limit bad' => [['type' => 'time', 'label' => 'A', 'max' => '25:00'], ['field_limit_invalid']],
			'radio columns' => [['type' => 'radio', 'label' => 'A', 'options' => [['value' => 'aa', 'label' => 'A']], 'columns' => 3], []],
			'checkboxes columns too many' => [['type' => 'checkboxes', 'label' => 'A', 'options' => [['value' => 'aa', 'label' => 'A']], 'columns' => 5], ['field_limit_invalid']],
			'select has no columns' => [['type' => 'select', 'label' => 'A', 'options' => [['value' => 'aa', 'label' => 'A']], 'columns' => 2], ['unknown_property']],
		];
	}

	/**
	 * @param array<string, mixed> $field
	 * @param list<string> $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('typeProperties')]
	public function testTypeSpecificProperties(array $field, array $expected): void {
		$this->assertSame($expected, $this->codes(static function (array $d) use ($field): array {
			$d['fields']['f_extra'] = $field;
			$d['pages'][0]['rows'][] = ['id' => 'r_9', 'cells' => [['id' => 'c_9', 'span' => 12, 'field' => 'f_extra']]];
			return $d;
		}));
	}

	public function testDividerStyle(): void {
		foreach (['solid' => [], 'dotted' => [], 'wavy' => ['field_limit_invalid']] as $style => $expected) {
			$this->assertSame($expected, $this->codes(static function (array $d) use ($style): array {
				$d['fields']['f_div'] = ['type' => 'divider', 'style' => $style];
				$d['pages'][0]['rows'][] = ['id' => 'r_9', 'cells' => [['id' => 'c_9', 'span' => 12, 'field' => 'f_div']]];
				return $d;
			}), $style);
		}
	}

	public function testTextAlignment(): void {
		$layout = static function (array $d, array $field): array {
			$d['fields']['f_head'] = $field;
			$d['pages'][0]['rows'][] = ['id' => 'r_9', 'cells' => [['id' => 'c_9', 'span' => 12, 'field' => 'f_head']]];
			return $d;
		};
		$this->assertSame([], $this->codes(static fn (array $d): array => $layout($d, ['type' => 'heading', 'text' => 'Hi', 'align' => 'center'])));
		$this->assertSame([], $this->codes(static fn (array $d): array => $layout($d, ['type' => 'paragraph', 'text' => 'Hi', 'align' => 'end'])));
		$this->assertSame(['field_align_invalid'], $this->codes(static fn (array $d): array => $layout($d, ['type' => 'heading', 'text' => 'Hi', 'align' => 'left'])));
		$this->assertSame(['unknown_property'], $this->codes(static fn (array $d): array => $layout($d, ['type' => 'divider', 'align' => 'center'])));
	}

	/**
	 * @param array<string, mixed> $field
	 * @return list<string>
	 */
	private function codesForUploadField(array $field): array {
		return $this->codes(static function (array $d) use ($field): array {
			$d['fields']['f_up'] = array_merge(['type' => 'file', 'label' => 'CV'], $field);
			$d['pages'][0]['rows'][] = ['id' => 'r_up', 'cells' => [['id' => 'c_up', 'span' => 12, 'field' => 'f_up']]];
			return $d;
		});
	}

	public function testUploadFieldSettings(): void {
		$this->assertSame([], $this->codesForUploadField([]));
		$this->assertSame([], $this->codesForUploadField(['maxFiles' => 5, 'maxSizeMb' => 50, 'accept' => ['images', '.dwg'], 'audience' => 'login', 'storage' => 'files', 'folder' => 'Work/CVs']));
		$this->assertSame([], $this->codesForUploadField(['storage' => 'app']));
	}

	public function testUploadFieldRefusals(): void {
		$this->assertSame(['field_limit_invalid'], $this->codesForUploadField(['maxFiles' => 11]));
		$this->assertSame(['field_limit_invalid'], $this->codesForUploadField(['maxFiles' => 0]));
		$this->assertSame(['field_limit_invalid'], $this->codesForUploadField(['maxSizeMb' => 101]));
		$this->assertSame(['field_upload_invalid'], $this->codesForUploadField(['audience' => 'everyone']));
		$this->assertSame(['field_upload_invalid'], $this->codesForUploadField(['storage' => 'cloud']));
		$this->assertSame(['field_upload_folder_invalid'], $this->codesForUploadField(['folder' => '../Secret']));
		$this->assertSame(['field_upload_accept_invalid'], $this->codesForUploadField(['accept' => []]));
		$this->assertSame(['field_upload_accept_invalid'], $this->codesForUploadField(['accept' => ['photos']]));
		$this->assertSame(['field_upload_type_denied'], $this->codesForUploadField(['accept' => ['.php']]));
		$this->assertSame(['field_default_invalid'], $this->codesForUploadField(['defaultValue' => 'x']));
		$this->assertSame(['unknown_property'], $this->codesForUploadField(['placeholder' => 'x']));
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

	public function testEmailActionOwnerAndRespondentRecipients(): void {
		foreach ([['owner' => true], ['respondent' => true]] as $to) {
			$this->assertSame([], $this->codes(static function (array $d) use ($to): array {
				$d['actions'][0]['to'] = $to;
				return $d;
			}));
		}
		foreach ([['owner' => false], ['owner' => 'yes'], ['respondent' => 1], ['owner' => true, 'respondent' => true], ['user' => 'alice']] as $to) {
			$this->assertSame(['action_bad_recipient'], $this->codes(static function (array $d) use ($to): array {
				$d['actions'][0]['to'] = $to;
				return $d;
			}));
		}
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

	public function testEveryLabelPositionIsAcceptedForTheFormAndForAField(): void {
		foreach (DefinitionValidator::LABEL_POSITIONS as $position) {
			$this->assertSame([], $this->codes(static function (array $d) use ($position): array {
				$d['settings']['labelPosition'] = $position;
				$d['fields']['f_email']['labelPosition'] = $position;
				return $d;
			}), $position);
		}
	}

	public function testUnknownFormLabelPositionIsRejected(): void {
		$this->assertSame(['settings_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['labelPosition'] = 'left';
			return $d;
		}));
	}

	public function testUnknownFieldLabelPositionIsRejected(): void {
		foreach (['left', '', true, 1, null, ['above']] as $bad) {
			$this->assertSame(['field_label_position_invalid'], $this->codes(static function (array $d) use ($bad): array {
				$d['fields']['f_email']['labelPosition'] = $bad;
				return $d;
			}), var_export($bad, true));
		}
	}

	public function testDisplayFieldsHaveNoLabelPosition(): void {
		$this->assertSame(['unknown_property'], $this->codes(static function (array $d): array {
			$d['fields']['f_note']['labelPosition'] = 'above';
			return $d;
		}));
	}

	public function testHideLabelStillWorksAlongsideLabelPosition(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['fields']['f_phone']['hideLabel'] = true;
			$d['fields']['f_phone']['labelPosition'] = 'inline';
			return $d;
		}));
	}
}
