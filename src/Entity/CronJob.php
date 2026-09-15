<?php

declare(strict_types=1);

namespace Drupal\druker\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\druker\CronJobListBuilder;
use Drupal\druker\Event\CollectJobsEvent;
use Drupal\druker\Form\CronJobForm;

/**
 * One scheduled job: a command, and when to run it.
 */
#[ContentEntityType(
  id: 'druker_job',
  label: new TranslatableMarkup('Cron Job'),
  label_collection: new TranslatableMarkup('Cron Jobs'),
  label_singular: new TranslatableMarkup('cron job'),
  label_plural: new TranslatableMarkup('cron jobs'),
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => CronJobListBuilder::class,
    'form' => [
      'add' => CronJobForm::class,
      'edit' => CronJobForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'canonical' => '/admin/config/system/druker/jobs/{druker_job}',
    'add-form' => '/admin/config/system/druker/jobs/add',
    'edit-form' => '/admin/config/system/druker/jobs/{druker_job}/edit',
    'delete-form' => '/admin/config/system/druker/jobs/{druker_job}/delete',
    'collection' => '/admin/config/system/druker/jobs',
  ],
  admin_permission: 'administer druker',
  base_table: 'druker_job',
  label_count: [
    'singular' => '@count cron job',
    'plural' => '@count cron jobs',
  ],
)]
class CronJob extends ContentEntityBase implements CronJobInterface {

  /**
   * {@inheritdoc}
   */
  public function getCommand(): string {
    return $this->get('command')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getTimingCron(): string {
    return $this->get('timing_cron')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getTimingOnce(): ?int {
    $value = $this->get('timing_once')->value;
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimingEvery(): ?int {
    $value = $this->get('timing_every')->value;
    return $value !== NULL && $value !== '' ? (int) $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimingOffset(): int {
    return (int) ($this->get('timing_offset')->value ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function getTimingType(): string {
    if ($this->getTimingOnce() !== NULL) {
      return CronJobInterface::TIMING_ONCE;
    }

    if ($this->getTimingEvery() !== NULL) {
      return CronJobInterface::TIMING_INTERVAL;
    }

    return CronJobInterface::TIMING_CRON;
  }

  /**
   * {@inheritdoc}
   */
  public function getRunner(): string {
    $value = (string) ($this->get('runner')->value ?? '');

    return $value !== '' ? $value : CronJobInterface::RUNNER_DRUSH;
  }

  /**
   * {@inheritdoc}
   */
  public function getServerIds(): array {
    $ids = [];

    foreach ($this->get('server')->getValue() as $item) {
      if (($item['target_id'] ?? '') !== '') {
        $ids[] = (string) $item['target_id'];
      }
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function isAsync(): bool {
    return (bool) $this->get('async')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDependsOnId(): ?int {
    if ($this->get('depends_on')->isEmpty()) {
      return NULL;
    }
    return (int) $this->get('depends_on')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 255])
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10]);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Enabled'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => -5]);

    $fields['command'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Command'))
      ->setDescription(t('Drush command to execute, e.g. "advancedqueue:queue:process mail".'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 512])
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0]);

    $fields['runner'] = self::runnerFieldDefinition();

    $fields['timing_cron'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Cron expression'))
      ->setDescription(t('Standard cron syntax ("0 2 * * *"), @-shortcut ("@daily"), or plain English ("every 5 minutes"). Leave empty for a one-time job, or one that repeats on a period cron cannot express.'))
      ->setSettings(['max_length' => 255])
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 5]);

    $fields['timing_once'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Run at'))
      ->setDescription(t('Date and time to run this job once. Leave empty for a recurring job.'))
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => 6]);

    $fields['timing_every'] = self::timingEveryFieldDefinition();
    $fields['timing_offset'] = self::timingOffsetFieldDefinition();

    $fields['server'] = self::serverFieldDefinition();

    $fields['async'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Run asynchronously'))
      ->setDescription(t('When enabled, the worker runs this job in a goroutine and does not wait for it to finish before moving on. Disable to block until the job exits.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 15]);

    $fields['depends_on'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Run after job'))
      ->setDescription(t('If set, this job will only start after the referenced job has finished in the same cycle.'))
      ->setSetting('target_type', 'druker_job')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 16]);

    return $fields;
  }

  /**
   * The server field, also used by the update that made it multi-value.
   *
   * A job can name more than one server. Some queues are worth working from
   * several boxes at once — Advanced Queue hands each worker different items,
   * so three servers on one queue is three times the throughput rather than
   * the same work done three times.
   */
  public static function serverFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Servers'))
      ->setDescription(t('The servers this job runs on. Leave every box clear to run it on all of them.'))
      ->setSetting('target_type', 'druker_server')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => 10]);
  }

  /**
   * The interval period field, also used by the update that adds it.
   */
  public static function timingEveryFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('integer')
      ->setLabel(t('Repeat every'))
      ->setDescription(t('Seconds between runs, for a period cron cannot say — every 30 seconds, every 75. Runs are anchored to the clock, so "every 30" means :00 and :30 of every minute on every server. Leave empty unless this is what you want; the shortest allowed is @minimum seconds.', ['@minimum' => CollectJobsEvent::MINIMUM_INTERVAL]))
      ->setSetting('min', CollectJobsEvent::MINIMUM_INTERVAL)
      ->setSetting('suffix', ' ' . t('seconds'))
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 7]);
  }

  /**
   * The interval offset field, also used by the update that adds it.
   */
  public static function timingOffsetFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('integer')
      ->setLabel(t('Offset'))
      ->setDescription(t('Seconds to shift those runs by. The same job given 0 on one server and 4 on another stays four seconds apart on the two, which is how one queue is processed by several machines without them all starting together. Only meaningful with a repeat period.'))
      ->setSetting('min', 0)
      ->setSetting('suffix', ' ' . t('seconds'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 8]);
  }

  /**
   * The runner field, also used by the update that adds it.
   */
  public static function runnerFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('string')
      ->setLabel(t('Runner'))
      ->setDescription(t('Whether the command is a Drush command or a shell command line.'))
      ->setRequired(TRUE)
      ->setDefaultValue(CronJobInterface::RUNNER_DRUSH)
      ->setSettings(['max_length' => 16]);
  }

}
