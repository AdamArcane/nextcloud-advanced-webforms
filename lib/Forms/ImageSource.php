<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

/**
 * Where an image block's picture comes from (docs/SCHEMA.md section 4.9): an `https://` address, or
 * a path in the form owner's Files. Nothing here touches a file; it only reads the `src` text.
 */
final class ImageSource {
	public const REMOTE = 'remote';
	public const FILES = 'files';
	/** What respondents get in place of a path in the owner's Files, which is not theirs to see. */
	public const FILES_MARKER = '@files';
	/** The largest picture served from the owner's Files. */
	public const MAX_BYTES = 10 * 1024 * 1024;
	public const MAX_LENGTH = 1000;

	/**
	 * Extensions a picture in Files may have, and the type each is served as. No SVG: it can carry
	 * script, and it would be served from this site's own address.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
	];

	/** `remote`, `files`, or null when the text is neither a usable address nor a usable path. */
	public static function kind(mixed $src): ?string {
		if (!is_string($src) || $src === '' || strlen($src) > self::MAX_LENGTH || preg_match('//u', $src) !== 1) {
			return null;
		}
		if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $src) === 1) {
			return self::remoteHost($src) !== null ? self::REMOTE : null;
		}
		return self::filesPath($src) !== null ? self::FILES : null;
	}

	/** The host of an `https://` picture, lower case, or null when the address is not one. */
	public static function remoteHost(string $src): ?string {
		if (!str_starts_with(strtolower($src), 'https://') || preg_match('/[\s<>"\'\\\\]/u', $src) === 1) {
			return null;
		}
		$parts = parse_url($src);
		$host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
		if ($host === '' || isset($parts['user']) || isset($parts['pass']) || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1 || !str_contains($host, '.')) {
			return null;
		}
		return $host;
	}

	/**
	 * The path below the owner's home, as plain names joined by `/`, or null when it is not a path to
	 * a picture: a `.` or `..` part, a control character, or an extension that is not in TYPES.
	 */
	public static function filesPath(string $src): ?string {
		$parts = [];
		foreach (explode('/', trim($src, '/')) as $part) {
			if ($part === '' || $part === '.' || $part === '..' || preg_match('/[\x00-\x1F\x7F\\\\]/', $part) === 1) {
				return null;
			}
			$parts[] = $part;
		}
		$extension = strtolower(pathinfo((string)end($parts), PATHINFO_EXTENSION));
		return $parts !== [] && isset(self::TYPES[$extension]) ? implode('/', $parts) : null;
	}

	/** The type a picture in Files is served as, decided by its name alone. */
	public static function mime(string $path): ?string {
		return self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
	}

	/**
	 * The hosts of the remote pictures in a definition, for the page's content security policy.
	 *
	 * @param array<string, mixed> $definition
	 * @return list<string>
	 */
	public static function remoteHosts(array $definition): array {
		$hosts = [];
		foreach (is_array($definition['fields'] ?? null) ? $definition['fields'] : [] as $field) {
			if (is_array($field) && ($field['type'] ?? null) === 'image' && is_string($field['src'] ?? null)) {
				$host = self::remoteHost($field['src']);
				if ($host !== null) {
					$hosts[$host] = true;
				}
			}
		}
		return array_map(strval(...), array_keys($hosts));
	}
}
