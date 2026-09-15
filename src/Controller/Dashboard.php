<?php

declare(strict_types=1);

namespace Drupal\druker\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\druker\Entity\CronJobInterface;
use Drupal\druker\Entity\ServerInterface;
use Drupal\druker\JobManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows what each server is going to run.
 *
 * The two lists answer "what jobs exist" and "what servers exist". Neither
 * answers the question people actually have, which is "what is this machine
 * going to do" — and getting that from the lists means holding the
 * per-server assignment in your head while you read them.
 */
class Dashboard extends ControllerBase {

  public function __construct(
    protected readonly JobManager $jobManager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('druker.job_manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Builds the dashboard.
   */
  public function build(): array {
    $servers = $this->entityTypeManager->getStorage('druker_server')->loadMultiple();
    $jobs = $this->entityTypeManager->getStorage('druker_job')->loadMultiple();

    $build = [
      '#attached' => ['library' => ['druker/dashboard']],
      '#cache' => [
        'tags' => [
          'druker_job_list',
          'config:druker_server_list',
        ],
      ],
    ];

    $build['summary'] = $this->summary($servers, $jobs);
    $build['warnings'] = $this->warnings($servers, $jobs);
    $build['servers'] = $this->servers($servers, $jobs);

    if ($jobs !== []) {
      $build['day'] = $this->day($jobs, $servers);
    }

    return $build;
  }

  /**
   * The counts, across the top.
   */
  protected function summary(array $servers, array $jobs): array {
    $enabled = array_filter($jobs, static fn(CronJobInterface $job): bool => (bool) $job->get('status')->value);
    $shell = array_filter($enabled, static fn(CronJobInterface $job): bool => $job->getRunner() === CronJobInterface::RUNNER_SHELL);
    $once = array_filter($enabled, static fn(CronJobInterface $job): bool => $job->getTimingType() === CronJobInterface::TIMING_ONCE);
    $interval = array_filter($enabled, static fn(CronJobInterface $job): bool => $job->getTimingType() === CronJobInterface::TIMING_INTERVAL);

    $items = [
      ['label' => $this->t('Servers'), 'value' => count($servers)],
      ['label' => $this->t('Enabled jobs'), 'value' => count($enabled)],
      ['label' => $this->t('Interval jobs'), 'value' => count($interval)],
      ['label' => $this->t('One-time jobs'), 'value' => count($once)],
      ['label' => $this->t('Shell jobs'), 'value' => count($shell)],
    ];

    return [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['druker-summary']],
      '#items' => array_map(
        static fn(array $item): array => [
          '#type' => 'inline_template',
          '#template' => '<span class="druker-summary__value">{{ value }}</span><span class="druker-summary__label">{{ label }}</span>',
          '#context' => $item,
        ],
        $items,
      ),
    ];
  }

  /**
   * Anything that means a job will not run, said before the pretty part.
   */
  protected function warnings(array $servers, array $jobs): array {
    $messages = [];
    $shell_allowed = $this->jobManager->shellAllowed();

    foreach ($jobs as $job) {
      if (!$job->get('status')->value) {
        continue;
      }

      $label = $job->label();

      if ($job->getRunner() === CronJobInterface::RUNNER_SHELL && !$shell_allowed) {
        $messages[] = $this->t('%job is a shell job, and shell jobs are not enabled on this site, so no worker is told about it.', ['%job' => $label]);
        continue;
      }

      if (!$this->jobManager->scheduleIsReadable($job)) {
        $messages[] = $this->t('%job has a schedule the worker cannot read, so it never runs.', ['%job' => $label]);
      }

      $named = $job->getServerIds();
      $reachable = array_filter($named, static fn(string $id): bool => isset($servers[$id]) && $servers[$id]->status());

      if ($named !== [] && $reachable === []) {
        $messages[] = $this->t('%job names only servers that are missing or disabled, so nothing picks it up.', ['%job' => $label]);
      }
      elseif (count($reachable) < count($named)) {
        $messages[] = $this->t('%job names a server that is missing or disabled. The others still run it.', ['%job' => $label]);
      }
    }

    if ($messages === []) {
      return [];
    }

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('These jobs will not run'),
      '#items' => $messages,
      '#attributes' => ['class' => ['druker-warnings']],
    ];
  }

  /**
   * One card per server, listing what its worker will actually receive.
   */
  protected function servers(array $servers, array $jobs): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['druker-servers']],
    ];

    $shared = array_filter($jobs, static fn(CronJobInterface $job): bool => $job->getServerIds() === [] && (bool) $job->get('status')->value);

    if ($servers === []) {
      $build['none'] = [
        '#markup' => '<p>' . $this->t('No servers yet. Add one per machine that will run a worker, matched by its hostname.') . '</p>',
      ];

      return $build;
    }

    foreach ($servers as $id => $server) {
      assert($server instanceof ServerInterface);

      $own = array_filter($jobs, static fn(CronJobInterface $job): bool => in_array((string) $id, $job->getServerIds(), TRUE) && (bool) $job->get('status')->value);

      // A disabled server is not matched by hostname, so its worker falls
      // through to the jobs that belong to no server — and the ones assigned
      // to it specifically are picked up by nobody. Showing them here would
      // be showing work that is not happening.
      $will_run = $server->status() ? $own + $shared : $shared;

      $build[$id] = [
        '#theme' => 'druker_server_card',
        '#server' => $server,
        '#hostname' => $server->getHostname(),
        '#refresh' => $server->getDefaultRefresh(),
        '#enabled' => $server->status(),
        '#stranded' => $server->status() ? 0 : count($own),
        '#edit_url' => $server->toUrl('edit-form')->toString(),
        '#jobs' => array_map(fn(CronJobInterface $job): array => $this->jobSummary($job), $will_run),
        '#shared_count' => count($shared),
      ];
    }

    return $build;
  }

  /**
   * One job, reduced to what a card shows.
   */
  protected function jobSummary(CronJobInterface $job): array {
    return [
      'label' => $job->label(),
      'command' => $job->getCommand(),
      'runner' => $job->getRunner(),
      'shared' => $job->getServerIds() === [],
      'schedule' => $this->summarySchedule($job),
      'url' => $job->toUrl('edit-form')->toString(),
    ];
  }

  /**
   * What a card says about when a job runs.
   */
  protected function summarySchedule(CronJobInterface $job): string {
    if ($job->getTimingType() === CronJobInterface::TIMING_ONCE) {
      return (string) $this->t('once');
    }

    if ($job->getTimingType() === CronJobInterface::TIMING_INTERVAL) {
      $offset = $job->getTimingOffset();

      // The offset is on the card because it is the whole reason the same
      // job is on two servers at once without them colliding — reading one
      // card and not seeing it would make the pair look identical.
      return (string) ($offset > 0
        ? $this->t('every @every s +@offset', ['@every' => $job->getTimingEvery(), '@offset' => $offset])
        : $this->t('every @every s', ['@every' => $job->getTimingEvery()]));
    }

    return $this->jobManager->resolveCronExpression($job->getTimingCron());
  }

  /**
   * The day as a grid, so a three-in-the-morning pile-up is visible.
   */
  protected function day(array $jobs, array $servers): array {
    $rows = [];

    foreach ($jobs as $job) {
      assert($job instanceof CronJobInterface);

      // Only what will actually happen. A job the warnings above say never
      // runs has no business being drawn onto the day as though it does.
      if (!$this->willRun($job, $servers) || $job->getTimingType() === CronJobInterface::TIMING_ONCE) {
        continue;
      }

      $interval = $job->getTimingType() === CronJobInterface::TIMING_INTERVAL;

      // An interval job runs in every hour there is. Filling all twenty-four
      // is true but not enough on its own: an hourly cron job fills them too,
      // and the two are three orders of magnitude apart in how often they
      // run. So the row carries the period as well, and is drawn as a
      // continuous band rather than as twenty-four separate hits.
      $hours = $interval
        ? range(0, 23)
        : $this->jobManager->scheduleHours($job->getTimingCron());

      if ($hours === []) {
        continue;
      }

      $rows[] = [
        'label' => $job->label(),
        'url' => $job->toUrl('edit-form')->toString(),
        'hours' => $hours,
        'busy' => count($hours) === 24,
        'interval' => $interval,
        'every' => $interval ? $job->getTimingEvery() : NULL,
      ];
    }

    if ($rows === []) {
      return [];
    }

    return [
      '#theme' => 'druker_day',
      '#rows' => $rows,
      '#hours' => range(0, 23),
    ];
  }

  /**
   * Whether anything will pick this job up and run it.
   *
   * The one predicate both the warnings and the day use, so the two cannot
   * disagree about whether a job is real.
   */
  protected function willRun(CronJobInterface $job, array $servers): bool {
    if (!$job->get('status')->value) {
      return FALSE;
    }

    if ($job->getRunner() === CronJobInterface::RUNNER_SHELL && !$this->jobManager->shellAllowed()) {
      return FALSE;
    }

    if (!$this->jobManager->scheduleIsReadable($job)) {
      return FALSE;
    }

    $named = $job->getServerIds();

    if ($named === []) {
      return TRUE;
    }

    // One reachable server is enough: the others being off does not stop it.
    foreach ($named as $id) {
      if (isset($servers[$id]) && $servers[$id]->status()) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
