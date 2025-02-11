<?php

declare(strict_types = 1);

namespace Acquia\Cli\Tests\Commands\Ide\Wizard;

use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\Command\Ide\Wizard\IdeWizardCreateSshKeyCommand;
use Acquia\Cli\Tests\Commands\Ide\IdeHelper;
use AcquiaCloudApi\Response\IdeResponse;

/**
 * @property \Acquia\Cli\Command\Ide\Wizard\IdeWizardCreateSshKeyCommand $command
 * @requires OS linux|darwin
 */
class IdeWizardCreateSshKeyCommandTest extends IdeWizardTestBase {

  protected IdeResponse $ide;

  public function setUp(): void {
    parent::setUp();
    $applicationResponse = $this->mockApplicationRequest();
    $this->mockListSshKeysRequest();
    $this->mockRequest('getAccount');
    $this->mockPermissionsRequest($applicationResponse);
    $this->ide = $this->mockIdeRequest();
    $this->sshKeyFileName = IdeWizardCreateSshKeyCommand::getSshKeyFilename(IdeHelper::$remoteIdeUuid);
  }

  /**
   * @return \Acquia\Cli\Command\Ide\Wizard\IdeWizardCreateSshKeyCommand
   */
  protected function createCommand(): CommandBase {
    return $this->injectCommand(IdeWizardCreateSshKeyCommand::class);
  }

  protected function mockIdeRequest(): IdeResponse {
    $ideResponse = $this->getMockResponseFromSpec('/ides/{ideUuid}', 'get', '200');
    $this->clientProphecy->request('get', '/ides/' . IdeHelper::$remoteIdeUuid)->willReturn($ideResponse)->shouldBeCalled();
    return new IdeResponse($ideResponse);
  }

  public function testCreate(): void {
    parent::runTestCreate();
  }

  /**
   * @group brokenProphecy
   */
  public function testSshKeyAlreadyUploaded(): void {
    parent::runTestSshKeyAlreadyUploaded();
  }

}
