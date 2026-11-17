<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Settings;

use OCA\Webforms\AppInfo\Application;
use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Forms\DefinitionValidator;
use OCP\Config\IUserConfig;

/**
 * Per-user preferences (docs/SCHEMA.md section 16.1), kept in the user's own config so they go when the
 * account does. A saved preference replaces nothing for anyone else.
 *
 * @psalm-type Preferences = array{
 *     openPublishedOnResponses: bool,
 *     newFormLabelPosition: string,
 *     newFormAccentColor: string,
 *     newFormHideTitle: bool,
 *     newFormCaptcha: bool,
 *     responsesView: string,
 *     responsesSummary: bool,
 * }
 */
class UserPreferences {
	public const VIEWS = ['table', 'cards'];

	private const OPEN_PUBLISHED_ON_RESPONSES = 'open_published_on_responses';
	private const NEW_FORM_LABEL_POSITION = 'new_form_label_position';
	private const NEW_FORM_ACCENT_COLOR = 'new_form_accent_color';
	private const NEW_FORM_HIDE_TITLE = 'new_form_hide_title';
	private const NEW_FORM_CAPTCHA = 'new_form_captcha';
	private const RESPONSES_VIEW = 'responses_view';
	private const RESPONSES_SUMMARY = 'responses_summary';

	public function __construct(
		private readonly IUserConfig $config,
	) {
	}

	/** @return Preferences */
	public function forUser(string $userId): array {
		$app = Application::APP_ID;
		$label = $this->config->getValueString($userId, $app, self::NEW_FORM_LABEL_POSITION, 'above');
		$accent = $this->config->getValueString($userId, $app, self::NEW_FORM_ACCENT_COLOR, '');
		$view = $this->config->getValueString($userId, $app, self::RESPONSES_VIEW, 'table');
		return [
			'openPublishedOnResponses' => $this->config->getValueBool($userId, $app, self::OPEN_PUBLISHED_ON_RESPONSES, true),
			'newFormLabelPosition' => in_array($label, DefinitionValidator::LABEL_POSITIONS, true) ? $label : 'above',
			'newFormAccentColor' => self::isColor($accent) ? $accent : '',
			'newFormHideTitle' => $this->config->getValueBool($userId, $app, self::NEW_FORM_HIDE_TITLE, false),
			'newFormCaptcha' => $this->config->getValueBool($userId, $app, self::NEW_FORM_CAPTCHA, true),
			'responsesView' => in_array($view, self::VIEWS, true) ? $view : 'table',
			// The summary above the responses has always been open until someone closed it.
			'responsesSummary' => $this->config->getValueBool($userId, $app, self::RESPONSES_SUMMARY, true),
		];
	}

	/**
	 * Change some preferences and leave the rest. All of it is checked first, so a bad value changes nothing.
	 *
	 * @param array<string, mixed> $patch preference names as in `forUser()`
	 * @throws ValidationException
	 */
	public function save(string $userId, array $patch): void {
		$bad = self::invalid($patch);
		if ($bad !== []) {
			throw new ValidationException(array_map(static fn (string $name): array => ['code' => 'preference_invalid', 'path' => $name], $bad));
		}
		$app = Application::APP_ID;
		foreach ($patch as $name => $value) {
			match ($name) {
				'openPublishedOnResponses' => $this->config->setValueBool($userId, $app, self::OPEN_PUBLISHED_ON_RESPONSES, (bool)$value),
				'newFormLabelPosition' => $this->config->setValueString($userId, $app, self::NEW_FORM_LABEL_POSITION, (string)$value),
				'newFormAccentColor' => $this->config->setValueString($userId, $app, self::NEW_FORM_ACCENT_COLOR, strtolower((string)$value)),
				'newFormHideTitle' => $this->config->setValueBool($userId, $app, self::NEW_FORM_HIDE_TITLE, (bool)$value),
				'newFormCaptcha' => $this->config->setValueBool($userId, $app, self::NEW_FORM_CAPTCHA, (bool)$value),
				'responsesView' => $this->config->setValueString($userId, $app, self::RESPONSES_VIEW, (string)$value),
				'responsesSummary' => $this->config->setValueBool($userId, $app, self::RESPONSES_SUMMARY, (bool)$value),
				default => null,
			};
		}
	}

	/**
	 * The names in a change that are unknown or hold a value they cannot.
	 *
	 * @param array<string, mixed> $patch
	 * @return list<string>
	 */
	public static function invalid(array $patch): array {
		$bad = [];
		foreach ($patch as $name => $value) {
			$ok = match ($name) {
				'openPublishedOnResponses', 'newFormHideTitle', 'newFormCaptcha', 'responsesSummary' => is_bool($value),
				'newFormLabelPosition' => in_array($value, DefinitionValidator::LABEL_POSITIONS, true),
				// An empty colour means "the Nextcloud colour", which is what a form has without one.
				'newFormAccentColor' => $value === '' || self::isColor($value),
				'responsesView' => in_array($value, self::VIEWS, true),
				default => false,
			};
			if (!$ok) {
				$bad[] = (string)$name;
			}
		}
		return $bad;
	}

	private static function isColor(mixed $value): bool {
		return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
	}
}
