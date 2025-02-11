<?php

declare(strict_types = 1);

namespace Acquia\Cli\Command\CodeStudio;

class CodeStudioCiCdVariables {

  /**
   * @return array<mixed>
   */
  public static function getList(): array {
    return array_column(self::getDefaults(), 'key');
  }

  /**
   * @return array<mixed>
   */
  public static function getDefaults(?string $cloudApplicationUuid = NULL, ?string $cloudKey = NULL, ?string $cloudSecret = NULL, ?string $projectAccessTokenName = NULL, ?string $projectAccessToken = NULL, ?string $phpVersion = NULL): array {
    return [
      [
        'key' => 'ACQUIA_APPLICATION_UUID',
        'masked' => TRUE,
        'protected' => FALSE,
        'value' => $cloudApplicationUuid,
        'variable_type' => 'env_var',
      ],
      [
        'key' => 'ACQUIA_CLOUD_API_TOKEN_KEY',
        'masked' => TRUE,
        'protected' => FALSE,
        'value' => $cloudKey,
        'variable_type' => 'env_var',
      ],
      [
        'key' => 'ACQUIA_CLOUD_API_TOKEN_SECRET',
        'masked' => TRUE,
        'protected' => FALSE,
        'value' => $cloudSecret,
        'variable_type' => 'env_var',
      ],
      [
        'key' => 'ACQUIA_GLAB_TOKEN_NAME',
        'masked' => TRUE,
        'protected' => FALSE,
        'value' => $projectAccessTokenName,
        'variable_type' => 'env_var',
      ],
      [
        'key' => 'ACQUIA_GLAB_TOKEN_SECRET',
        'masked' => TRUE,
        'protected' => FALSE,
        'value' => $projectAccessToken,
        'variable_type' => 'env_var',
      ],
      [
        'key' => 'PHP_VERSION',
        'masked' => FALSE,
        'protected' => FALSE,
        'value' => $phpVersion,
        'variable_type' => 'env_var',
      ],
    ];
  }

}
