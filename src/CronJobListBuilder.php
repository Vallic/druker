<?php

declare(strict_types=1);

namespace Drupal\druker;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\druker\Entity\CronJobInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the cron jobs, showing what each one runs and when.
 *
 * The schedule column shows the expression the worker will actually receive,
 * not the text that was typed. "Every 5 minutes" is friendlier to write and
 * useless to check against a log at two in the morning.
 */
class CronJobListBuilder extends EntityListBuilder {

  /**
   * Resolves and validates schedules the same way the worker does.
   */
  protected JobManager $jobManager;

  /**
   * Formats the moment a one-time job runs.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Loads the server a job is assigned to.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $instance = parent::createInstance($container, $entity_type);

    $instance->jobManager = $container->get('druker.job_manager');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->entityTypeManager = $container->get('entity_type.manager');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Name');
    $header['command'] = $this->t('Command');
    $header['runner'] = $this->t('Runner');
    $header['schedule'] = $this->t('Schedule');
    $header['server'] = $this->t('Server');
    $header['runs'] = $this->t('Runs');
    $header['status'] = $this->t('Status');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof CronJobInterface);

    $row['label'] = $entity->label();
    $row['command'] = ['data' => $this->code($entity->getCommand())];
    $row['runner'] = $this->runner($entity);
    $row['schedule'] = $this->schedule($entity);
    $row['server'] = $this->server($entity);
    $row['runs'] = $this->runs($entity);
    $row['status'] = $entity->get('status')->value ? $this->t('Enabled') : $this->t('Disabled');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No jobs yet. A job is a Drush command and a schedule; the worker on each server reads the ones meant for it.');

    return $build;
  }

  /**
   * When the job runs, as the worker will read it.
   */
  protected function schedule(CronJobInterface $job): array|TranslatableMarkup {
    $once = $job->getTimingOnce();

    if ($once !== NULL) {
      return $this->t('Once, @date', [
        '@date' => $this->dateFormatter->format($once, 'custom', 'Y-m-d H:i T'),
      ]);
    }

    if (($every = $job->getTimingEvery()) !== NULL) {
      if (!$this->jobManager->isValidInterval($every, $job->getTimingOffset())) {
        return $this->unreadable((string) $this->t('Every @every seconds', ['@every' => $every]));
      }

      $offset = $job->getTimingOffset();

      return $offset > 0
        ? $this->t('Every @every seconds, offset @offset', ['@every' => $every, '@offset' => $offset])
        : $this->t('Every @every seconds', ['@every' => $every]);
    }

    $typed = $job->getTimingCron();
    $resolved = $this->jobManager->resolveCronExpression($typed);

    if (!$this->jobManager->isValidCronExpression($typed)) {
      return $this->unreadable($typed);
    }

    if ($resolved === $typed) {
      return ['data' => $this->code($resolved)];
    }

    return [
      'data' => [
        '#type' => 'inline_template',
        '#template' => '<code>{{ cron }}</code><br><small>{{ typed }}</small>',
        '#context' => ['cron' => $resolved, 'typed' => $typed],
      ],
    ];
  }

  /**
   * Which runner the job uses, and whether this site permits it.
   */
  protected function runner(CronJobInterface $job): array|TranslatableMarkup {
    if ($job->getRunner() !== CronJobInterface::RUNNER_SHELL) {
      return $this->t('Drush');
    }

    if ($this->jobManager->shellAllowed()) {
      return $this->t('Shell');
    }

    // Said here because the job is otherwise invisible in its absence: the
    // schedule handed to the worker leaves it out entirely.
    return [
      'data' => [
        '#type' => 'inline_template',
        '#template' => '<strong>{{ warning }}</strong>',
        '#context' => ['warning' => $this->t('Shell, not enabled here')],
      ],
    ];
  }

  /**
   * A schedule the worker cannot read, said plainly.
   *
   * Rather than left for someone to notice in a log: the worker drops a job
   * whose schedule it cannot read, and it simply never runs.
   */
  protected function unreadable(string $typed): array {
    return [
      'data' => [
        '#type' => 'inline_template',
        '#template' => '<strong>{{ warning }}</strong><br><code>{{ typed }}</code>',
        '#context' => [
          'warning' => $this->t('Unreadable, so this never runs'),
          'typed' => $typed,
        ],
      ],
    ];
  }

  /**
   * One value as code, which is how a command and an expression read best.
   */
  protected function code(string $text): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<code>{{ text }}</code>',
      '#context' => ['text' => $text],
    ];
  }

  /**
   * Which server runs it.
   */
  protected function server(CronJobInterface $job) {
    $ids = $job->getServerIds();

    if ($ids === []) {
      return $this->t('Every server');
    }

    $servers = $this->entityTypeManager->getStorage('druker_server')->loadMultiple($ids);
    $names = [];

    foreach ($ids as $id) {
      $names[] = isset($servers[$id])
        ? (string) $servers[$id]->label()
        : (string) $this->t('Unknown (@id)', ['@id' => $id]);
    }

    sort($names);

    return implode(', ', $names);
  }

  /**
   * How the job behaves around other jobs.
   */
  protected function runs(CronJobInterface $job) {
    $parts = [$job->isAsync() ? $this->t('Alongside others') : $this->t('On its own')];

    if (($depends_on = $job->getDependsOnId()) !== NULL) {
      $other = $this->storage->load($depends_on);
      $parts[] = $this->t('after @job', ['@job' => $other !== NULL ? $other->label() : '#' . $depends_on]);
    }

    return implode(', ', array_map('strval', $parts));
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $operations = parent::getDefaultOperations($entity, $cacheability);

    // The canonical page for a job says nothing the list does not, and an
    // editor clicking the name expects the form.
    unset($operations['view']);

    assert($entity instanceof CronJobInterface);
    $enabled = (bool) $entity->get('status')->value;

    // Ahead of Delete, because switching a job off is what people actually
    // want when they reach for Delete and what they should reach for first.
    $operations['toggle'] = [
      'title' => $enabled ? $this->t('Disable') : $this->t('Enable'),
      'weight' => 20,
      'url' => Url::fromRoute('druker.job_toggle', ['druker_job' => $entity->id()]),
    ];

    if (isset($operations['delete'])) {
      $operations['delete']['weight'] = 30;
    }

    return $operations;
  }

}
