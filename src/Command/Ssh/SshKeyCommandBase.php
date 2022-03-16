<?php

namespace Acquia\Cli\Command\Ssh;

use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\Exception\AcquiaCliException;
use Acquia\Cli\Helpers\LoopHelper;
use Acquia\Cli\Helpers\SshCommandTrait;
use AcquiaCloudApi\Endpoints\Applications;
use AcquiaCloudApi\Endpoints\Environments;
use AcquiaCloudApi\Response\EnvironmentResponse;
use AcquiaCloudApi\Response\IdeResponse;
use Closure;
use React\EventLoop\Loop;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\Validator\Validation;

/**
 * Class SshKeyCommandBase.
 */
abstract class SshKeyCommandBase extends CommandBase {

  use SshCommandTrait;

  /** @var string */
  protected $passphraseFilepath;

  /**
   * @var string
   */
  protected $privateSshKeyFilename;

  /**
   * @var string
   */
  protected $privateSshKeyFilepath;

  /**
   * @var string
   */
  protected $publicSshKeyFilepath;

  /**
   * @param string $private_ssh_key_filename
   */
  protected function setSshKeyFilepath(string $private_ssh_key_filename) {
    $this->privateSshKeyFilename = $private_ssh_key_filename;
    $this->privateSshKeyFilepath = $this->sshDir . '/' . $this->privateSshKeyFilename;
    $this->publicSshKeyFilepath = $this->privateSshKeyFilepath . '.pub';
  }

  /**
   *
   * @param \AcquiaCloudApi\Response\IdeResponse $ide
   *
   * @return string
   */
  public static function getIdeSshKeyLabel(IdeResponse $ide): string {
    return self::normalizeSshKeyLabel('IDE_' . $ide->label . '_' . $ide->uuid);
  }

  /**
   * @param string $label
   *   The label to normalize.
   *
   * @return string|string[]|null
   */
  public static function normalizeSshKeyLabel($label) {
    // It may only contain letters, numbers and underscores.
    return preg_replace('/[^A-Za-z0-9_]/', '', $label);
  }

  /**
   * Normalizes public SSH key by trimming and removing user and machine suffix.
   *
   * @param string $public_key
   *
   * @return string
   */
  protected function normalizePublicSshKey($public_key): string {
    $parts = explode('== ', $public_key);
    $key = $parts[0];

    return trim($key);
  }

  /**
   * Asserts whether ANY SSH key has been added to the local keychain.
   *
   * @return bool
   * @throws \Exception
   */
  protected function sshKeyIsAddedToKeychain(): bool {
    $process = $this->localMachineHelper->execute([
      'ssh-add',
      '-L',
    ], NULL, NULL, FALSE);

    if ($process->isSuccessful()) {
      $key_contents = $this->normalizePublicSshKey($this->localMachineHelper->readFile($this->publicSshKeyFilepath));
      return strpos($process->getOutput(), $key_contents) !== FALSE;
    }
    return FALSE;
  }

  /**
   * Adds a given password protected local SSH key to the local keychain.
   *
   * @param string $filepath
   *   The filepath of the private SSH key.
   * @param string $password
   *
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  protected function addSshKeyToAgent(string $filepath, string $password): void {
    // We must use a separate script to mimic user input due to the limitations of the `ssh-add` command.
    // @see https://www.linux.com/topic/networking/manage-ssh-key-file-passphrase/
    $temp_filepath = $this->localMachineHelper->getFilesystem()->tempnam(sys_get_temp_dir(), 'acli');
    $this->localMachineHelper->writeFile($temp_filepath, <<<'EOT'
#!/usr/bin/env bash
echo $SSH_PASS
EOT
    );
    $this->localMachineHelper->getFilesystem()->chmod($temp_filepath, 0755);
    $private_key_filepath = str_replace('.pub', '', $filepath);
    $process = $this->localMachineHelper->executeFromCmd('SSH_PASS=' . $password . ' DISPLAY=1 SSH_ASKPASS=' . $temp_filepath . ' ssh-add ' . $private_key_filepath, NULL, NULL, FALSE);
    $this->localMachineHelper->getFilesystem()->remove($temp_filepath);
    if (!$process->isSuccessful()) {
      throw new AcquiaCliException('Unable to add the SSH key to local SSH agent:' . $process->getOutput() . $process->getErrorOutput());
    }
  }

  /**
   * Polls the Cloud Platform until a successful SSH request is made to the dev
   * environment.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @throws \Exception
   */
  protected function pollAcquiaCloudUntilSshSuccess(
    OutputInterface $output
  ): void {
    // Create a loop to periodically poll the Cloud Platform.
    $loop = Loop::get();
    $spinner = LoopHelper::addSpinnerToLoop($loop, 'Waiting for the key to become available on the Cloud Platform', $output);

    // Wait for SSH key to be available on a web.
    $cloud_app_uuid = $this->determineCloudApplication(TRUE);
    $environment = $this->getAnyNonProdAhEnvironment($cloud_app_uuid);

    // Poll Cloud every 5 seconds.
    $loop->addPeriodicTimer(5, function () use ($output, $loop, $environment, $spinner) {
      try {
        $process = $this->sshHelper->executeCommand($environment, ['ls'], FALSE);
        if ($process->isSuccessful()) {
          LoopHelper::finishSpinner($spinner);
          $loop->stop();
          $output->writeln("\n<info>Your SSH key is ready for use!</info>\n");
        }
        else {
          $this->logger->debug($process->getOutput() . $process->getErrorOutput());
        }
      } catch (AcquiaCliException $exception) {
        // Do nothing. Keep waiting and looping and logging.
        $this->logger->debug($exception->getMessage());
      }
    });
    LoopHelper::addTimeoutToLoop($loop, 15, $spinner);
    $loop->run();
  }

  /**
   * Get the first environment for a given Cloud application.
   *
   * @param string $cloud_app_uuid
   *
   * @return \AcquiaCloudApi\Response\EnvironmentResponse|null
   * @throws \Exception
   */
  protected function getAnyNonProdAhEnvironment(string $cloud_app_uuid): ?EnvironmentResponse {
    $acquia_cloud_client = $this->cloudApiClientService->getClient();
    $environment_resource = new Environments($acquia_cloud_client);
    /** @var EnvironmentResponse[] $application_environments */
    $application_environments = iterator_to_array($environment_resource->getAll($cloud_app_uuid));
    foreach ($application_environments as $environment) {
      if (!$environment->flags->production) {
        return $environment;
      }
    }
    return NULL;
  }

  /**
   * @param string $filename
   * @param string $password
   *
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   * @throws \Exception
   */
  protected function createSshKey(string $filename, string $password) {
    $key_file_path = $this->doCreateSshKey($filename, $password);
    $this->setSshKeyFilepath(basename($key_file_path));
    if (!$this->sshKeyIsAddedToKeychain()) {
      $this->addSshKeyToAgent($this->publicSshKeyFilepath, $password);
    }
    return $key_file_path;
  }

  /**
   * @param string $filename
   * @param string $password
   *
   * @return string
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  protected function doCreateSshKey($filename, $password): string {
    $filepath = $this->sshDir . '/' . $filename;
    if (file_exists($filepath)) {
      throw new AcquiaCliException('An SSH key with the filename {filepath} already exists. Please delete it and retry', ['filepath' => $filename]);
    }

    $this->localMachineHelper->checkRequiredBinariesExist(['ssh-keygen']);
    $process = $this->localMachineHelper->execute([
      'ssh-keygen',
      '-t',
      'rsa',
      '-b',
      '4096',
      '-f',
      $filepath,
      '-N',
      $password,
    ], NULL, NULL, FALSE);
    if (!$process->isSuccessful()) {
      throw new AcquiaCliException($process->getOutput() . $process->getErrorOutput());
    }

    return $filepath;
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return string
   */
  protected function determineFilename(InputInterface $input, OutputInterface $output): string {
    if ($input->getOption('filename')) {
      $filename = $input->getOption('filename');
      $this->validateFilename($filename);
    }
    else {
      $default = 'id_rsa_acquia';
      $question = new Question("Please enter a filename for your new local SSH key. Press enter to use default value", $default);
      $question->setNormalizer(static function ($value) {
        return $value ? trim($value) : '';
      });
      $question->setValidator(Closure::fromCallable([$this, 'validateFilename']));
      $filename = $this->io->askQuestion($question);
    }

    return $filename;
  }

  /**
   * @param string $filename
   *
   * @return mixed
   */
  protected function validateFilename($filename) {
    $violations = Validation::createValidator()->validate($filename, [
      new Length(['min' => 5]),
      new NotBlank(),
      new Regex(['pattern' => '/^\S*$/', 'message' => 'The value may not contain spaces']),
    ]);
    if (count($violations)) {
      throw new ValidatorException($violations->get(0)->getMessage());
    }

    return $filename;
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return string
   * @throws \Exception
   */
  protected function determinePassword(InputInterface $input, OutputInterface $output): string {
    if ($input->getOption('password')) {
      $password = $input->getOption('password');
      $this->validatePassword($password);
      return $password;
    }
    if ($input->isInteractive()) {
      $question = new Question('Enter a password for your SSH key');
      $question->setHidden($this->localMachineHelper->useTty());
      $question->setNormalizer(static function ($value) {
        return $value ? trim($value) : '';
      });
      $question->setValidator(Closure::fromCallable([$this, 'validatePassword']));
      return $this->io->askQuestion($question);
    }

    throw new AcquiaCliException('Could not determine the SSH key password. Either use the --password option or else run this command in an interactive shell.');
  }

  /**
   * @param string $password
   *
   * @return string
   */
  protected function validatePassword($password) {
    $violations = Validation::createValidator()->validate($password, [
      new Length(['min' => 5]),
      new NotBlank(),
    ]);
    if (count($violations)) {
      throw new ValidatorException($violations->get(0)->getMessage());
    }

    return $password;
  }

  /**
   * @param \AcquiaCloudApi\Connector\Client $acquia_cloud_client
   * @param string $public_key
   *
   * @return bool
   */
  protected function keyHasUploaded($acquia_cloud_client, $public_key): bool {
    $cloud_keys = $acquia_cloud_client->request('get', '/account/ssh-keys');
    foreach ($cloud_keys as $cloud_key) {
      if (trim($cloud_key->public_key) === trim($public_key)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param null $filepath
   *
   * @return array
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   * @throws \Exception
   */
  protected function determinePublicSshKey($filepath = NULL): array {
    if ($filepath) {
      $filepath = $this->localMachineHelper->getLocalFilepath($filepath);
    }
    elseif ($this->input->hasOption('filepath') && $this->input->getOption('filepath')) {
      $filepath = $this->localMachineHelper->getLocalFilepath($this->input->getOption('filepath'));
    }

    if ($filepath) {
      if (!$this->localMachineHelper->getFilesystem()->exists($filepath)) {
        throw new AcquiaCliException('The filepath {filepath} is not valid', ['filepath' => $filepath]);
      }
      if (strpos($filepath, '.pub') === FALSE) {
        throw new AcquiaCliException('The filepath {filepath} does not have the .pub extension', ['filepath' => $filepath]);
      }
      $public_key = $this->localMachineHelper->readFile($filepath);
      $chosen_local_key = basename($filepath);
    }
    else {
      // Get local key and contents.
      $local_keys = $this->findLocalSshKeys();
      $chosen_local_key = $this->promptChooseLocalSshKey($local_keys);
      $public_key = $this->getLocalSshKeyContents($local_keys, $chosen_local_key);
    }

    return [$chosen_local_key, $public_key];
  }

  /**
   * @param \Symfony\Component\Finder\SplFileInfo[] $local_keys
   *
   * @return string
   */
  protected function promptChooseLocalSshKey($local_keys): string {
    $labels = [];
    foreach ($local_keys as $local_key) {
      $labels[] = $local_key->getFilename();
    }
    $question = new ChoiceQuestion(
      'Choose a local SSH key to upload to the Cloud Platform',
      $labels
    );
    return $this->io->askQuestion($question);
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return string
   */
  protected function determineSshKeyLabel(InputInterface $input, OutputInterface $output): string {
    if ($input->hasOption('label') && $input->getOption('label')) {
      $label = $input->getOption('label');
      $label = SshKeyCommandBase::normalizeSshKeyLabel($label);
      $label = $this->validateSshKeyLabel($label);
    }
    else {
      $question = new Question('Please enter a Cloud Platform label for this SSH key');
      $question->setNormalizer(Closure::fromCallable([$this, 'normalizeSshKeyLabel']));
      $question->setValidator(Closure::fromCallable([$this, 'validateSshKeyLabel']));
      $label = $this->io->askQuestion($question);
    }

    return $label;
  }

  /**
   * @param $label
   *
   * @return mixed
   */
  protected function validateSshKeyLabel($label) {
    if (trim($label) === '') {
      throw new RuntimeException('The label cannot be empty');
    }

    return $label;
  }

  /**
   * @param \Symfony\Component\Finder\SplFileInfo[] $local_keys
   * @param string $chosen_local_key
   *
   * @return string
   * @throws \Exception
   */
  protected function getLocalSshKeyContents(array $local_keys, string $chosen_local_key): string {
    $filepath = '';
    foreach ($local_keys as $local_key) {
      if ($local_key->getFilename() === $chosen_local_key) {
        $filepath = $local_key->getRealPath();
        break;
      }
    }
    return $this->localMachineHelper->readFile($filepath);
  }

  /**
   * @param string $label
   * @param string $chosen_local_key
   * @param string $public_key
   *
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   * @throws \Exception
   */
  protected function uploadSshKey(string $label, string $chosen_local_key, string $public_key) {
    $options = [
      'form_params' => [
        'label' => $label,
        'public_key' => $public_key,
      ],
    ];

    // @todo If a key with this label already exists, let the user try again.
    $response = $this->cloudApiClientService->getClient()->makeRequest('post', '/account/ssh-keys', $options);
    if ($response->getStatusCode() !== 202) {
      throw new AcquiaCliException($response->getBody()->getContents());
    }

    $this->output->writeln("<info>Uploaded $chosen_local_key to the Cloud Platform with label $label</info>");

    // Wait for the key to register on the Cloud Platform.
    if ($this->input->hasOption('no-wait') && $this->input->getOption('no-wait') === FALSE) {
      if ($this->input->isInteractive()) {
        $this->io->note("It may take some time before the SSH key is installed on all of your application's web servers.");
        $answer = $this->io->confirm("Would you like to wait until Cloud Platform is ready?");
        if (!$answer) {
          $this->io->success('Your SSH key has been successfully uploaded to Cloud Platform.');
          return;
        }
      }

      if ($this->keyHasUploaded($this->cloudApiClientService->getClient(), $public_key)) {
        $this->pollAcquiaCloudUntilSshSuccess($this->output);
      }
    }
  }

}
