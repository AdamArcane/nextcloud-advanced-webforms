<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\WorkflowEngine;

use OCA\Webforms\Event\EntryCreatedEvent;
use OCA\Webforms\Event\EntryDeletedEvent;
use OCA\Webforms\Event\EntryPromotedEvent;
use OCA\Webforms\Event\EntryStatusChangedEvent;
use OCA\Webforms\Event\EventPayload;
use OCA\Webforms\Event\FormPublishedEvent;
use OCA\Webforms\Event\FormsEvent;
use OCA\Webforms\Event\FormStatusChangedEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\WorkflowEngine\EntityContext\IContextPortation;
use OCP\WorkflowEngine\EntityContext\IDisplayText;
use OCP\WorkflowEngine\EntityContext\IIcon;
use OCP\WorkflowEngine\EntityContext\IUrl;
use OCP\WorkflowEngine\GenericEntityEvent;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IRuleMatcher;

/**
 * Offers the app's events as triggers in Nextcloud Flow (the workflow engine): "When a response is
 * submitted", and so on. The event name is the event class name, which is how the engine listens.
 * Actions come from other apps. An administrator's flow sees every form, a user's own flow only
 * forms that user owns.
 */
class FormsEntity implements IEntity, IDisplayText, IUrl, IIcon, IContextPortation {
	private readonly IL10N $l10n;

	private ?string $eventName = null;

	/** @var array<string, mixed> */
	private array $payload = [];

	public function __construct(
		IFactory $l10nFactory,
		private readonly IURLGenerator $urls,
	) {
		$this->l10n = $l10nFactory->get('webforms');
	}

	public function getName(): string {
		return $this->l10n->t('Webforms');
	}

	public function getIcon(): string {
		return $this->urls->imagePath('webforms', 'app.svg');
	}

	public function getEvents(): array {
		return [
			new GenericEntityEvent($this->l10n->t('Form response submitted'), EntryCreatedEvent::class),
			new GenericEntityEvent($this->l10n->t('Form response deleted'), EntryDeletedEvent::class),
			new GenericEntityEvent($this->l10n->t('Form response status changed'), EntryStatusChangedEvent::class),
			new GenericEntityEvent($this->l10n->t('Form response promoted from the waiting list'), EntryPromotedEvent::class),
			new GenericEntityEvent($this->l10n->t('Form published'), FormPublishedEvent::class),
			new GenericEntityEvent($this->l10n->t('Form status changed'), FormStatusChangedEvent::class),
		];
	}

	public function prepareRuleMatcher(IRuleMatcher $ruleMatcher, string $eventName, Event $event): void {
		if (!$event instanceof FormsEvent) {
			return;
		}
		$this->eventName = $eventName;
		$this->payload = $event->getPayload();
		$ruleMatcher->setEntitySubject($this, $this->payload);
	}

	/**
	 * A user's own flows only run for forms that user owns.
	 */
	public function isLegitimatedForUserId(string $userId): bool {
		return ($this->payload['ownerId'] ?? null) === $userId;
	}

	public function getDisplayText(int $verbosity = 0): string {
		$title = (string)($this->payload['formTitle'] ?? '');
		switch ($this->payload['event'] ?? null) {
			case EventPayload::ENTRY_CREATED:
				$who = is_string($this->payload['userId'] ?? null) ? $this->payload['userId'] : $this->l10n->t('Someone');
				return $this->l10n->t('%1$s submitted %2$s', [$who, $title]);
			case EventPayload::ENTRY_DELETED:
				return $this->l10n->t('A response to %s was deleted', [$title]);
			case EventPayload::ENTRY_STATUS_CHANGED:
				return $this->l10n->t('The status of a response to %s changed', [$title]);
			case EventPayload::ENTRY_PROMOTED:
				return $this->l10n->t('A response to %s was moved off the waiting list', [$title]);
			case EventPayload::FORM_PUBLISHED:
				return $this->l10n->t('%s was published', [$title]);
			case EventPayload::FORM_STATUS_CHANGED:
				return match ($this->payload['status'] ?? null) {
					'closed' => $this->l10n->t('%s was closed', [$title]),
					'unpublished' => $this->l10n->t('%s was unpublished', [$title]),
					default => $this->l10n->t('%s was reopened', [$title]),
				};
			default:
				return '';
		}
	}

	public function getUrl(): string {
		$formId = $this->payload['formId'] ?? null;
		if (!is_int($formId)) {
			return '';
		}
		$base = $this->urls->linkToRouteAbsolute('webforms.page.index') . '#/f/' . $formId;
		$event = $this->payload['event'] ?? null;
		if (($event === EventPayload::ENTRY_STATUS_CHANGED || $event === EventPayload::ENTRY_PROMOTED) && is_int($this->payload['entryId'] ?? null)) {
			return $base . '/responses/' . $this->payload['entryId'];
		}
		return $event === EventPayload::ENTRY_CREATED || $event === EventPayload::ENTRY_DELETED ? $base . '/responses' : $base;
	}

	public function getIconUrl(): string {
		return $this->getIcon();
	}

	/**
	 * What a flow that runs later needs to rebuild this entity. The answers stay out: they would end
	 * up in the job queue, and no text above needs them.
	 *
	 * @return array<string, mixed>
	 */
	public function exportContextIDs(): array {
		$payload = $this->payload;
		unset($payload['data']);
		return ['eventName' => $this->eventName, 'payload' => $payload];
	}

	public function importContextIDs(array $contextIDs): void {
		$this->eventName = is_string($contextIDs['eventName'] ?? null) ? $contextIDs['eventName'] : null;
		$this->payload = is_array($contextIDs['payload'] ?? null) ? $contextIDs['payload'] : [];
	}
}
