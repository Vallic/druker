<?php

declare(strict_types=1);

namespace Drupal\druker\Drush\Commands;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\druker\Entity\CronJobInterface;
use Drupal\druker\Event\CollectJobsEvent;
use Drupal\druker\JobManager;
use Drupal\druker\Wake;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for Druker.
 *
 * The worker binary calls druker:jobs and nothing else. Everything here is
 * either that command, a way for a person to see what the worker sees, or a
 * way to ask it to run a job now.
 */
final class DrukerCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'druker.job_manager')]
    private readonly JobManager $jobManager,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'druker.wake')]
    private readonly Wake $wake,
  ) {
    parent::__construct();
  }

  /**
   * Print the job schedule for a server as JSON.
   */
  #[CLI\Command(name: 'druker:jobs', aliases: ['dk:jobs'])]
  #[CLI\Argument(name: 'hostname', description: 'The server to fetch jobs for. This machine when omitted.')]
  #[CLI\Option(name: 'pretty', description: 'Indent the JSON, for reading rather than parsing.')]
  #[CLI\Usage(name: 'drush druker:jobs', description: 'What the worker on this machine would run.')]
  #[CLI\Usage(name: 'drush druker:jobs web-01 --pretty', description: 'What the worker on another server would run.')]
  public function jobs(string $hostname = '', array $options = ['pretty' => FALSE]): void {
    $hostname = $hostname !== '' ? $hostname : (string) gethostname();
    $schedule = $this->jobManager->getJobsForServer($hostname);

    // The worker parses this. Anything else written to stdout breaks it,
    // which is why every human-facing message in this class goes to stderr.
    $this->output()->writeln($options['pretty']
      ? (string) json_encode($schedule, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
      : Json::encode($schedule));
  }

  /**
   * Check that every job would be understood by the worker.
   */
  #[CLI\Command(name: 'druker:check', aliases: ['dk:check'])]
  #[CLI\Argument(name: 'hostname', description: 'The server to check. This machine when omitted.')]
  #[CLI\Usage(name: 'drush druker:check', description: 'Find jobs the worker would silently drop.')]
  public function check(string $hostname = ''): int {
    $hostname = $hostname !== '' ? $hostname : (string) gethostname();
    $schedule = $this->jobManager->getJobsForServer($hostname);
    $problems = [];

    foreach ($schedule['jobs'] as $job) {
      $label = sprintf('%s (#%s)', $job['name'] ?? '?', $job['id'] ?? '?');

      if (($job['command'] ?? '') === '') {
        $problems[] = dt('@job has no command.', ['@job' => $label]);
        continue;
      }

      if (($job['type'] ?? '') === 'once') {
        if ((int) ($job['run_at'] ?? 0) <= 0) {
          $problems[] = dt('@job runs once but has no time set.', ['@job' => $label]);
        }
        continue;
      }

      if (($job['type'] ?? '') === 'interval') {
        if (!$this->jobManager->isValidInterval((int) ($job['every'] ?? 0), (int) ($job['offset'] ?? 0))) {
          $problems[] = dt('@job repeats every @every seconds, which the worker will not run. The shortest allowed is @minimum, and the offset cannot be negative.', [
            '@job' => $label,
            '@every' => $job['every'] ?? 0,
            '@minimum' => CollectJobsEvent::MINIMUM_INTERVAL,
          ]);
        }
        continue;
      }

      if (!$this->jobManager->isValidCronExpression((string) ($job['cron'] ?? ''))) {
        $problems[] = dt('@job has a cron expression the worker cannot read: @cron', [
          '@job' => $label,
          '@cron' => $job['cron'] ?? '',
        ]);
      }
    }

    // Neither of these is an error — the site is configured that way on
    // purpose — but silence here is how someone spends an afternoon wondering
    // why the job they created never runs.
    $this->reportWithheldShellJobs();
    $this->reportStrandedJobs();
    $this->reportWake((string) ($schedule['wake_dir'] ?? ''));

    if ($problems !== []) {
      foreach ($problems as $problem) {
        $this->logger()->error($problem);
      }

      return self::EXIT_FAILURE;
    }

    $this->logger()->success(dt('@count jobs, all of them readable by the worker.', [
      '@count' => count($schedule['jobs']),
    ]));

    return self::EXIT_SUCCESS;
  }

  /**
   * Ask the worker to run a job now, rather than at its next turn.
   */
  #[CLI\Command(name: 'druker:wake', aliases: ['dk:wake'])]
  #[CLI\Argument(name: 'job', description: 'The job: its id, or its name when no other job has it.')]
  #[CLI\Usage(name: 'drush druker:wake 12', description: 'Run job 12 within a second or two.')]
  #[CLI\Option(name: 'delay', description: 'Seconds to wait first. A signal due sooner is kept.')]
  #[CLI\Usage(name: 'drush druker:wake "Drupal cron"', description: 'The same, by name.')]
  #[CLI\Usage(name: 'drush druker:wake 12 --delay=30', description: 'Run job 12 in half a minute.')]
  public function wake(string $job, array $options = ['delay' => 0]): int {
    $storage = $this->entityTypeManager->getStorage('druker_job');
    $found = ctype_digit($job) ? array_filter([$storage->load((int) $job)]) : $storage->loadByProperties(['label' => $job]);

    if (count($found) !== 1) {
      $this->logger()->error($found === []
        ? dt('No job is called @job.', ['@job' => $job])
        : dt('@count jobs are called @job: name it by its id.', ['@count' => count($found), '@job' => $job]));
      return self::EXIT_FAILURE;
    }

    $target = reset($found);
    if (!$target instanceof CronJobInterface) {
      return self::EXIT_FAILURE;
    }

    $delay = max(0, (int) $options['delay']);

    if (!$this->wake->wake($target, $delay)) {
      $this->logger()->error(dt('The signal could not be written, so @job runs at its next turn. Check @setting.', [
        '@job' => $target->label(),
        '@setting' => "\$settings['" . Wake::SETTING . "']",
      ]));
      return self::EXIT_FAILURE;
    }

    $this->logger()->success($delay > 0
      ? dt('@job is woken: a worker that runs it starts it in @delay seconds, or sooner if it was already asked.', [
        '@job' => $target->label(),
        '@delay' => min($delay, Wake::MAXIMUM_DELAY),
      ])
      : dt('@job is woken: a worker that runs it starts it within a second or two.', ['@job' => $target->label()]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Says where waking stands: off, or a directory the site cannot write.
   */
  private function reportWake(string $directory): void {
    if ($directory === '') {
      $this->logger()->notice(dt('Waking is off: jobs run only on their schedule.'));
      return;
    }

    $writable = is_dir($directory) ? is_writable($directory) : is_writable($this->existingParent($directory));
    if (!$writable) {
      $this->logger()->warning(dt('The wake directory @dir cannot be written, so a woken job runs only at its next turn. Set @setting to a directory the site and the worker share.', [
        '@dir' => $directory,
        '@setting' => "\$settings['" . Wake::SETTING . "']",
      ]));
    }
  }

  /**
   * The nearest directory above a path that exists.
   */
  private function existingParent(string $path): string {
    do {
      $path = dirname($path);
    } while (!is_dir($path) && $path !== dirname($path));
    return $path;
  }

  /**
   * Says so when a job names only servers that are missing or disabled.
   *
   * This one cannot be found by reading a payload, which is why it was missed
   * before: a stranded job is in no server's schedule at all, so checking one
   * hostname reports a clean bill of health while the job never runs. The
   * whole set has to be looked at from outside.
   */
  private function reportStrandedJobs(): void {
    $servers = $this->entityTypeManager->getStorage('druker_server')->loadMultiple();
    $stranded = [];

    foreach ($this->entityTypeManager->getStorage('druker_job')->loadMultiple() as $job) {
      if (!$job instanceof CronJobInterface || !$job->get('status')->value) {
        continue;
      }

      if ($this->jobManager->isStranded($job, $servers)) {
        $stranded[] = (string) $job->label();
      }
    }

    if ($stranded === []) {
      return;
    }

    $this->logger()->warning(dt('@count enabled jobs name only servers that are missing or disabled, so no worker runs them: @jobs. Enable one of the servers they name, or clear the assignment to run them everywhere.', [
      '@count' => count($stranded),
      '@jobs' => implode(', ', $stranded),
    ]));
  }

  /**
   * Says so when shell jobs exist but this site does not allow them.
   */
  private function reportWithheldShellJobs(): void {
    if ($this->jobManager->shellAllowed()) {
      return;
    }

    $withheld = [];

    foreach ($this->entityTypeManager->getStorage('druker_job')->loadMultiple() as $job) {
      if ($job instanceof CronJobInterface && $job->getRunner() === CronJobInterface::RUNNER_SHELL && $job->get('status')->value) {
        $withheld[] = (string) $job->label();
      }
    }

    if ($withheld === []) {
      return;
    }

    $this->logger()->warning(dt('@count enabled shell jobs are not being sent to any worker, because shell jobs are not enabled on this site: @jobs. To allow them, set @setting in settings.php.', [
      '@count' => count($withheld),
      '@jobs' => implode(', ', $withheld),
      '@setting' => "\$settings['" . JobManager::ALLOW_SHELL . "'] = TRUE;",
    ]));
  }

}
