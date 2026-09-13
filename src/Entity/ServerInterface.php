<?php

declare(strict_types=1);

namespace Drupal\druker\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * One machine running a worker, matched to it by hostname.
 */
interface ServerInterface extends ConfigEntityInterface {

  /**
   * The hostname the worker reports, which is how a server is recognized.
   */
  public function getHostname(): string;

  /**
   * How many seconds the worker waits before asking for the schedule again.
   */
  public function getDefaultRefresh(): int;

}
