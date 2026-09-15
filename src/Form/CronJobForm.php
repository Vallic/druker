<?php

declare(strict_types=1);

namespace Drupal\druker\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\druker\Entity\CronJobInterface;
use Drupal\druker\Event\CollectJobsEvent;
use Drupal\druker\JobManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add and edit form for cron jobs.
 *
 * Everything here is validation. The fields themselves come from the entity,
 * but a job that says two contradictory things about when it runs, or names a
 * schedule the worker cannot read, is one that quietly never runs.
 */
class CronJobForm extends ContentEntityForm {

  /**
   * Which timing kind each field belongs to.
   *
   * Read three times over — to show the right fields, to validate the one
   * that matters, and to clear the ones that do not — so it is written once.
   */
  protected const TIMING_FIELDS = [
    'timing_cron' => CronJobInterface::TIMING_CRON,
    'timing_every' => CronJobInterface::TIMING_INTERVAL,
    'timing_offset' => CronJobInterface::TIMING_INTERVAL,
    'timing_once' => CronJobInterface::TIMING_ONCE,
  ];

  /**
   * Resolves and validates schedules the same way the worker does.
   */
  protected JobManager $jobManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->jobManager = $container->get('druker.job_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $entity = $this->entity;
    assert($entity instanceof CronJobInterface);

    $allowed = $this->jobManager->shellAllowed();

    $form['runner'] = [
      '#type' => 'radios',
      '#title' => $this->t('Run the command with'),
      '#options' => [
        CronJobInterface::RUNNER_DRUSH => $this->t('Drush'),
        CronJobInterface::RUNNER_SHELL => $this->t('A shell'),
      ],
      '#default_value' => $entity->getRunner(),
      '#required' => TRUE,
      '#weight' => -1,
    ];

    $form['runner'][CronJobInterface::RUNNER_DRUSH]['#description'] = $this->t('A Drush command and its arguments, without the leading %drush. Use %script to run a PHP file.', [
      '%drush' => 'drush',
      '%script' => 'php:script path/to/file.php',
    ]);

    $form['runner'][CronJobInterface::RUNNER_SHELL]['#description'] = $allowed
      ? $this->t('A command line, run through <code>sh -c</code>, so pipes and redirection work. It runs as the user the worker runs as.')
      : $this->t('Not enabled on this site. A shell job can run anything on the machine, so it has to be switched on in <code>settings.php</code>: <code>@setting</code>', [
        '@setting' => "\$settings['" . JobManager::ALLOW_SHELL . "'] = TRUE;",
      ]);

    $form['runner'][CronJobInterface::RUNNER_SHELL]['#disabled'] = !$allowed;

    $form['timing_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('When it runs'),
      '#options' => [
        CronJobInterface::TIMING_CRON => $this->t('Recurring'),
        CronJobInterface::TIMING_INTERVAL => $this->t('Interval'),
        CronJobInterface::TIMING_ONCE => $this->t('One-time'),
      ],
      // Derived from the entity rather than stored, so an existing job opens
      // on the kind it already is and there is no second copy of that fact to
      // fall out of step with the fields.
      '#default_value' => $entity->getTimingType(),
      '#required' => TRUE,
      '#weight' => 4,
    ];

    $form['timing_type'][CronJobInterface::TIMING_CRON]['#description'] = $this->t('On a clock: every night at two, every Monday, every five minutes.');
    $form['timing_type'][CronJobInterface::TIMING_INTERVAL]['#description'] = $this->t('Every n seconds, for the periods cron cannot say. Queue processors are what this is mostly for.');
    $form['timing_type'][CronJobInterface::TIMING_ONCE]['#description'] = $this->t('At one moment, once. A migration, a one-off rebuild.');

    // Only the fields belonging to the chosen kind are shown. This is a
    // convenience, not a guard: #states hides in the browser and the hidden
    // fields still post, so validateForm() and buildEntity() both go by the
    // chosen kind rather than by what happens to be filled in.
    foreach (self::TIMING_FIELDS as $field => $type) {
      $form[$field]['#states'] = [
        'visible' => [':input[name="timing_type"]' => ['value' => $type]],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    // ContentEntityForm sends you to the entity's canonical route, which for
    // a job is a page with nothing on it: there is no view display, so it
    // renders the label and stops. The list is where the job you just saved
    // is actually shown — beside the others, with the schedule resolved —
    // and it is where the server form already lands.
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));

    return $result;
  }

  /**
   * Whether the run-at field actually holds a date the person entered.
   *
   * The widget hands back a DrupalDateTime once it has parsed one, and the
   * raw date/time strings before that, so both shapes have to count.
   */
  protected static function dateWasGiven(FormStateInterface $form_state): bool {
    $value = $form_state->getValue(['timing_once', 0, 'value']);

    if ($value instanceof DrupalDateTime) {
      return TRUE;
    }

    if (is_array($value)) {
      return trim((string) ($value['date'] ?? '')) !== '';
    }

    return trim((string) $value) !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state): ContentEntityInterface {
    $entity = parent::buildEntity($form, $form_state);
    assert($entity instanceof ContentEntityInterface);

    $chosen = (string) $form_state->getValue('timing_type');

    // Everything that does not belong to the chosen kind is cleared, so a job
    // is only ever one kind of job. Two reasons it cannot be left to the
    // person filling the form in:
    //
    // - #states hides fields in the browser; they are still posted. Switching
    //   a job from cron to interval would otherwise leave the old expression
    //   behind it, and getTimingType() reads the fields.
    // - Core's timestamp widget fills an empty run-at date with the request
    //   time — TimestampDatetimeWidget::massageFormValues() ends on "else {
    //   $date = new DrupalDateTime(); }". Left alone, every job saved here
    //   comes out with a run-at date of the moment it was saved, and a
    //   run-at date wins, so the job would run once and never again whatever
    //   was typed beside it.
    foreach (self::TIMING_FIELDS as $field => $type) {
      if ($type !== $chosen) {
        $entity->set($field, NULL);
      }
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): ContentEntityInterface {
    $entity = parent::validateForm($form, $form_state);

    $chosen = (string) $form_state->getValue('timing_type');
    $cron = trim((string) $form_state->getValue(['timing_cron', 0, 'value']));
    $every = $form_state->getValue(['timing_every', 0, 'value']);
    $offset = (int) $form_state->getValue(['timing_offset', 0, 'value']);

    // Only the chosen kind is checked. The others are cleared on the way into
    // the entity, so whatever is sitting in them is not this job's schedule
    // and complaining about it would be complaining about nothing.
    switch ($chosen) {
      case CronJobInterface::TIMING_CRON:
        if ($cron === '') {
          $form_state->setErrorByName('timing_cron', $this->t('A recurring job needs a cron expression.'));
        }
        elseif (!$this->jobManager->isValidCronExpression($cron)) {
          $form_state->setErrorByName('timing_cron', $this->t('"%cron" is not a valid cron expression or recognized shorthand.', ['%cron' => $cron]));
        }
        break;

      case CronJobInterface::TIMING_INTERVAL:
        if ($every === NULL || $every === '') {
          $form_state->setErrorByName('timing_every', $this->t('An interval job needs a period in seconds.'));
        }
        elseif (!$this->jobManager->isValidInterval((int) $every, $offset)) {
          $form_state->setErrorByName('timing_every', $this->t('A repeat period must be at least @minimum seconds, and the offset cannot be negative. Every run is a process and a full Drupal bootstrap, and a job still running when its next turn comes round is skipped.', [
            '@minimum' => CollectJobsEvent::MINIMUM_INTERVAL,
          ]));
        }
        break;

      case CronJobInterface::TIMING_ONCE:
        if (!self::dateWasGiven($form_state)) {
          $form_state->setErrorByName('timing_once', $this->t('A one-time job needs a date and time to run at.'));
        }
        break;
    }

    // Belt and braces: a disabled radio can still be posted.
    if ($form_state->getValue('runner') === CronJobInterface::RUNNER_SHELL && !$this->jobManager->shellAllowed()) {
      $form_state->setErrorByName('runner', $this->t('Shell jobs are not enabled on this site.'));
    }

    $depends_on = $form_state->getValue(['depends_on', 0, 'target_id']);
    if (!empty($depends_on) && !$entity->isNew() && (int) $depends_on === (int) $entity->id()) {
      $form_state->setErrorByName(
        'depends_on',
        $this->t('A job cannot depend on itself.')
      );
    }

    return $entity;
  }

}
