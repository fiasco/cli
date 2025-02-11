<?php

declare(strict_types = 1);

namespace Acquia\Cli\Command\Pull;

use Acquia\Cli\Attribute\RequireAuth;
use Acquia\Cli\Attribute\RequireDb;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[RequireAuth]
#[RequireDb]
#[AsCommand(name: 'pull:all', description: 'Copy code, database, and files from a Cloud Platform environment', aliases: ['refresh', 'pull'])]
final class PullCommand extends PullCommandBase {

  protected function configure(): void {
    $this
      ->acceptEnvironmentId()
      ->acceptSite()
      ->addOption('dir', NULL, InputArgument::OPTIONAL, 'The directory containing the Drupal project to be refreshed')
      ->addOption('no-code', NULL, InputOption::VALUE_NONE, 'Do not refresh code from remote repository')
      ->addOption('no-files', NULL, InputOption::VALUE_NONE, 'Do not refresh files')
      ->addOption('no-databases', NULL, InputOption::VALUE_NONE, 'Do not refresh databases')
      ->addOption(
            'no-scripts',
            NULL,
            InputOption::VALUE_NONE,
            'Do not run any additional scripts after code and database are copied. E.g., composer install , drush cache-rebuild, etc.'
        );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    parent::execute($input, $output);

    if (!$input->getOption('no-code')) {
      $this->pullCode($input, $output);
    }

    if (!$input->getOption('no-files')) {
      $this->pullFiles($input, $output);
    }

    if (!$input->getOption('no-databases')) {
      $this->pullDatabase($input, $output);
    }

    if (!$input->getOption('no-scripts')) {
      $this->executeAllScripts($input, $this->getOutputCallback($output, $this->checklist));
    }

    return Command::SUCCESS;
  }

}
