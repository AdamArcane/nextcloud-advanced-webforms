<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Rules\FieldType;

/**
 * What the read-only API says about a form and a response (docs/SCHEMA.md section 23). Each shape lists the
 * keys it sends, so a field added to the app's own arrays later is never sent by accident.
 */
final class ApiShape {
	/** The words for a form's status. */
	public const STATUSES = [0 => 'draft', 1 => 'published', 2 => 'closed'];

	/**
	 * A form as the API shows it. Nothing about the link, address, password or embedding, whatever the role.
	 *
	 * @param array<string, mixed> $described the app's own description of the form
	 * @return array<string, mixed>
	 */
	public static function form(array $described, int $responses): array {
		$status = $described['status'] ?? 0;
		return [
			'id' => $described['id'] ?? null,
			'title' => $described['title'] ?? '',
			'description' => $described['description'] ?? null,
			'status' => self::STATUSES[$status] ?? 'draft',
			'role' => $described['role'] ?? 'reviewer',
			'ownerId' => $described['ownerId'] ?? null,
			'ownerName' => $described['ownerName'] ?? null,
			'latestVersionId' => $described['latestVersionId'] ?? null,
			'createdAt' => $described['createdAt'] ?? null,
			'updatedAt' => $described['updatedAt'] ?? null,
			'responses' => $responses,
			'availability' => $described['availability'] ?? null,
		];
	}

	/**
	 * One response. The owner's private note is not part of it.
	 *
	 * @param array<string, mixed> $data the stored answers, by field id
	 * @param array<string, array<string, mixed>> $fields the fields of the version it answered
	 * @return array<string, mixed>
	 */
	public static function response(int $id, int $versionId, ?string $userId, int $submittedAt, ?string $status, array $data, array $fields): array {
		return [
			'id' => $id,
			'versionId' => $versionId,
			'submittedAt' => $submittedAt,
			'respondent' => $userId,
			'status' => $status,
			'answers' => JsonShape::map(self::answers($data, $fields)),
		];
	}

	/**
	 * The answers as stored, except that an uploaded file is its name, size and type only (never where it is
	 * kept), and a signature is just whether there is one.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, array<string, mixed>> $fields
	 * @return array<string, mixed>
	 */
	public static function answers(array $data, array $fields): array {
		$answers = [];
		foreach ($data as $id => $value) {
			$type = $fields[$id]['type'] ?? null;
			if ($type === FieldType::FILE) {
				$answers[$id] = self::files($value);
			} elseif ($type === FieldType::SIGNATURE) {
				if (is_string($value) && $value !== '') {
					$answers[$id] = true;
				}
			} else {
				$answers[$id] = $value;
			}
		}
		return $answers;
	}

	/**
	 * @return list<array{name: string, size: int, mime: string}>
	 */
	private static function files(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}
		$files = [];
		foreach ($value as $file) {
			if (is_array($file) && is_string($file['name'] ?? null)) {
				$files[] = [
					'name' => $file['name'],
					'size' => is_int($file['size'] ?? null) ? $file['size'] : 0,
					'mime' => is_string($file['mime'] ?? null) ? $file['mime'] : '',
				];
			}
		}
		return $files;
	}
}
