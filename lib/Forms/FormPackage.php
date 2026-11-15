<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Forms;

use OCA\Webforms\Exception\ValidationException;

/**
 * The file a form is exported to and imported from (docs/SCHEMA.md section 13).
 *
 * It wraps a definition with a format marker and version. Nothing instance
 * specific goes in (ids, owner, public link, versions, responses) and nothing
 * secret: a webhook's `secretRef` is dropped, because the secret it names is
 * stored on the exporting server and cannot be moved.
 */
final class FormPackage {
	public const FORMAT = 'webforms.form';
	public const FORMAT_VERSION = 1;

	public const SOURCE_DRAFT = 'draft';
	public const SOURCE_PUBLISHED = 'published';

	/**
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed>
	 */
	public static function build(array $definition, string $source, int $exportedAt): array {
		return [
			'format' => self::FORMAT,
			'formatVersion' => self::FORMAT_VERSION,
			'source' => $source,
			'exportedAt' => $exportedAt,
			'definition' => JsonShape::definition(self::withoutSecrets($definition)),
		];
	}

	/**
	 * Check the wrapper and return the definition inside it. The definition itself is not judged here:
	 * it is stored as a draft, and publishing is the gate.
	 *
	 * @param array<string, mixed> $package
	 * @return array<string, mixed>
	 * @throws ValidationException
	 */
	public static function parse(array $package): array {
		if (($package['format'] ?? null) !== self::FORMAT) {
			throw new ValidationException([['code' => 'import_not_a_form', 'path' => 'format']]);
		}
		$version = $package['formatVersion'] ?? null;
		if (!is_int($version) || $version < 1) {
			throw new ValidationException([['code' => 'import_not_a_form', 'path' => 'formatVersion']]);
		}
		if ($version > self::FORMAT_VERSION) {
			throw new ValidationException([['code' => 'import_too_new', 'path' => 'formatVersion']]);
		}
		$definition = $package['definition'] ?? null;
		if (!is_array($definition) || ($definition !== [] && array_is_list($definition))) {
			throw new ValidationException([['code' => 'import_not_a_form', 'path' => 'definition']]);
		}
		$schema = $definition['schemaVersion'] ?? null;
		if (!is_int($schema) || $schema < 1) {
			throw new ValidationException([['code' => 'import_not_a_form', 'path' => 'definition.schemaVersion']]);
		}
		if ($schema > DefinitionValidator::SCHEMA_VERSION) {
			throw new ValidationException([['code' => 'import_too_new', 'path' => 'definition.schemaVersion']]);
		}
		return self::withoutSecrets($definition);
	}

	/**
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed>
	 */
	public static function withoutSecrets(array $definition): array {
		$actions = $definition['actions'] ?? null;
		if (is_array($actions)) {
			foreach ($actions as $i => $action) {
				if (is_array($action)) {
					unset($action['secretRef']);
					$actions[$i] = $action;
				}
			}
			$definition['actions'] = $actions;
		}
		return $definition;
	}
}
