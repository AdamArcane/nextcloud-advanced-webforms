<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Forms;

/**
 * A zip of every form a person owns, as the same files "Export draft" and "Export published version"
 * give one form at a time (docs/SCHEMA.md section 13.1). Each file can be brought back with Import form.
 */
final class FormBackup {
	/**
	 * @param list<array{id: int, title: string, draft: array<string, mixed>, published: array<string, mixed>|null}> $forms
	 *        `published` is the latest published version's definition, or null when the form was never published
	 * @return array<string, string> the files, by path inside the zip
	 */
	public static function files(array $forms, int $now): array {
		$root = self::root($now);
		$files = [];
		foreach ($forms as $form) {
			// The id keeps two forms with the same title apart.
			$base = $root . '/' . $form['id'] . '-' . self::slug($form['title']);
			$files[$base . '.draft.webforms.json'] = self::json(FormPackage::build($form['draft'], FormPackage::SOURCE_DRAFT, $now));
			if ($form['published'] !== null) {
				$files[$base . '.published.webforms.json'] = self::json(FormPackage::build($form['published'], FormPackage::SOURCE_PUBLISHED, $now));
			}
		}
		$files[$root . '/README.txt'] = self::readme(count($forms));
		return $files;
	}

	/** An ASCII name that cannot break out of a header. */
	public static function filename(int $now): string {
		return self::root($now) . '.zip';
	}

	/**
	 * @param array<string, string> $files
	 * @throws \RuntimeException
	 */
	public static function zip(array $files): string {
		$path = tempnam(sys_get_temp_dir(), 'wfb');
		if ($path === false) {
			throw new \RuntimeException('No temporary file');
		}
		try {
			$zip = new \ZipArchive();
			if ($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
				throw new \RuntimeException('Could not create the backup');
			}
			foreach ($files as $name => $content) {
				$zip->addFromString($name, $content);
			}
			if (!$zip->close()) {
				throw new \RuntimeException('Could not write the backup');
			}
			$bytes = file_get_contents($path);
			if ($bytes === false) {
				throw new \RuntimeException('Could not read the backup');
			}
			return $bytes;
		} finally {
			@unlink($path);
		}
	}

	private static function root(int $now): string {
		return 'webforms-backup-' . gmdate('Y-m-d', $now);
	}

	private static function slug(string $title): string {
		$slug = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-');
		return $slug === '' ? 'form' : substr($slug, 0, 60);
	}

	/**
	 * @param array<string, mixed> $package
	 */
	private static function json(array $package): string {
		return json_encode($package, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function readme(int $forms): string {
		return "Advanced Webforms backup of $forms form" . ($forms === 1 ? '' : 's') . ".\n\n"
			. "Each form has a .draft file (what you were editing) and, if it was ever published, a\n"
			. ".published file (the version people fill in).\n\n"
			. "To bring one back, open Advanced Webforms, choose Import form in the sidebar and pick the\n"
			. "file. It is added as a new draft form and never replaces an existing one.\n\n"
			. "Not included: responses (download them from each form's Responses page), uploaded files,\n"
			. "public links, and webhook secrets. A webhook comes back unsigned until you make a new secret.\n";
	}
}
