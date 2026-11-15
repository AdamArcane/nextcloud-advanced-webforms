<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Db\ActionLogMapper;
use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\FormMapper;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Settings\PolicyCheck;
use OCA\Webforms\Uploads\UploadService;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

/**
 * Deletes responses that are older than the administrator's retention period, with their uploaded
 * files and delivery log rows (docs/SCHEMA.md section 16). It does the same clean-up as the owner
 * deleting a response. Retention is off (0 days) until an administrator turns it on.
 */
class RetentionService {
	private const BATCH = 100;

	public function __construct(
		private readonly PolicyService $policy,
		private readonly EntryMapper $entries,
		private readonly FormMapper $forms,
		private readonly VersionMapper $versions,
		private readonly ActionLogMapper $actionLog,
		private readonly UploadService $uploads,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * How many responses are older than the given number of days. For the preview before retention is
	 * turned on, so nobody is surprised by what a number deletes.
	 *
	 * @throws Exception
	 */
	public function countDue(int $now, int $days): int {
		$cutoff = PolicyCheck::retentionCutoff($now, $days);
		return $cutoff === null ? 0 : $this->entries->countOlderThan($cutoff);
	}

	/**
	 * Delete due responses, oldest first, at most $limit in one run so a first run on a big server does
	 * not hold a background job for long. The next run carries on.
	 *
	 * @return int how many responses were deleted
	 */
	public function purge(int $now, int $limit = 1000): int {
		$cutoff = PolicyCheck::retentionCutoff($now, $this->policy->settings()->retentionDays());
		if ($cutoff === null) {
			return 0;
		}

		$deleted = 0;
		/** @var array<int, string> $owners */
		$owners = [];
		/** @var array<int, array<string, array<string, mixed>>> $fieldsByVersion */
		$fieldsByVersion = [];
		while ($deleted < $limit) {
			$batch = $this->entries->findOlderThan($cutoff, min(self::BATCH, $limit - $deleted));
			if ($batch === []) {
				break;
			}
			foreach ($batch as $entry) {
				try {
					$formId = $entry->getFormId();
					$owners[$formId] ??= $this->forms->find($formId)->getOwnerId();
					$fieldsByVersion[$entry->getVersionId()] ??= self::fieldsOf($this->versions->find($entry->getVersionId())->getDefinitionArray());
					$this->uploads->deleteFilesOf($owners[$formId], $fieldsByVersion[$entry->getVersionId()], $entry->getDataArray());
				} catch (\Throwable $e) {
					// The form or version is already gone, so there is nothing to clean beyond the row itself.
					$this->logger->info('Advanced Webforms retention: could not clean up files of a response', ['exception' => $e, 'app' => 'webforms']);
				}
				$this->actionLog->deleteByEntry($entry->getId());
				$this->entries->delete($entry);
				$deleted++;
			}
		}
		if ($deleted > 0) {
			$this->logger->info('Advanced Webforms retention deleted {count} responses', ['count' => $deleted, 'app' => 'webforms']);
		}
		return $deleted;
	}

	/**
	 * @param array<string, mixed> $definition
	 * @return array<string, array<string, mixed>>
	 */
	private static function fieldsOf(array $definition): array {
		$fields = $definition['fields'] ?? [];
		return is_array($fields) ? array_filter($fields, 'is_array') : [];
	}
}
