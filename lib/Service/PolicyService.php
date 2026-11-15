<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Actions\MailPolicy;
use OCA\Webforms\Settings\MailSettings;
use OCA\Webforms\Settings\PolicyCheck;
use OCA\Webforms\Settings\PolicySettings;
use OCP\Defaults;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Util;

/**
 * Applies the administrator's policy (docs/SCHEMA.md section 16) where forms are created, published,
 * answered and run. The rules themselves are in PolicySettings and PolicyCheck.
 */
class PolicyService {
	public function __construct(
		private readonly PolicySettings $settings,
		private readonly IGroupManager $groups,
		private readonly MailSettings $mail,
		private readonly IUserManager $users,
		private readonly Defaults $defaults,
	) {
	}

	/** The administrator's settings for mail that forms send (docs/SCHEMA.md section 16.2). */
	public function mail(): MailPolicy {
		return $this->mail->policy();
	}

	public function settings(): PolicySettings {
		return $this->settings;
	}

	/**
	 * Whether this account may create (or import) a form. Everyone may unless creator groups are set,
	 * and then only their members and administrators. Forms that already exist are not affected.
	 */
	public function canCreate(string $userId): bool {
		$groups = $this->settings->creatorGroups();
		if ($groups === [] || $this->groups->isAdmin($userId)) {
			return true;
		}
		foreach ($groups as $group) {
			if ($this->groups->isInGroup($userId, $group)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What policy says is wrong with this definition's actions.
	 *
	 * @param array<string, mixed> $definition
	 * @return list<array{code: string, path: string}>
	 */
	public function actionErrors(array $definition): array {
		return array_merge(
			PolicyCheck::actionErrors($definition, $this->settings->allowedActionTypes(), $this->settings->maxActionsPerForm()),
			PolicyCheck::mailErrors($definition, $this->mail->policy()),
		);
	}

	public function isActionTypeAllowed(string $type): bool {
		return in_array($type, $this->settings->allowedActionTypes(), true);
	}

	/**
	 * The definition with the actions the server no longer allows taken out.
	 *
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed>
	 */
	public function withoutDisabledActions(array $definition): array {
		return PolicyCheck::withoutDisabledActions($definition, $this->settings->allowedActionTypes());
	}

	/**
	 * What the builder needs to know, for the signed-in user.
	 *
	 * @return array{canCreate: bool, allowedActionTypes: list<string>, maxActions: int, mail: array<string, mixed>}
	 */
	public function forUser(string $userId): array {
		return [
			'canCreate' => $this->canCreate($userId),
			'allowedActionTypes' => $this->settings->allowedActionTypes(),
			'maxActions' => $this->settings->maxActionsPerForm(),
			'mail' => $this->mailForUser($this->mail->policy(), $userId),
		];
	}

	/**
	 * What a form owner may know about the mail settings: whether their own choice counts, and what
	 * applies when they make none, as the real address and name, so the builder can show them in the
	 * empty boxes. `defaultReplyTo` is what a reply would reach for a form this user owns.
	 *
	 * @return array{allowOverride: bool, replyToMode: string, replyToAddress: string, fromName: string, defaultReplyTo: string, defaultFromName: string}
	 */
	private function mailForUser(MailPolicy $mail, string $userId): array {
		// Without a reply-to a reply goes to the address the mail was sent from.
		$sender = Util::getDefaultEmailAddress('no-reply');
		$replyTo = match ($mail->replyToMode) {
			MailPolicy::REPLY_OWNER => trim((string)$this->users->get($userId)?->getEMailAddress()),
			MailPolicy::REPLY_ADDRESS => $mail->replyToAddress,
			default => '',
		};
		return [
			'allowOverride' => $mail->allowOverride,
			'replyToMode' => $mail->replyToMode,
			'replyToAddress' => $mail->replyToAddress,
			'fromName' => $mail->fromName,
			'defaultReplyTo' => $replyTo !== '' ? $replyTo : $sender,
			'defaultFromName' => $mail->fromName !== '' ? $mail->fromName : $this->defaults->getName(),
		];
	}
}
