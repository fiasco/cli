<?php

declare(strict_types = 1);

namespace Acquia\Cli\Command\Remote;

use Acquia\Cli\Attribute\RequireAuth;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command to proxy Drush commands on an environment using SSH.
 */
#[RequireAuth]
#[AsCommand(name: 'remote:drush', description: 'Run a Drush command remotely on a application\'s environment', aliases: ['drush', 'dr'])]
final class DrushCommand extends SshBaseCommand {

  protected function configure(): void {
    $this
      ->setHelp('<fg=black;bg=cyan>Pay close attention to the argument syntax! Note the usage of <options=bold;bg=cyan>--</> to separate the drush command arguments and options.</>')
      ->addArgument('alias', InputArgument::REQUIRED, 'Alias for application & environment in the format `app-name.env`')
      ->addArgument('drush_command', InputArgument::IS_ARRAY, 'Drush command')
      ->addUsage('<app>.<env> -- <command>')
      ->addUsage('myapp.dev -- uli 1')
      ->addUsage('myapp.dev -- status --fields=db-status');
  }

  protected function execute(InputInterface $input, OutputInterface $output): ?int {
    $alias = $input->getArgument('alias');
    $alias = $this->normalizeAlias($alias);
    $alias = self::validateEnvironmentAlias($alias);
    $environment = $this->getEnvironmentFromAliasArg($alias);

    $acliArguments = $input->getArguments();
    $drushCommandArguments = [
      "cd /var/www/html/{$alias}/docroot; ",
      'drush',
      implode(' ', (array) $acliArguments['drush_command']),
    ];

    return $this->sshHelper->executeCommand($environment, $drushCommandArguments)->getExitCode();
  }

}
