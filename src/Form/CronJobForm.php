<?php

declare(strict_types=1);

namespace Drupal\druker\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\druker\Entity\CronJobInterface;
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): ContentEntityInterface {
    $entity = parent::validateForm($form, $form_state);

    $cron = trim((string) $form_state->getValue(['timing_cron', 0, 'value']));
    $once = $form_state->getValue(['timing_once', 0, 'value']);

    if ($cron === '' && empty($once)) {
      $form_state->setError(
        $form['timing_cron'],
        $this->t('Set either a cron expression (recurring) or a run-at date (one-time).')
      );
    }
    elseif ($cron !== '' && !empty($once)) {
      $form_state->setError(
        $form['timing_cron'],
        $this->t('Set either a cron expression or a run-at date, not both.')
      );
    }
    elseif ($cron !== '' && !$this->jobManager->isValidCronExpression($cron)) {
      $form_state->setErrorByName(
        'timing_cron',
        $this->t('"%cron" is not a valid cron expression or recognised shorthand.', ['%cron' => $cron])
      );
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
