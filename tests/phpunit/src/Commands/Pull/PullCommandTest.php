<?php

declare(strict_types = 1);

namespace Acquia\Cli\Tests\Commands\Pull;

use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\Command\Pull\PullCommand;
use Acquia\Cli\Exception\AcquiaCliException;

/**
 * @property \Acquia\Cli\Command\Pull\PullCommand $command
 */
class PullCommandTest extends PullCommandTestBase {

  public function setUp(): void {
    parent::setUp();
    $this->setupFsFixture();
  }

  protected function createCommand(): CommandBase {
    return $this->injectCommand(PullCommand::class);
  }

  public function testMissingLocalRepo(): void {
    // Unset repo root. Mimics failing to find local git repo. Command must be re-created
    // to re-inject the parameter into the command.
    $this->acliRepoRoot = '';
    $this->removeMockGitConfig();
    $this->command = $this->createCommand();
    $this->expectException(AcquiaCliException::class);
    $this->expectExceptionMessage('Execute this command from within a Drupal project directory or an empty directory');
    $inputs = [
      // Would you like to clone a project into the current directory?
      'n',
    ];
    $this->executeCommand([], $inputs);
  }

}
