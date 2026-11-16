<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Event;

use OCA\Webforms\Actions\ActionContext;
use OCA\Webforms\Actions\ActionRunner;

/**
 * The data each Nextcloud event carries (docs/SCHEMA.md section 14). Plain arrays, no framework
 * classes, so it is unit tested. Every payload has `event`, `formId`, `formTitle` and `ownerId`.
 */
final class EventPayload {
	public const ENTRY_CREATED = 'entry.created';
	public const ENTRY_DELETED = 'entry.deleted';
	public const ENTRY_STATUS_CHANGED = 'entry.status_changed';
	public const FORM_PUBLISHED = 'form.published';
	public const FORM_STATUS_CHANGED = 'form.status_changed';

	/** Form::STATUS_* values, as the names a flow reads. */
	private const STATUS_NAMES = [0 => 'unpublished', 1 => 'published', 2 => 'closed'];

	/**
	 * The webhook body of section 7.3 plus the form's title and owner. Answers are the visible
	 * fields only, and uploaded files are described by name, size and type, never by location.
	 *
	 * @param array<string, array<string, mixed>> $fields the version's fields
	 * @param array<string, mixed> $data the entry's answers
	 * @return array<string, mixed>
	 */
	public static function entryCreated(
		int $formId,
		string $formTitle,
		string $ownerId,
		int $versionId,
		int $entryId,
		?string $userId,
		int $submittedAt,
		array $fields,
		array $data,
	): array {
		$body = ActionRunner::payload(new ActionContext(
			formId: $formId,
			versionId: $versionId,
			entryId: $entryId,
			logId: 0,
			userId: $userId,
			submittedAt: $submittedAt,
			fields: $fields,
			data: $data,
			now: $submittedAt,
			ownerId: $ownerId,
		));
		return self::withForm($body, $formTitle, $ownerId);
	}

	/**
	 * Carries no answers: the response is gone, and so are its files.
	 *
	 * @return array<string, mixed>
	 */
	public static function entryDeleted(int $formId, string $formTitle, string $ownerId, int $versionId, int $entryId, string $deletedBy): array {
		return self::withForm([
			'event' => self::ENTRY_DELETED,
			'formId' => $formId,
			'versionId' => $versionId,
			'entryId' => $entryId,
			'deletedBy' => $deletedBy,
		], $formTitle, $ownerId);
	}

	/**
	 * Carries no answers and no note. A status is the id of one of the administrator's statuses, or null
	 * for none (docs/SCHEMA.md section 20.2).
	 *
	 * @return array<string, mixed>
	 */
	public static function entryStatusChanged(int $formId, string $formTitle, string $ownerId, int $versionId, int $entryId, ?string $status, ?string $previousStatus, string $changedBy): array {
		return self::withForm([
			'event' => self::ENTRY_STATUS_CHANGED,
			'formId' => $formId,
			'versionId' => $versionId,
			'entryId' => $entryId,
			'status' => $status,
			'previousStatus' => $previousStatus,
			'changedBy' => $changedBy,
		], $formTitle, $ownerId);
	}

	/**
	 * @param bool $newVersion false when publishing an unchanged draft reused the latest version
	 * @return array<string, mixed>
	 */
	public static function formPublished(int $formId, string $formTitle, string $ownerId, int $versionId, int $versionNumber, bool $newVersion, ?string $publicToken): array {
		return self::withForm([
			'event' => self::FORM_PUBLISHED,
			'formId' => $formId,
			'versionId' => $versionId,
			'versionNumber' => $versionNumber,
			'newVersion' => $newVersion,
			'publicToken' => $publicToken,
		], $formTitle, $ownerId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function formStatusChanged(int $formId, string $formTitle, string $ownerId, int $status, int $previousStatus): array {
		return self::withForm([
			'event' => self::FORM_STATUS_CHANGED,
			'formId' => $formId,
			'status' => self::STATUS_NAMES[$status] ?? 'unknown',
			'previousStatus' => self::STATUS_NAMES[$previousStatus] ?? 'unknown',
		], $formTitle, $ownerId);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private static function withForm(array $body, string $formTitle, string $ownerId): array {
		return $body + ['formTitle' => $formTitle, 'ownerId' => $ownerId];
	}
}
