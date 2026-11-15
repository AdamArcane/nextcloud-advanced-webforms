<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Uploads;

use OCA\Webforms\Forms\UploadPolicy;
use OCA\Webforms\Rules\FieldType;
use OCA\Webforms\Uploads\Ports\MimePort;
use OCA\Webforms\Uploads\Ports\OwnerFilesPort;
use OCA\Webforms\Uploads\Ports\PendingStorePort;
use OCA\Webforms\Uploads\Ports\TokenPort;
use OCA\Webforms\Uploads\Ports\UploadRegistryPort;

/**
 * Receiving, claiming and removing uploaded files (docs/SCHEMA.md section 4.6).
 *
 * A file is uploaded on its own first and waits as a pending upload. When the form is
 * submitted, `claim` swaps the upload ids in the answers for stored files. Anything that is
 * never claimed is removed by `cleanup`.
 */
final class UploadService {
	/** Per form, so one visitor cannot fill the disk with uploads that are never submitted. */
	public const MAX_PENDING_BYTES = 200 * 1024 * 1024;
	/** A little longer than the fill token's lifetime, so a form left open overnight still works. */
	public const PENDING_TTL_SECONDS = 25 * 3600;

	/** Content that is never accepted, whatever the file is called. */
	private const DENIED_MIME = [
		'application/x-dosexec', 'application/x-executable', 'application/x-sharedlib', 'application/x-msdownload',
		'application/x-httpd-php', 'text/x-php', 'text/html', 'application/xhtml+xml', 'image/svg+xml',
		'application/javascript', 'text/javascript', 'text/x-shellscript', 'application/x-sh',
	];

	public function __construct(
		private readonly UploadRegistryPort $registry,
		private readonly PendingStorePort $store,
		private readonly OwnerFilesPort $files,
		private readonly TokenPort $tokens,
		private readonly MimePort $mimes,
	) {
	}

	/**
	 * @param array<string, mixed> $field the upload field's definition
	 * @return array{id: string, name: string, size: int}
	 * @throws UploadRejected
	 */
	public function receive(int $formId, string $fieldId, array $field, ?string $userId, string $clientName, string $localPath, int $size, int $now): array {
		if (($field['type'] ?? null) !== FieldType::FILE) {
			throw new UploadRejected('upload_not_a_file_field', $fieldId);
		}
		if (UploadPolicy::audience($field) === UploadPolicy::AUDIENCE_LOGIN && $userId === null) {
			throw new UploadRejected('upload_login_required', $fieldId);
		}
		$name = UploadPolicy::safeName($clientName);
		if ($name === '') {
			throw new UploadRejected('upload_bad_name', $fieldId);
		}
		if (UploadPolicy::hasDeniedPart($name) || !UploadPolicy::accepts($field, $name)) {
			throw new UploadRejected('upload_type_not_allowed', $fieldId);
		}
		if ($size < 1) {
			throw new UploadRejected('upload_empty', $fieldId);
		}
		if ($size > UploadPolicy::maxBytes($field)) {
			throw new UploadRejected('upload_too_large', $fieldId);
		}
		if ($this->registry->pendingBytes($formId) + $size > self::MAX_PENDING_BYTES) {
			throw new UploadRejected('upload_busy', $fieldId);
		}
		$mime = $this->mimes->detect($localPath, $name);
		if (in_array($mime, self::DENIED_MIME, true)) {
			throw new UploadRejected('upload_type_not_allowed', $fieldId);
		}

		$token = $this->tokens->newToken();
		$this->store->put($token, $localPath);
		$this->registry->add(new PendingUpload($token, $formId, $fieldId, $userId, $name, $size, $mime, $now));
		return ['id' => $token, 'name' => $name, 'size' => $size];
	}

	/**
	 * Turn the upload ids in validated answers into stored files. Nothing is kept if any file cannot
	 * be stored; the respondent can then submit again with the same uploads.
	 *
	 * @param array<string, array<string, mixed>> $fields the definition's fields
	 * @param array<string, mixed> $data validated answers, upload fields hold lists of upload ids
	 * @return array<string, mixed> the answers with each upload field holding a list of stored file descriptors
	 * @throws UploadRejected
	 */
	public function claim(int $formId, string $ownerId, string $formTitle, array $fields, array $data, ?string $userId, int $now): array {
		/** @var list<array{field: string, pending: PendingUpload, config: array<string, mixed>}> $work */
		$work = [];
		foreach ($data as $fieldId => $value) {
			$field = $fields[$fieldId] ?? null;
			if (!is_array($field) || ($field['type'] ?? null) !== FieldType::FILE || !is_array($value)) {
				continue;
			}
			$fieldId = (string)$fieldId;
			if (UploadPolicy::audience($field) === UploadPolicy::AUDIENCE_LOGIN && $userId === null) {
				throw new UploadRejected('upload_login_required', $fieldId);
			}
			foreach ($value as $token) {
				$pending = is_string($token) ? $this->registry->find($token) : null;
				if ($pending === null || $pending->formId !== $formId || $pending->fieldId !== $fieldId) {
					throw new UploadRejected('upload_missing', $fieldId);
				}
				$work[] = ['field' => $fieldId, 'pending' => $pending, 'config' => $field];
			}
			if (UploadPolicy::storage($field) === UploadPolicy::STORAGE_FILES) {
				$this->requireDestination($ownerId, $formTitle, $field, $fieldId);
			}
		}
		if ($work === []) {
			return $data;
		}

		$subfolder = 'Response ' . gmdate('Y-m-d H.i.s', $now) . ' ' . substr($work[0]['pending']->token, 0, 4);
		$descriptors = [];
		$storedInFiles = [];
		try {
			foreach ($work as $item) {
				$pending = $item['pending'];
				if (UploadPolicy::storage($item['config']) === UploadPolicy::STORAGE_APP) {
					$descriptors[$item['field']][] = self::descriptor($pending, UploadPolicy::STORAGE_APP, $pending->token);
					continue;
				}
				$stream = $this->store->read($pending->token);
				if ($stream === null) {
					throw new UploadRejected('upload_missing', $item['field']);
				}
				try {
					$fileId = $this->files->store($ownerId, UploadPolicy::folder($item['config'], $formTitle), $subfolder, $pending->name, $stream);
				} finally {
					if (is_resource($stream)) {
						fclose($stream);
					}
				}
				$storedInFiles[] = $fileId;
				$descriptors[$item['field']][] = self::descriptor($pending, UploadPolicy::STORAGE_FILES, (string)$fileId);
			}
		} catch (UploadRejected $e) {
			$this->rollback($ownerId, $storedInFiles);
			throw $e->fieldId === null ? new UploadRejected($e->reason, $work[count($storedInFiles)]['field'] ?? null) : $e;
		} catch (\Throwable) {
			$this->rollback($ownerId, $storedInFiles);
			throw new UploadRejected('upload_store_failed', $work[count($storedInFiles)]['field'] ?? null);
		}

		// Only now is everything stored, so the pending rows can go. Files kept in the app stay where they are.
		foreach ($work as $item) {
			$pending = $item['pending'];
			if (UploadPolicy::storage($item['config']) === UploadPolicy::STORAGE_FILES) {
				$this->store->delete($pending->token);
			}
			$this->registry->remove($pending->token);
		}

		foreach ($descriptors as $fieldId => $list) {
			$data[$fieldId] = $list;
		}
		return $data;
	}

	/**
	 * Remove the files of stored answers, for example when a response is deleted.
	 *
	 * @param array<string, array<string, mixed>> $fields
	 * @param array<string, mixed> $data an entry's stored answers
	 */
	public function deleteFilesOf(string $ownerId, array $fields, array $data): void {
		foreach (self::storedFiles($fields, $data) as $file) {
			try {
				if ($file['storage'] === UploadPolicy::STORAGE_APP) {
					$this->store->delete($file['ref']);
				} elseif (ctype_digit($file['ref'])) {
					$this->files->delete($ownerId, (int)$file['ref']);
				}
			} catch (\Throwable) {
				// A file that is already gone is fine.
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 * @param array<string, mixed> $data
	 * @return list<array{storage: string, ref: string, name: string, size: int, mime: string}>
	 */
	public static function storedFiles(array $fields, array $data): array {
		$found = [];
		foreach ($data as $fieldId => $value) {
			if (($fields[$fieldId]['type'] ?? null) !== FieldType::FILE || !is_array($value)) {
				continue;
			}
			foreach ($value as $file) {
				if (is_array($file) && is_string($file['ref'] ?? null) && is_string($file['storage'] ?? null)) {
					$found[] = [
						'storage' => $file['storage'],
						'ref' => $file['ref'],
						'name' => is_string($file['name'] ?? null) ? $file['name'] : '',
						'size' => is_int($file['size'] ?? null) ? $file['size'] : 0,
						'mime' => is_string($file['mime'] ?? null) ? $file['mime'] : '',
					];
				}
			}
		}
		return $found;
	}

	/**
	 * Throw away uploads that were never submitted.
	 *
	 * @return int how many were removed
	 */
	public function cleanup(int $now): int {
		$removed = 0;
		foreach ($this->registry->expired($now - self::PENDING_TTL_SECONDS, 200) as $pending) {
			$this->store->delete($pending->token);
			$this->registry->remove($pending->token);
			$removed++;
		}
		return $removed;
	}

	/**
	 * Remove everything still waiting for a form, when the form is deleted.
	 */
	public function discardPending(int $formId): void {
		foreach ($this->registry->forForm($formId) as $pending) {
			$this->store->delete($pending->token);
			$this->registry->remove($pending->token);
		}
	}

	/**
	 * Open a stored file for the owner to download.
	 *
	 * @param array{name: string, size: int, mime: string, storage: string, ref: string} $file one entry of {@see storedFiles}
	 * @return array{name: string, size: int, mime: string, stream: resource}|null
	 */
	public function open(string $ownerId, array $file): ?array {
		if ($file['storage'] === UploadPolicy::STORAGE_APP) {
			$stream = $this->store->read($file['ref']);
			return $stream === null ? null : ['name' => $file['name'], 'size' => $file['size'], 'mime' => $file['mime'], 'stream' => $stream];
		}
		return ctype_digit($file['ref']) ? $this->files->open($ownerId, (int)$file['ref']) : null;
	}

	/**
	 * The folder must be somewhere the owner can write, checked now and not only when the form was
	 * published, because their access can change. A file that anyone may send amounts to a public
	 * upload link, so it also needs the owner to be allowed to share that folder that way.
	 *
	 * @param array<string, mixed> $field
	 * @throws UploadRejected
	 */
	public function requireDestination(string $ownerId, string $formTitle, array $field, string $fieldId): void {
		$folder = UploadPolicy::folder($field, $formTitle);
		if (!$this->files->canWrite($ownerId, $folder)) {
			throw new UploadRejected('upload_destination_unavailable', $fieldId);
		}
		if (UploadPolicy::audience($field) === UploadPolicy::AUDIENCE_ANYONE && !$this->files->canShareByLink($ownerId, $folder)) {
			throw new UploadRejected('upload_anonymous_not_allowed', $fieldId);
		}
	}

	/**
	 * @param list<int> $fileIds
	 */
	private function rollback(string $ownerId, array $fileIds): void {
		foreach ($fileIds as $fileId) {
			try {
				$this->files->delete($ownerId, $fileId);
			} catch (\Throwable) {
				// Best effort: the owner can remove a stray file themselves.
			}
		}
	}

	/**
	 * @return array{name: string, size: int, mime: string, storage: string, ref: string}
	 */
	private static function descriptor(PendingUpload $pending, string $storage, string $ref): array {
		return ['name' => $pending->name, 'size' => $pending->size, 'mime' => $pending->mime, 'storage' => $storage, 'ref' => $ref];
	}
}
