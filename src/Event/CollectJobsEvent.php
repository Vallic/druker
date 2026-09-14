<?php

declare(strict_types=1);

namespace Drupal\druker\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired when the job manager collects jobs for a server.
 *
 * Subscribers can call addJob() to inject additional jobs beyond those
 * stored in druker_job entities (e.g. dynamically computed jobs).
 *
 * Each job array must contain:
 *   - id: string|int unique identifier
 *   - name: string
 *   - command: string (drush command, or a command line when runner is shell)
 *   - runner: 'drush' or 'shell'
 *   - type: 'cron' or 'once'
 *   - cron: string cron expression (when type=cron)
 *   - run_at: int unix timestamp (when type=once)
 *
 * A subscriber can also change how often this server comes back for its
 * schedule, with setRefresh(). The server's own setting is the starting
 * point; a subscriber that knows the site is in a busy period, or that a
 * deployment is in progress, can shorten or lengthen it from here.
 */
class CollectJobsEvent extends Event {

  const NAME = 'druker.collect_jobs';

  /**
   * The shortest refresh a subscriber may ask for.
   *
   * Each refresh is a Drush call on every server. Below this the worker
   * spends more time asking what to do than doing it.
   */
  public const MINIMUM_REFRESH = 60;

  /**
   * The refresh used when nothing says otherwise.
   *
   * A schedule is edited by a person, so it changes on the timescale people
   * work at. Half an hour is soon enough for an edit to take effect without
   * a deploy, and rare enough that the asking costs nothing.
   */
  public const DEFAULT_REFRESH = 1800;

  public function __construct(
    private readonly string $hostname,
    private array $jobs = [],
    private int $refresh = self::DEFAULT_REFRESH,
  ) {
    // The floor applies to what the server was configured with as much as to
    // what a subscriber asks for. A stored value can predate the minimum, or
    // have been written straight into the config YAML.
    $this->refresh = max(self::MINIMUM_REFRESH, $this->refresh);
  }

  /**
   * The server the jobs are being collected for.
   */
  public function getHostname(): string {
    return $this->hostname;
  }

  /**
   * Adds a job to the schedule, in the shape described above.
   */
  public function addJob(array $job): void {
    $this->jobs[] = $job;
  }

  /**
   * Every job collected so far, entity-backed and added alike.
   */
  public function getJobs(): array {
    return $this->jobs;
  }

  /**
   * How many seconds before this server asks for its schedule again.
   */
  public function getRefresh(): int {
    return $this->refresh;
  }

  /**
   * Changes how often this server comes back.
   *
   * Clamped rather than rejected: a subscriber asking for two seconds has
   * misunderstood the cost, and failing the whole schedule over it would be
   * a worse answer than quietly doing the sensible thing.
   */
  public function setRefresh(int $seconds): void {
    $this->refresh = max(self::MINIMUM_REFRESH, $seconds);
  }

}
