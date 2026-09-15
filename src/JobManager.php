<?php

declare(strict_types=1);

namespace Drupal\druker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\druker\Entity\CronJobInterface;
use Drupal\druker\Entity\ServerInterface;
use Drupal\druker\Event\CollectJobsEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Works out what a given server should be running.
 *
 * The one place that decides the payload shape the worker consumes. Anything
 * changed in formatJob() has to change in the worker's Job struct, and the
 * sample in examples/sample-output.json is what the worker's tests parse.
 */
class JobManager {

  /**
   * The setting a site sets in settings.php to allow shell jobs.
   */
  public const ALLOW_SHELL = 'druker_allow_shell';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventDispatcherInterface $eventDispatcher,
    protected Settings $settings,
  ) {}

  /**
   * Whether this site allows jobs that are shell command lines.
   *
   * Deliberately a setting rather than a permission. Turning Druker from "run
   * any Drush command" into "run anything on this machine" is a decision for
   * whoever owns the server, not for whoever administers the site, and the
   * people who granted the permission under the first meaning never agreed to
   * the second.
   */
  public function shellAllowed(): bool {
    return (bool) $this->settings->get(self::ALLOW_SHELL, FALSE);
  }

  /**
   * Returns the full job payload for the given server hostname.
   *
   * Output shape:
   * {
   *   "server": "web-01",
   *   "refresh": 1800,
   *   "jobs": [
   *     {"id": 1, "command": "...", "type": "cron", "cron": "0 2 * * *"},
   *     {"id": 2, "command": "...", "type": "once", "run_at": 1747392000}
   *   ]
   * }
   */
  public function getJobsForServer(string $hostname): array {
    $server = $this->resolveServer($hostname);
    $refresh = $server ? $server->getDefaultRefresh() : CollectJobsEvent::DEFAULT_REFRESH;

    $storage = $this->entityTypeManager->getStorage('druker_job');
    $query = $storage->getQuery()
      ->condition('status', TRUE)
      ->accessCheck(FALSE);

    if ($server) {
      $or = $query->orConditionGroup()
        ->condition('server', $server->id())
        ->notExists('server');
      $query->condition($or);
    }
    else {
      // No server record matches this hostname. It gets the jobs that belong
      // to no server in particular, and nothing else — never another
      // server's. A typo in a hostname, or a machine coming up before anyone
      // adds its Server, must not turn it into a box that runs everybody's
      // nightly backup.
      $query->notExists('server');
    }

    $shell_allowed = $this->shellAllowed();
    $jobs = [];

    foreach ($storage->loadMultiple($query->execute()) as $job) {
      if (!$job instanceof CronJobInterface) {
        continue;
      }

      // A shell job on a site that has not opted in never reaches the worker
      // at all. Gating it here rather than in the worker means the gate is a
      // setting on the machine being protected, not a flag in a payload.
      if ($job->getRunner() === CronJobInterface::RUNNER_SHELL && !$shell_allowed) {
        continue;
      }

      $jobs[] = $this->formatJob($job);
    }

    $event = new CollectJobsEvent($hostname, $jobs, $refresh);
    $this->eventDispatcher->dispatch($event, CollectJobsEvent::NAME);

    return [
      'server' => $hostname,
      // The server's setting is the starting point; a subscriber may have
      // moved it, and the worker picks the change up on its next fetch.
      'refresh' => $event->getRefresh(),
      'jobs' => $event->getJobs(),
    ];
  }

  /**
   * The server record matching a hostname, when there is an enabled one.
   */
  private function resolveServer(string $hostname): ?ServerInterface {
    if ($hostname === '') {
      return NULL;
    }
    $servers = $this->entityTypeManager
      ->getStorage('druker_server')
      ->loadByProperties(['hostname' => $hostname, 'status' => TRUE]);
    $server = reset($servers);
    return $server instanceof ServerInterface ? $server : NULL;
  }

  /**
   * One job in the shape the worker expects.
   */
  private function formatJob(CronJobInterface $job): array {
    $result = [
      'id' => (int) $job->id(),
      'name' => $job->label(),
      'command' => $job->getCommand(),
      'runner' => $job->getRunner(),
      'async' => $job->isAsync(),
      'depends_on' => $job->getDependsOnId(),
    ];

    $result['type'] = $job->getTimingType();

    switch ($result['type']) {
      case CronJobInterface::TIMING_ONCE:
        $result['run_at'] = $job->getTimingOnce();
        break;

      case CronJobInterface::TIMING_INTERVAL:
        $result['every'] = $job->getTimingEvery();
        $result['offset'] = $job->getTimingOffset();
        break;

      default:
        $result['cron'] = $this->resolveCronExpression($job->getTimingCron());
    }

    return $result;
  }

  /**
   * Whether the worker will be able to read this job's schedule at all.
   *
   * One question asked in one place, because a job has three ways of saying
   * when it runs and every caller that cares — the dashboard's warnings, the
   * day grid, the list — has to agree about which of them this job used.
   */
  public function scheduleIsReadable(CronJobInterface $job): bool {
    return match ($job->getTimingType()) {
      CronJobInterface::TIMING_ONCE => TRUE,
      CronJobInterface::TIMING_INTERVAL => $this->isValidInterval((int) $job->getTimingEvery(), $job->getTimingOffset()),
      default => $this->isValidCronExpression($job->getTimingCron()),
    };
  }

  /**
   * Whether an interval the worker will accept.
   *
   * The same reasoning as isValidCronExpression(): the worker drops what it
   * cannot use, and a dropped job is silent. Kept in step with Prepare() in
   * worker/schedule.go.
   */
  public function isValidInterval(int $every, int $offset = 0): bool {
    return $every >= CollectJobsEvent::MINIMUM_INTERVAL && $offset >= 0;
  }

  /**
   * Whether a value resolves to an expression the worker will accept.
   *
   * The worker parses cron itself and drops anything it cannot read, which is
   * silent from the admin UI's point of view: the job simply never runs. So
   * the rules are checked here too, and they have to be the same rules — a
   * loose pattern that accepts "99 * * * *" only moves the failure to a place
   * nobody is looking.
   */
  public function isValidCronExpression(string $value): bool {
    $fields = preg_split('/\s+/', trim($this->resolveCronExpression($value))) ?: [];

    if (count($fields) !== 5) {
      return FALSE;
    }

    // Minute, hour, day of month, month, day of week. Sunday is 0 and 7 both.
    $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    foreach ($fields as $index => $field) {
      if (!$this->isValidCronField((string) $field, $ranges[$index][0], $ranges[$index][1])) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Whether one comma-separated cron field is within its range.
   */
  private function isValidCronField(string $field, int $min, int $max): bool {
    return $this->expandCronField($field, $min, $max) !== NULL;
  }

  /**
   * The values one cron field matches, or NULL when it cannot be read.
   *
   * Mirrors the worker's parser: "*", a number, "a-b", "*&#47;n" and any
   * comma-separated mixture. Kept in step with it by the parity test.
   *
   * @param string $field
   *   One field of a cron expression.
   * @param int $min
   *   The lowest value the field accepts.
   * @param int $max
   *   The highest value the field accepts.
   *
   * @return int[]|null
   *   The matching values, sorted, or NULL when the field is unreadable.
   */
  private function expandCronField(string $field, int $min, int $max): ?array {
    $values = [];

    foreach (explode(',', $field) as $part) {
      $part = trim($part);

      if ($part === '') {
        return NULL;
      }

      $step = 1;

      if (str_contains($part, '/')) {
        [$part, $raw_step] = explode('/', $part, 2);

        if (!ctype_digit($raw_step) || (int) $raw_step < 1) {
          return NULL;
        }

        $step = (int) $raw_step;
      }

      if ($part === '*') {
        $start = $min;
        $end = $max;
      }
      elseif (str_contains($part, '-')) {
        [$raw_start, $raw_end] = array_pad(explode('-', $part, 2), 2, '');

        if (!ctype_digit(trim($raw_start)) || !ctype_digit(trim($raw_end))) {
          return NULL;
        }

        $start = (int) $raw_start;
        $end = (int) $raw_end;
      }
      else {
        if (!ctype_digit($part)) {
          return NULL;
        }

        $start = $end = (int) $part;
      }

      if ($start < $min || $end > $max || $start > $end) {
        return NULL;
      }

      for ($value = $start; $value <= $end; $value += $step) {
        $values[$value] = $value;
      }
    }

    ksort($values);

    return array_values($values);
  }

  /**
   * The hours of the day a recurring schedule fires in.
   *
   * Used to draw the dashboard's day, where seeing that everything lands at
   * three in the morning is the point.
   *
   * @param string $value
   *   Anything the cron field accepts: an expression, a shortcut, English.
   *
   * @return int[]
   *   Hours from 0 to 23, or an empty array when the value is unreadable.
   */
  public function scheduleHours(string $value): array {
    // An expression that is wrong anywhere is wrong everywhere: "99 * * * *"
    // has a readable hour field and still never runs, and drawing it as
    // running every hour would contradict the warning beside it.
    if (!$this->isValidCronExpression($value)) {
      return [];
    }

    $fields = preg_split('/\s+/', trim($this->resolveCronExpression($value))) ?: [];

    if (count($fields) !== 5) {
      return [];
    }

    return $this->expandCronField((string) $fields[1], 0, 23) ?? [];
  }

  /**
   * Resolves a human-readable or @-shortcut to a standard cron expression.
   *
   * Supported forms (case-insensitive):
   *
   *   @yearly / @annually / @monthly / @weekly / @daily / @midnight / @hourly
   *   "every minute"
   *   "every N minutes"
   *   "every hour"
   *   "every N hours"
   *   "every day" / "daily"
   *   "every week" / "weekly"
   *   "every month" / "monthly"
   *   Standard 5-part cron expression (passed through as-is)
   */
  public function resolveCronExpression(string $value): string {
    static $shortcuts = [
      '@yearly'   => '0 0 1 1 *',
      '@annually' => '0 0 1 1 *',
      '@monthly'  => '0 0 1 * *',
      '@weekly'   => '0 0 * * 0',
      '@daily'    => '0 0 * * *',
      '@midnight' => '0 0 * * *',
      '@hourly'   => '0 * * * *',
    ];

    $trimmed = trim($value);
    $lower = strtolower($trimmed);

    if (isset($shortcuts[$lower])) {
      return $shortcuts[$lower];
    }

    // Standard 5-part cron expression — pass through as-is.
    if (preg_match('/^[\d\*\/,\-]+(?: [\d\*\/,\-]+){4}$/', $trimmed)) {
      return $trimmed;
    }

    if ($lower === 'every minute') {
      return '* * * * *';
    }
    if (preg_match('/^every (\d+) minutes?$/', $lower, $m)) {
      return sprintf('*/%d * * * *', $m[1]);
    }
    if ($lower === 'every hour') {
      return '0 * * * *';
    }
    if (preg_match('/^every (\d+) hours?$/', $lower, $m)) {
      return sprintf('0 */%d * * *', $m[1]);
    }
    if ($lower === 'every day' || $lower === 'daily') {
      return '0 0 * * *';
    }
    if ($lower === 'every week' || $lower === 'weekly') {
      return '0 0 * * 0';
    }
    if ($lower === 'every month' || $lower === 'monthly') {
      return '0 0 1 * *';
    }

    return $trimmed;
  }

}
