<?php

declare(strict_types=1);

namespace Drupal\druker\Drush\Commands;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\druker\Entity\CronJobInterface;
use Drupal\druker\Event\CollectJobsEvent;
use Drupal\druker\JobManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for Druker.
 *
 * The worker binary calls druker:jobs and nothing else. Everything here is
 * either that command or a way for a person to see what the worker sees.
 */
final class DrukerCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'druker.job_manager')]
    private readonly JobManager $jobManager,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
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

    // A withheld job is not an error — the site is configured that way on
    // purpose — but silence here is how someone spends an afternoon wondering
    // why the job they created never runs.
    $this->reportWithheldShellJobs();

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
