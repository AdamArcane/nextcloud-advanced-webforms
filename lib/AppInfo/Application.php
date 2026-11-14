<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\AppInfo;

use OCA\AdvancedForms\Actions\Adapter\NextcloudHttp;
use OCA\AdvancedForms\Actions\Adapter\NextcloudMail;
use OCA\AdvancedForms\Actions\Adapter\NextcloudSecrets;
use OCA\AdvancedForms\Actions\Adapter\NextcloudAccounts;
use OCA\AdvancedForms\Actions\Ports\AccountPort;
use OCA\AdvancedForms\Actions\Ports\HttpPort;
use OCA\AdvancedForms\Actions\Ports\MailPort;
use OCA\AdvancedForms\Actions\Ports\SecretPort;
use OCA\AdvancedForms\Db\UploadMapper;
use OCA\AdvancedForms\Listener\UserDeletedListener;
use OCA\AdvancedForms\Uploads\Adapter\NextcloudMime;
use OCA\AdvancedForms\Uploads\Adapter\NextcloudOwnerFiles;
use OCA\AdvancedForms\Uploads\Adapter\NextcloudPendingStore;
use OCA\AdvancedForms\Uploads\Adapter\NextcloudTokens;
use OCA\AdvancedForms\Uploads\Ports\MimePort;
use OCA\AdvancedForms\Uploads\Ports\OwnerFilesPort;
use OCA\AdvancedForms\Uploads\Ports\PendingStorePort;
use OCA\AdvancedForms\Uploads\Ports\TokenPort;
use OCA\AdvancedForms\Uploads\Ports\UploadRegistryPort;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'advancedforms';

	/**
	 * @param array<string, mixed> $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// The action runner depends on small interfaces so it can be tested without a server;
		// these bind them to Nextcloud's mailer, HTTP client and encrypted config.
		$context->registerServiceAlias(MailPort::class, NextcloudMail::class);
		$context->registerServiceAlias(HttpPort::class, NextcloudHttp::class);
		$context->registerServiceAlias(SecretPort::class, NextcloudSecrets::class);
		$context->registerServiceAlias(AccountPort::class, NextcloudAccounts::class);
		$context->registerServiceAlias(UploadRegistryPort::class, UploadMapper::class);
		$context->registerServiceAlias(PendingStorePort::class, NextcloudPendingStore::class);
		$context->registerServiceAlias(OwnerFilesPort::class, NextcloudOwnerFiles::class);
		$context->registerServiceAlias(TokenPort::class, NextcloudTokens::class);
		$context->registerServiceAlias(MimePort::class, NextcloudMime::class);

		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
