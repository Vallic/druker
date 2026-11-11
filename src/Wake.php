<?php

declare(strict_types=1);

namespace Drupal\druker;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\druker\Entity\CronJobInterface;
use Psr\Log\LoggerInterface;

/**
 * Asks the worker to run a job now, rather than at its next turn.
 *
 * A signal is an empty file named after the job's id, in a directory the
 * site and the worker both read. The worker lists the directory every second
 * (its tick), claims a signal by removing it, and starts the job; nothing
 * waits on Drush or a bootstrap, so a woken job starts within a second or
 * two. A job still running keeps its signal and runs again once it is done,
 * and signals sent before the worker gets to them are one run.
 *
 * The directory is `$settings['druker_wake_dir']`: a path or a stream URI,
 * or FALSE to switch waking off. Unset, it is `private://druker/wake` where
 * the site has a private file system, which is the place a web server and a
 * worker on another machine or in another container most often share, and
 * otherwise a directory of the site's own under `temporary://`, which serves
 * one machine. The worker learns the directory from the schedule
 * (`wake_dir`), so it is set in one place.
 *
 * Waking is a shortcut, never a guarantee: a signal that cannot be written
 * is logged and the job still runs at its next turn. Code that wakes a job
 * should keep the job's schedule as the floor.
 */
class Wake {

  /**
   * The setting that names the directory, or switches waking off.
   */
  public const SETTING = 'druker_wake_dir';

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected Settings $settings,
    protected LoggerInterface $logger,
  ) {}

  /**
   * The directory signals go to, as an absolute path; empty when off.
   */
  public function directory(): string {
    $setting = $this->settings->get(self::SETTING);
    if ($setting === FALSE) {
      return '';
    }

    if (is_string($setting) && trim($setting) !== '') {
      $location = trim($setting);
    }
    elseif ($this->streamWrapperManager->isValidScheme('private')) {
      $location = 'private://druker/wake';
    }
    else {
      // Several sites on one machine share its temporary directory, and a
      // job id means something different on each.
      $location = 'temporary://druker-wake-' . substr(hash('sha256', Settings::getHashSalt()), 0, 12);
    }

    if (!str_contains($location, '://')) {
      return rtrim($location, '/');
    }

    [$scheme, $target] = explode('://', $location, 2);
    if (!$this->streamWrapperManager->isValidScheme($scheme)) {
      // Not one of Drupal's schemes: a location PHP opens as it is.
      return rtrim($location, '/');
    }

    // realpath() of a directory not created yet is FALSE; its scheme's root
    // exists.
    $root = $this->fileSystem->realpath($scheme . '://');
    if ($root === FALSE || $root === '') {
      return '';
    }
    return rtrim($root, '/') . '/' . trim($target, '/');
  }

  /**
   * Signals that a job has something to do now.
   *
   * @param \Drupal\druker\Entity\CronJobInterface|int $job
   *   The job, or its id.
   *
   * @return bool
   *   Whether the signal was written. FALSE when waking is off, or the
   *   directory cannot be written; the job then runs at its next turn.
   */
  public function wake(CronJobInterface|int $job): bool {
    $id = $job instanceof CronJobInterface ? (int) $job->id() : $job;
    $directory = $this->directory();
    if ($id <= 0 || $directory === '') {
      return FALSE;
    }

    if (!is_dir($directory) && !@mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      $this->logger->warning('Cannot create the wake directory @dir; job @id runs at its next turn instead.', [
        '@dir' => $directory,
        '@id' => $id,
      ]);
      return FALSE;
    }

    if (!@touch($directory . '/' . $id)) {
      $this->logger->warning('Cannot write a wake signal in @dir; job @id runs at its next turn instead.', [
        '@dir' => $directory,
        '@id' => $id,
      ]);
      return FALSE;
    }

    return TRUE;
  }

}
