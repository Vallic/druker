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
   * Repeats on a cron expression.
   */
  public const TIMING_CRON = 'cron';

  /**
   * Runs at one moment and is then done with.
   */
  public const TIMING_ONCE = 'once';

  /**
   * Repeats every n seconds, for the periods cron cannot say.
   */
  public const TIMING_INTERVAL = 'interval';

  /**
   * Which runner executes this job: one of the RUNNER_* constants.
   */
  public function getRunner(): string;

  /**
   * How this job says when it runs: one of the TIMING_* constants.
   *
   * Derived from which of the timing fields is filled in, so that the form,
   * the payload, the list and the dashboard all decide it the same way and
   * cannot disagree about what kind of job this is.
   */
  public function getTimingType(): string;

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
   * Seconds between runs, or NULL when this is not an interval job.
   *
   * For the periods cron cannot say: every 30 seconds, every 75, every 90.
   */
  public function getTimingEvery(): ?int;

  /**
   * Seconds to shift an interval job's runs by.
   *
   * Boundaries are anchored to the epoch, so this is what keeps the same job
   * on two servers out of the same second. Meaningless without a period, and
   * zero when there is none.
   */
  public function getTimingOffset(): int;

  /**
   * The servers this job runs on.
   *
   * @return string[]
   *   Server IDs. An empty array means every server runs it.
   */
  public function getServerIds(): array;

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
