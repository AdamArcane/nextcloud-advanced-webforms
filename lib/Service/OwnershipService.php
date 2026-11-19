<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Access\FormAccess;
use OCA\Webforms\Access\NextOwner;
use OCA\Webforms\Access\Role;
use OCA\Webforms\Db\Form;
use OCA\Webforms\Db\FormMapper;
use OCA\Webforms\Db\FormShare;
use OCA\Webforms\Db\FormShareMapper;
use OCA\Webforms\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Who owns a form when people change or go away (docs/SCHEMA.md section 22.7): the owner handing a form to
 * someone, and a form passing to an editor when its owner's account is deleted.
 */
class OwnershipService {
	public function __construct(
		private readonly FormAccess $access,
		private readonly FormMapper $forms,
		private readonly FormShareMapper $shares,
		private readonly FormService $service,
		private readonly PolicyService $policy,
		private readonly IUserManager $users,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The owner hands the form to another account that may create forms. They become an editor.
	 *
	 * @throws \OCA\Webforms\Exception\NotFoundException
	 * @throws \OCA\Webforms\Exception\ForbiddenException
	 * @throws ValidationException `transfer_target_invalid`, `transfer_destinations_unavailable` (one per
	 *                             place, with its path) or `transfer_has_files`
	 */
	public function transfer(string $userId, int $formId, string $to): Form {
		$form = $this->access->require($userId, $formId, Role::OWNER);
		if ($to === $userId || !$this->users->userExists($to) || !$this->policy->canCreate($to)) {
			throw new ValidationException([['code' => 'transfer_target_invalid', 'path' => 'to']]);
		}
		$unavailable = $this->service->destinationErrorsFor($form, $to);
		if ($unavailable !== []) {
			throw new ValidationException(array_map(static fn (array $error): array => ['code' => 'transfer_destinations_unavailable', 'path' => (string)$error['path']], $unavailable));
		}
		if ($this->service->holdsFilesInFiles($form)) {
			throw new ValidationException([['code' => 'transfer_has_files', 'path' => '']]);
		}
		return $this->handOver($form, $to, $userId);
	}

	/**
	 * The form's owner is going away. It passes to the editor who was added first and may create forms,
	 * and the answer is true. It is closed when its places in Files and Tables are not usable for them, and
	 * says so until it is published again. With no such editor nothing changes and the answer is false: the
	 * caller deletes the form.
	 */
	public function passOnFromDeletedOwner(Form $form): bool {
		$candidate = NextOwner::pick(
			array_map(static fn (FormShare $share): array => ['type' => $share->getShareType(), 'with' => $share->getShareWith(), 'role' => $share->getRole()], $this->shares->findByForm($form->getId())),
			FormShare::TYPE_USER,
			fn (string $userId): bool => $this->users->userExists($userId) && $this->policy->canCreate($userId),
		);
		if ($candidate === null) {
			return false;
		}
		try {
			$usable = $this->service->destinationErrorsFor($form, $candidate) === [];
		} catch (\Throwable $e) {
			$this->logger->warning('Advanced Webforms could not check the places of a form for its new owner', ['exception' => $e, 'app' => 'webforms']);
			$usable = false;
		}
		$form = $this->handOver($form, $candidate, null);
		if (!$usable) {
			$form->setStatus(Form::STATUS_CLOSED);
			$form->setDestinationsPending(1);
			$form->setUpdatedAt($this->time->getTime());
			$this->forms->update($form);
		}
		return true;
	}

	/**
	 * The new owner no longer needs their own share, and the previous owner (when there is one still) stays
	 * on as an editor.
	 */
	private function handOver(Form $form, string $to, ?string $previousOwner): Form {
		$own = $this->shares->findOne($form->getId(), FormShare::TYPE_USER, $to);
		if ($own !== null) {
			$this->shares->delete($own);
		}
		if ($previousOwner !== null) {
			$share = new FormShare();
			$share->setFormId($form->getId());
			$share->setShareType(FormShare::TYPE_USER);
			$share->setShareWith($previousOwner);
			$share->setRole(Role::EDITOR->key());
			$share->setCreatedBy($previousOwner);
			$share->setCreatedAt($this->time->getTime());
			$this->shares->insert($share);
		}
		$form->setOwnerId($to);
		$form->setUpdatedAt($this->time->getTime());
		return $this->forms->update($form);
	}
}
