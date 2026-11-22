<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Command;

use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Import\CoreForms\CoreFormsImporter;
use OCA\Webforms\Import\CoreForms\CoreFormsReader;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ webforms:import-core-forms` (docs/SCHEMA.md section 13.3).
 */
class ImportCoreForms extends Command {
	public function __construct(
		private CoreFormsReader $reader,
		private CoreFormsImporter $importer,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('webforms:import-core-forms')
			->setDescription('Import forms from the core Nextcloud Forms app as new draft forms')
			->addOption('form', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Id of a core Forms form to import (repeat for several). Default: all of them')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Account that will own the imported forms. Default: each form\'s owner in core Forms')
			->addOption('with-responses', null, InputOption::VALUE_NONE, 'Also import the responses. The form is published once and then closed, so it takes no new responses until you reopen it')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be imported and what would be left out, and create nothing');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->reader->isAvailable()) {
			$output->writeln('<error>The tables of the core Forms app were not found on this server.</error>');
			return self::FAILURE;
		}

		$only = array_values(array_unique(array_map('intval', (array)$input->getOption('form'))));
		$forms = [];
		if ($only === []) {
			$forms = $this->reader->listForms();
		} else {
			foreach ($only as $id) {
				$found = $this->reader->listForms($id);
				if ($found === []) {
					$output->writeln('<error>There is no core Forms form with id ' . $id . '.</error>');
					return self::FAILURE;
				}
				$forms = array_merge($forms, $found);
			}
		}
		if ($forms === []) {
			$output->writeln('There are no forms in core Forms.');
			return self::SUCCESS;
		}

		$user = $input->getOption('user');
		$user = is_string($user) && $user !== '' ? $user : null;
		if ($user !== null && !$this->users->userExists($user)) {
			$output->writeln('<error>The account "' . $user . '" does not exist.</error>');
			return self::FAILURE;
		}
		$dryRun = (bool)$input->getOption('dry-run');
		$withResponses = (bool)$input->getOption('with-responses');

		$failed = 0;
		foreach ($forms as $form) {
			$owner = $user ?? $form['ownerId'];
			$output->writeln('');
			$output->writeln(sprintf('<info>Core form %d: %s</info>', $form['id'], $form['title']));
			if (!$dryRun && !$this->users->userExists($owner)) {
				$output->writeln('<error>  Its owner "' . $owner . '" does not exist here. Use --user to name an account.</error>');
				$failed++;
				continue;
			}
			try {
				$outcome = $this->importer->import($form['id'], $owner, $withResponses, $dryRun);
			} catch (ValidationException $e) {
				$output->writeln('<error>  Refused: ' . implode(', ', array_map(static fn (array $error): string => (string)($error['code'] ?? '?'), $e->getErrors())) . '</error>');
				$failed++;
				continue;
			}

			if ($dryRun) {
				$output->writeln(sprintf('  Would create a draft with %d fields and %s.', $outcome['fields'], $withResponses ? $outcome['responsesFound'] . ' responses' : 'no responses (' . $outcome['responsesFound'] . ' found, add --with-responses)'));
			} else {
				$output->writeln(sprintf('  Created draft form %d for %s with %d fields%s.', $outcome['formId'], $owner, $outcome['fields'], $withResponses ? ', ' . $outcome['responsesImported'] . ' of ' . $outcome['responsesFound'] . ' responses imported' : ''));
			}
			foreach ($outcome['errors'] as $error) {
				$output->writeln('  <comment>Draft problem (blocks publishing): ' . ($error['code'] ?? '?') . ' at ' . ($error['path'] ?? '?') . '</comment>');
			}
			foreach ($outcome['skipped'] as $item) {
				$output->writeln(sprintf('  <comment>Left out [%s %s%s] %s:</comment> %s', $item['scope'], $item['ref'], $item['label'] === '' ? '' : ' "' . $item['label'] . '"', $item['code'], $item['detail']));
			}
		}
		return $failed === 0 ? self::SUCCESS : self::FAILURE;
	}
}
