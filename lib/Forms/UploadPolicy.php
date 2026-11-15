<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Forms;

/**
 * The rules for upload fields that need no Nextcloud: which file types a field accepts,
 * defaults, and how names and folders are cleaned (docs/SCHEMA.md section 4.6).
 */
final class UploadPolicy {
	public const MAX_FILES = 10;
	public const MAX_SIZE_MB = 100;
	public const DEFAULT_MAX_FILES = 1;
	public const DEFAULT_MAX_SIZE_MB = 10;
	public const DEFAULT_ACCEPT = ['images', 'documents'];
	public const STORAGE_FILES = 'files';
	public const STORAGE_APP = 'app';
	public const AUDIENCE_ANYONE = 'anyone';
	public const AUDIENCE_LOGIN = 'login';
	public const DEFAULT_FOLDER_ROOT = 'Webforms';

	/**
	 * File types an owner can allow, by group.
	 *
	 * @var array<string, list<string>>
	 */
	public const GROUPS = [
		'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp', 'tif', 'tiff'],
		'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'rtf', 'csv', 'md'],
		'archives' => ['zip', '7z', 'tar', 'gz'],
		'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'flac'],
		'video' => ['mp4', 'mov', 'webm', 'mkv', 'avi'],
	];

	/**
	 * Never accepted, whatever the field allows: programs, scripts, and pages a browser would run.
	 *
	 * @var list<string>
	 */
	public const DENIED = [
		'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps',
		'exe', 'dll', 'msi', 'com', 'scr', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'hta', 'cpl', 'jar',
		'sh', 'bash', 'zsh', 'py', 'pl', 'rb', 'cgi', 'js', 'mjs', 'htm', 'html', 'xhtml', 'svg', 'swf',
		'htaccess', 'htpasswd', 'user.ini',
	];

	/**
	 * @param array<string, mixed> $field
	 */
	public static function maxFiles(array $field): int {
		return is_int($field['maxFiles'] ?? null) ? $field['maxFiles'] : self::DEFAULT_MAX_FILES;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function maxBytes(array $field): int {
		$mb = is_int($field['maxSizeMb'] ?? null) ? $field['maxSizeMb'] : self::DEFAULT_MAX_SIZE_MB;
		return $mb * 1024 * 1024;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function storage(array $field): string {
		return ($field['storage'] ?? null) === self::STORAGE_APP ? self::STORAGE_APP : self::STORAGE_FILES;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function audience(array $field): string {
		return ($field['audience'] ?? null) === self::AUDIENCE_LOGIN ? self::AUDIENCE_LOGIN : self::AUDIENCE_ANYONE;
	}

	/**
	 * The folder in the owner's Files, relative to their home, with the default filled in.
	 *
	 * @param array<string, mixed> $field
	 */
	public static function folder(array $field, string $formTitle): string {
		$folder = $field['folder'] ?? null;
		if (is_string($folder) && $folder !== '') {
			return self::cleanFolder($folder) ?? self::defaultFolder($formTitle);
		}
		return self::defaultFolder($formTitle);
	}

	public static function defaultFolder(string $formTitle): string {
		$name = self::safeName($formTitle);
		return self::DEFAULT_FOLDER_ROOT . '/' . ($name === '' ? 'Form' : mb_substr($name, 0, 80));
	}

	/**
	 * A folder path as a list of plain names joined by `/`, or null if it is not acceptable
	 * (empty parts, `.` or `..`, control characters, backslashes, or too long).
	 */
	public static function cleanFolder(string $path): ?string {
		$path = trim($path, '/ ');
		if ($path === '' || strlen($path) > 255 || preg_match('//u', $path) !== 1) {
			return null;
		}
		$parts = explode('/', $path);
		if (count($parts) > 8) {
			return null;
		}
		foreach ($parts as $part) {
			if ($part === '' || $part === '.' || $part === '..' || $part !== trim($part)
				|| preg_match('/[\x00-\x1F\x7F\\\\]/', $part) === 1) {
				return null;
			}
		}
		return implode('/', $parts);
	}

	/**
	 * Extensions a field accepts: its groups and extra `.ext` entries, minus the denied list.
	 *
	 * @param array<string, mixed> $field
	 * @return list<string>
	 */
	public static function allowedExtensions(array $field): array {
		$accept = is_array($field['accept'] ?? null) && $field['accept'] !== [] ? $field['accept'] : self::DEFAULT_ACCEPT;
		$extensions = [];
		foreach ($accept as $entry) {
			if (!is_string($entry)) {
				continue;
			}
			if (isset(self::GROUPS[$entry])) {
				array_push($extensions, ...self::GROUPS[$entry]);
			} elseif (preg_match('/^\.[a-z0-9]{1,10}$/D', $entry) === 1) {
				$extensions[] = substr($entry, 1);
			}
		}
		return array_values(array_diff(array_unique($extensions), self::DENIED));
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function accepts(array $field, string $filename): bool {
		$extension = self::extension($filename);
		return $extension !== '' && in_array($extension, self::allowedExtensions($field), true);
	}

	/**
	 * The last extension, lower case. Every dot-separated part is checked against the denied list
	 * elsewhere, so `shell.php.jpg` is refused by {@see hasDeniedPart}.
	 */
	public static function extension(string $filename): string {
		$dot = strrpos($filename, '.');
		return $dot === false ? '' : strtolower(substr($filename, $dot + 1));
	}

	/**
	 * `evil.php.jpg` is harmless to a correctly configured server and dangerous to a misconfigured
	 * one, so any part of the name that is a denied extension makes the file unacceptable.
	 */
	public static function hasDeniedPart(string $filename): bool {
		$parts = explode('.', strtolower($filename));
		array_shift($parts);
		foreach ($parts as $part) {
			if (in_array($part, self::DENIED, true)) {
				return true;
			}
		}
		return in_array(strtolower($filename), self::DENIED, true);
	}

	/**
	 * A name that is safe to show and to store: no path, no control characters, no leading dots,
	 * bounded length that keeps the extension.
	 */
	public static function safeName(string $name): string {
		$name = str_replace('\\', '/', $name);
		$name = basename($name);
		$name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name) ?? '';
		$name = trim(ltrim($name, '.'));
		if (mb_strlen($name) > 120) {
			$extension = self::extension($name);
			$stem = mb_substr($name, 0, 110 - strlen($extension));
			$name = $stem . ($extension !== '' ? '.' . $extension : '');
		}
		return $name;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return list<string> sorted group names the field offers, for the builder
	 */
	public static function groups(array $field): array {
		$accept = is_array($field['accept'] ?? null) ? $field['accept'] : [];
		$found = array_values(array_filter($accept, static fn (mixed $entry): bool => is_string($entry) && isset(self::GROUPS[$entry])));
		sort($found);
		return $found;
	}
}
