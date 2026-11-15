<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\AppInfo;

use OCA\Webforms\Actions\Adapter\NextcloudHttp;
use OCA\Webforms\Actions\Adapter\NextcloudMail;
use OCA\Webforms\Actions\Adapter\NextcloudSecrets;
use OCA\Webforms\Actions\Adapter\NextcloudAccounts;
use OCA\Webforms\Actions\Ports\AccountPort;
use OCA\Webforms\Actions\Ports\HttpPort;
use OCA\Webforms\Actions\Ports\MailPort;
use OCA\Webforms\Actions\Ports\SecretPort;
use OCA\Webforms\Db\UploadMapper;
use OCA\Webforms\Listener\RegisterEntitiesListener;
use OCA\Webforms\Listener\UserDeletedListener;
use OCA\Webforms\Security\Captcha\AppConfigStore;
use OCA\Webforms\Security\Captcha\CacheReplayStore;
use OCA\Webforms\Security\Captcha\CaptchaHttp;
use OCA\Webforms\Security\Captcha\ConfigStore;
use OCA\Webforms\Security\Captcha\NextcloudCaptchaHttp;
use OCA\Webforms\Security\Captcha\ProofOfWork;
use OCA\Webforms\Security\Captcha\ReplayStore;
use OCA\Webforms\Uploads\Adapter\NextcloudMime;
use OCA\Webforms\Uploads\Adapter\NextcloudOwnerFiles;
use OCA\Webforms\Uploads\Adapter\NextcloudPendingStore;
use OCA\Webforms\Uploads\Adapter\NextcloudTokens;
use OCA\Webforms\Uploads\Ports\MimePort;
use OCA\Webforms\Uploads\Ports\OwnerFilesPort;
use OCA\Webforms\Uploads\Ports\PendingStorePort;
use OCA\Webforms\Uploads\Ports\TokenPort;
use OCA\Webforms\Uploads\Ports\UploadRegistryPort;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\User\Events\UserDeletedEvent;
use OCP\WorkflowEngine\Events\RegisterEntitiesEvent;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'webforms';

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

		$context->registerServiceAlias(ConfigStore::class, AppConfigStore::class);
		$context->registerServiceAlias(ReplayStore::class, CacheReplayStore::class);
		$context->registerServiceAlias(CaptchaHttp::class, NextcloudCaptchaHttp::class);
		$context->registerService(ProofOfWork::class, static fn (ContainerInterface $c): ProofOfWork => new ProofOfWork(
			$c->get(IConfig::class)->getSystemValueString('secret'),
			$c->get(ReplayStore::class),
		));

		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(RegisterEntitiesEvent::class, RegisterEntitiesListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
