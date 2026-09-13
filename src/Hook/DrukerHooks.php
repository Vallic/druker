<?php

declare(strict_types=1);

namespace Drupal\druker\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for Druker.
 */
class DrukerHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'druker_server_card' => [
        'variables' => [
          'server' => NULL,
          'hostname' => '',
          'refresh' => 0,
          'enabled' => TRUE,
          'stranded' => 0,
          'edit_url' => '',
          'jobs' => [],
          'shared_count' => 0,
        ],
      ],
      'druker_day' => [
        'variables' => [
          'rows' => [],
          'hours' => [],
        ],
      ],
    ];
  }

}
