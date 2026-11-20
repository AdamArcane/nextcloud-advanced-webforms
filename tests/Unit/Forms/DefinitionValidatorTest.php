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

	public function testResponseLimitAndDates(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['settings'] += [
				'maxResponses' => 50,
				'showRemaining' => true,
				'opensAt' => 1800000000,
				'closesAt' => 1800003600,
				'notOpenMessage' => 'Registration opens on Monday.',
				'fullMessage' => 'All places are taken.',
			];
			return $d;
		}));
		foreach ([0, -1, 1000001, '50', 1.5, true] as $bad) {
			$this->assertSame(['settings_invalid'], $this->codes(static function (array $d) use ($bad): array {
				$d['settings']['maxResponses'] = $bad;
				return $d;
			}), var_export($bad, true));
		}
		foreach (['opensAt', 'closesAt'] as $key) {
			foreach ([0, -5, '2030-01-01T10:00', 32503680001] as $bad) {
				$this->assertSame(['settings_invalid'], $this->codes(static function (array $d) use ($key, $bad): array {
					$d['settings'][$key] = $bad;
					return $d;
				}), $key . ' ' . var_export($bad, true));
			}
		}
		$this->assertSame(['settings_invalid', 'settings_invalid', 'settings_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['showRemaining'] = 'yes';
			$d['settings']['notOpenMessage'] = str_repeat('a', 501);
			$d['settings']['fullMessage'] = 5;
			return $d;
		}));
	}

	public function testClosingMustComeAfterOpening(): void {
		$this->assertSame(['window_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['opensAt'] = 1800000000;
			$d['settings']['closesAt'] = 1800000000;
			return $d;
		}));
		$this->assertSame(['window_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['opensAt'] = 1800000000;
			$d['settings']['closesAt'] = 1799999999;
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
			$d['fields']['f_url']['type'] = 'hologram';
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
			'yes or no question' => [['type' => 'radio', 'label' => 'A', 'boolean' => true, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']], 'defaultValue' => false], []],
			'yes or no needs its two options' => [['type' => 'radio', 'label' => 'A', 'boolean' => true, 'options' => [['value' => 'aa', 'label' => 'A']]], ['options_invalid']],
			'yes or no flag must be a boolean' => [['type' => 'radio', 'label' => 'A', 'boolean' => 'yes', 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]], ['field_flag_invalid']],
			'yes or no default must be a boolean' => [['type' => 'radio', 'label' => 'A', 'boolean' => true, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']], 'defaultValue' => 'yes'], ['field_default_invalid']],
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

	public function testFilesActionPath(): void {
		$withPath = static fn (mixed $path): callable => static function (array $d) use ($path): array {
			$d['actions'] = [['id' => 'act_sheet', 'type' => 'files', 'path' => $path]];
			return $d;
		};
		$this->assertSame([], $this->codes($withPath('Webforms/Leads.csv')));
		$this->assertSame(['action_bad_file_path'], $this->codes($withPath('Webforms/Leads.xlsx')));
		$this->assertSame(['action_bad_file_path'], $this->codes($withPath('/Webforms/Leads.csv')));
		$this->assertSame(['action_bad_file_path'], $this->codes($withPath('../Leads.csv')));
		$this->assertSame(['action_bad_file_path'], $this->codes($withPath(12)));
	}

	public function testHiddenField(): void {
		$add = static fn (array $field): callable => static function (array $d) use ($field): array {
			$d['fields']['f_source'] = $field;
			$d['pages'][0]['rows'][] = ['id' => 'r_source', 'cells' => [['id' => 'c_source', 'span' => 12, 'field' => 'f_source']]];
			return $d;
		};
		$this->assertSame([], $this->codes($add(['type' => 'hidden', 'label' => 'Source', 'description' => 'From the newsletter link', 'defaultValue' => 'direct'])));
		$this->assertSame(['unknown_property'], $this->codes($add(['type' => 'hidden', 'label' => 'Source', 'prefill' => 'source'])));
		$this->assertSame(['field_label_invalid'], $this->codes($add(['type' => 'hidden'])));
		$this->assertSame(['unknown_property', 'unknown_property', 'unknown_property'], $this->codes($add(['type' => 'hidden', 'label' => 'S', 'required' => true, 'placeholder' => 'x', 'hideLabel' => true])));
		$this->assertSame(['field_default_invalid'], $this->codes($add(['type' => 'hidden', 'label' => 'S', 'defaultValue' => str_repeat('x', 2001)])));
	}

	public function testPrefillSetting(): void {
		$set = static fn (mixed $value): callable => static function (array $d) use ($value): array {
			$d['settings']['prefill'] = $value;
			return $d;
		};
		$this->assertSame([], $this->codes($set(false)));
		$this->assertSame([], $this->codes($set(true)));
		$this->assertSame(['settings_invalid'], $this->codes($set('no')));
	}

	public function testSaveProgressSetting(): void {
		$set = static fn (mixed $value): callable => static function (array $d) use ($value): array {
			$d['settings']['saveProgress'] = $value;
			return $d;
		};
		$this->assertSame([], $this->codes($set(true)));
		$this->assertSame([], $this->codes($set(false)));
		$this->assertSame(['settings_invalid'], $this->codes($set('device')));
	}

	public function testReviewStepSettings(): void {
		$set = static fn (string $key, mixed $value): callable => static function (array $d) use ($key, $value): array {
			$d['settings'][$key] = $value;
			return $d;
		};
		$this->assertSame([], $this->codes($set('reviewStep', true)));
		$this->assertSame([], $this->codes($set('reviewStep', false)));
		$this->assertSame(['settings_invalid'], $this->codes($set('reviewStep', 'yes')));
		$this->assertSame([], $this->codes($set('reviewTitle', 'Check it over')));
		$this->assertSame([], $this->codes($set('reviewTitle', str_repeat('x', 64))));
		$this->assertSame(['settings_invalid'], $this->codes($set('reviewTitle', str_repeat('x', 65))));
		$this->assertSame(['settings_invalid'], $this->codes($set('reviewTitle', 5)));
	}

	public function testDefaultValuesPerType(): void {
		$withDefault = static fn (array $field): callable => static function (array $d) use ($field): array {
			$d['fields']['f_extra'] = $field;
			$d['pages'][0]['rows'][] = ['id' => 'r_extra', 'cells' => [['id' => 'c_extra', 'span' => 12, 'field' => 'f_extra']]];
			return $d;
		};
		$options = [['value' => 'aaa', 'label' => 'A'], ['value' => 'bbb', 'label' => 'B']];
		foreach ([
			['type' => 'text', 'label' => 'L', 'defaultValue' => 'Ada'],
			['type' => 'number', 'label' => 'L', 'min' => 1, 'max' => 10, 'defaultValue' => 5],
			['type' => 'select', 'label' => 'L', 'options' => $options, 'defaultValue' => 'bbb'],
			['type' => 'checkboxes', 'label' => 'L', 'options' => $options, 'defaultValue' => ['aaa', 'bbb']],
			['type' => 'checkbox', 'label' => 'L', 'defaultValue' => true],
			['type' => 'date', 'label' => 'L', 'defaultValue' => '2026-12-01'],
			['type' => 'time', 'label' => 'L', 'defaultValue' => '09:30'],
		] as $field) {
			$this->assertSame([], $this->codes($withDefault($field)), $field['type']);
		}
		foreach ([
			['type' => 'number', 'label' => 'L', 'max' => 10, 'defaultValue' => 11],
			['type' => 'select', 'label' => 'L', 'options' => $options, 'defaultValue' => 'ccc'],
			['type' => 'date', 'label' => 'L', 'defaultValue' => '1/12/2026'],
		] as $field) {
			$this->assertSame(['field_default_invalid'], $this->codes($withDefault($field)), $field['type']);
		}
	}

	public function testRulesCannotTargetAHiddenField(): void {
		$this->assertContains('rule_target_hidden_field', $this->codes(static function (array $d): array {
			$d['fields']['f_source'] = ['type' => 'hidden', 'label' => 'Source'];
			$d['pages'][0]['rows'][] = ['id' => 'r_source', 'cells' => [['id' => 'c_source', 'span' => 12, 'field' => 'f_source']]];
			$d['rules'][0]['targets'] = ['f_source'];
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

	public function testReplyToIsOptionalAndWrittenLikeTheRecipient(): void {
		foreach (['desk@example.com', ['field' => 'f_email'], ['owner' => true], ['respondent' => true]] as $replyTo) {
			$this->assertSame([], $this->codes(static function (array $d) use ($replyTo): array {
				$d['actions'][0]['replyTo'] = $replyTo;
				return $d;
			}));
		}
		foreach (['', 'not an address', ['field' => 'f_name'], ['field' => 'f_missing'], ['owner' => false], ['user' => 'alice'], null, 5] as $replyTo) {
			$this->assertSame(['action_bad_reply_to'], $this->codes(static function (array $d) use ($replyTo): array {
				$d['actions'][0]['replyTo'] = $replyTo;
				return $d;
			}));
		}
	}

	public function testSenderNameIsOneLineOfPlainText(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['actions'][0]['fromName'] = 'Acme Support';
			return $d;
		}));
		foreach (['', '   ', "Acme\nBcc: x@example.com", str_repeat('a', 101), 5] as $name) {
			$this->assertSame(['action_from_name_invalid'], $this->codes(static function (array $d) use ($name): array {
				$d['actions'][0]['fromName'] = $name;
				return $d;
			}));
		}
	}

	public function testAllFieldsIsAllowedInAnEmailBodyOnly(): void {
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['actions'][0]['body'] = "New response:\n{all_fields}";
			return $d;
		}));
		$this->assertSame(['action_unknown_placeholder'], $this->codes(static function (array $d): array {
			$d['actions'][0]['subject'] = 'New {all_fields}';
			return $d;
		}));
	}

	public function testSubmitButtonAlignment(): void {
		foreach (['left', 'center', 'right', 'justify'] as $align) {
			$this->assertSame([], $this->codes(static function (array $d) use ($align): array {
				$d['settings']['design'] = ['submitAlign' => $align];
				return $d;
			}));
		}
		$this->assertSame(['settings_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['design'] = ['submitAlign' => 'middle'];
			return $d;
		}));
	}

	/**
	 * @param array<string, mixed> $field
	 * @return list<string>
	 */
	private function codesForField(array $field): array {
		return $this->codes(static function (array $d) use ($field): array {
			$d['fields']['f_new'] = $field + ['label' => 'New'];
			$d['pages'][0]['rows'][] = ['id' => 'r_new', 'cells' => [['id' => 'c_new', 'span' => 12, 'field' => 'f_new']]];
			return $d;
		});
	}

	public function testRatingScaleConsentAndDateTime(): void {
		$cols = [['value' => 'good', 'label' => 'Good'], ['value' => 'bad', 'label' => 'Bad']];
		$rows = [['value' => 'food', 'label' => 'Food']];
		$this->assertSame([], $this->codesForField(['type' => 'matrix', 'questions' => $rows, 'options' => $cols, 'multiple' => true]));
		$this->assertSame(['options_invalid'], $this->codesForField(['type' => 'matrix', 'options' => $cols]));
		$this->assertSame(['options_invalid'], $this->codesForField(['type' => 'matrix', 'questions' => $rows]));
		$this->assertSame(['field_flag_invalid'], $this->codesForField(['type' => 'matrix', 'questions' => $rows, 'options' => $cols, 'multiple' => 'yes']));
		$this->assertSame([], $this->codesForField(['type' => 'signature']));
		$this->assertSame(['field_default_invalid'], $this->codesForField(['type' => 'signature', 'defaultValue' => 'x']));
		$this->assertSame([], $this->codesForField(['type' => 'slider']));
		$this->assertSame([], $this->codesForField(['type' => 'slider', 'min' => 0, 'max' => 10, 'step' => 2, 'defaultValue' => 4]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'slider', 'min' => 5, 'max' => 5]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'slider', 'min' => 0, 'max' => 100000]));
		$this->assertSame(['field_default_invalid'], $this->codesForField(['type' => 'slider', 'defaultValue' => 101]));
		$this->assertSame([], $this->codesForField(['type' => 'color', 'defaultValue' => '#ff0000']));
		$this->assertSame(['field_default_invalid'], $this->codesForField(['type' => 'color', 'defaultValue' => 'red']));
		$this->assertSame([], $this->codesForField(['type' => 'daterange', 'min' => '2026-01-01', 'max' => '2026-12-31']));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'daterange', 'min' => 'soon']));
		$this->assertSame([], $this->codesForField(['type' => 'daterange', 'minDays' => 1, 'maxDays' => 14]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'daterange', 'minDays' => 5, 'maxDays' => 2]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'daterange', 'minDays' => -1]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'daterange', 'maxDays' => '7']));
		$this->assertSame([], $this->codesForField(['type' => 'name', 'parts' => ['first', 'middle', 'last']]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'name', 'parts' => ['street']]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'name', 'parts' => []]));
		$this->assertSame([], $this->codesForField(['type' => 'address', 'parts' => ['street', 'city']]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'address', 'parts' => ['first']]));
		$this->assertSame([], $this->codesForField(['type' => 'rating']));
		$this->assertSame([], $this->codesForField(['type' => 'rating', 'max' => 10, 'defaultValue' => 7]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'rating', 'max' => 11]));
		$this->assertSame(['field_default_invalid'], $this->codesForField(['type' => 'rating', 'defaultValue' => 6]));
		$this->assertSame(['unknown_property'], $this->codesForField(['type' => 'rating', 'min' => 0]));

		$this->assertSame([], $this->codesForField(['type' => 'scale', 'min' => 0, 'max' => 10, 'minLabel' => 'Not likely', 'maxLabel' => 'Very likely']));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'scale', 'min' => 2]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'scale', 'max' => 1]));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'scale', 'maxLabel' => str_repeat('x', 51)]));

		$this->assertSame([], $this->codesForField(['type' => 'consent', 'text' => 'I agree to the [terms](https://example.com).', 'required' => true]));
		$this->assertSame(['field_text_invalid'], $this->codesForField(['type' => 'consent']));
		$this->assertSame(['field_text_invalid'], $this->codesForField(['type' => 'consent', 'text' => ' ']));

		$this->assertSame([], $this->codesForField(['type' => 'datetime', 'min' => '2026-05-01T09:00', 'max' => '2026-05-31T17:00']));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'datetime', 'min' => '2026-05-01']));
		$this->assertSame(['field_limit_invalid'], $this->codesForField(['type' => 'datetime', 'min' => '2026-06-01T09:00', 'max' => '2026-05-31T17:00']));
	}

	public function testImageBlock(): void {
		$image = static fn (array $field): \Closure => static function (array $d) use ($field): array {
			$d['fields']['f_img'] = ['type' => 'image'] + $field;
			$d['pages'][0]['rows'][] = ['id' => 'r_img', 'cells' => [['id' => 'c_img', 'span' => 12, 'field' => 'f_img']]];
			return $d;
		};
		$this->assertSame([], $this->codes($image(['src' => 'Pictures/logo.png', 'alt' => 'Our logo', 'width' => 50, 'align' => 'center'])));
		$this->assertSame([], $this->codes($image(['src' => 'https://example.com/logo.png'])));
		$this->assertSame(['field_image_invalid'], $this->codes($image([])));
		$this->assertSame(['field_image_invalid'], $this->codes($image(['src' => 'http://example.com/logo.png'])));
		$this->assertSame(['field_image_invalid'], $this->codes($image(['src' => '../logo.png'])));
		$this->assertSame(['field_limit_invalid'], $this->codes($image(['src' => 'logo.png', 'width' => 33])));
		$this->assertSame(['unknown_property'], $this->codes($image(['src' => 'logo.png', 'label' => 'x'])));
	}

	public function testPageProgress(): void {
		foreach (['bar', 'steps', 'none'] as $style) {
			foreach (['step', 'title', 'percent', 'none'] as $label) {
				$this->assertSame([], $this->codes(static function (array $d) use ($style, $label): array {
					$d['settings']['design'] = ['progress' => $style, 'progressLabel' => $label];
					return $d;
				}));
			}
		}
		$this->assertSame(['settings_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['design'] = ['progress' => 'dots'];
			return $d;
		}));
		$this->assertSame(['settings_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['design'] = ['progressLabel' => true];
			return $d;
		}));
		$this->assertSame([], $this->codes(static function (array $d): array {
			$d['settings']['design'] = ['progressColor' => '#00aa55'];
			return $d;
		}));
		$this->assertSame(['settings_invalid'], $this->codes(static function (array $d): array {
			$d['settings']['design'] = ['progressColor' => 'green'];
			return $d;
		}));
	}

	public function testPlaceholderMustReferenceInputField(): void {
		$this->assertSame(['action_unknown_placeholder'], $this->codes(static function (array $d): array {
			$d['actions'][0]['body'] = 'Hi {f_note}';
			return $d;
		}));
	}

	public function testTablesActionShape(): void {
		$ok = static function (array $d): array {
			$d['actions'][] = ['id' => 'act_tbl', 'type' => 'tables', 'tableId' => 9, 'map' => ['11' => 'f_name', '12' => '@submitted']];
			return $d;
		};
		$this->assertSame([], $this->codes($ok));
		$this->assertSame(['action_bad_table'], $this->codes(static function (array $d) use ($ok): array {
			$d = $ok($d);
			$d['actions'][array_key_last($d['actions'])]['tableId'] = 0;
			return $d;
		}));
		$this->assertSame(['action_bad_table_map'], $this->codes(static function (array $d) use ($ok): array {
			$d = $ok($d);
			$d['actions'][array_key_last($d['actions'])]['map'] = [];
			return $d;
		}));
		// A source must be an input field or a meta name; a column id must be a number.
		$this->assertSame(['action_bad_table_map'], $this->codes(static function (array $d) use ($ok): array {
			$d = $ok($d);
			$d['actions'][array_key_last($d['actions'])]['map'] = ['11' => 'f_missing'];
			return $d;
		}));
		$this->assertSame(['action_bad_table_map'], $this->codes(static function (array $d) use ($ok): array {
			$d = $ok($d);
			$d['actions'][array_key_last($d['actions'])]['map'] = ['name' => 'f_name'];
			return $d;
		}));
		$this->assertSame(['unknown_property'], $this->codes(static function (array $d) use ($ok): array {
			$d = $ok($d);
			$d['actions'][array_key_last($d['actions'])]['table'] = 'x';
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
