<?php

declare(strict_types=1);

namespace Drupal\druker\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * One scheduled job, as the worker will be told about it.
 */
interface CronJobInterface extends ContentEntityInterface {

  /**
   * Run the command through Drush. Always available.
   */
  public const RUNNER_DRUSH = 'drush';

  /**
   * Run the command line through a shell.
   *
   * Off unless the site opts in, because it turns the Druker permission from
   * "run any Drush command" into "run anything on this machine".
   */
  public const RUNNER_SHELL = 'shell';

  /**
   * Which runner executes this job: one of the RUNNER_* constants.
   */
  public function getRunner(): string;

  /**
   * The Drush command to run, without the leading "drush".
   */
  public function getCommand(): string;

  /**
   * The recurring schedule, as typed: cron, an @-shortcut or plain English.
   */
  public function getTimingCron(): string;

  /**
   * When to run this once, as a timestamp, or NULL when it recurs.
   */
  public function getTimingOnce(): ?int;

  /**
   * The server this job belongs to, or NULL to run it on every server.
   */
  public function getServerId(): ?string;

  /**
   * Whether the Go binary should run this job in a goroutine (non-blocking).
   *
   * When FALSE, the binary blocks until the job process exits before moving on.
   */
  public function isAsync(): bool;

  /**
   * ID of the job that must complete before this one starts, or NULL.
   */
  public function getDependsOnId(): ?int;

}
