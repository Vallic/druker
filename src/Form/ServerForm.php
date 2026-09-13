<?php

declare(strict_types=1);

namespace Drupal\druker\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\druker\Entity\ServerInterface;

/**
 * Add and edit form for servers.
 *
 * A config entity gets no fields of its own, so every one of them is here.
 */
class ServerForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $server = $this->entity;
    assert($server instanceof ServerInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $server->label(),
      '#required' => TRUE,
      '#description' => $this->t('What you call this machine, such as %example.', ['%example' => 'Web 1']),
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $server->id(),
      '#machine_name' => ['exists' => [$this, 'exists']],
      '#disabled' => !$server->isNew(),
    ];

    $form['hostname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hostname'),
      '#default_value' => $server->getHostname(),
      '#required' => TRUE,
      '#description' => $this->t('Exactly what the machine reports for <code>hostname</code>. A worker finds its jobs by matching this, so a typo here means it silently picks up only the jobs assigned to no server.'),
    ];

    $form['default_refresh'] = [
      '#type' => 'number',
      '#title' => $this->t('Re-read the schedule every'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 10,
      '#default_value' => $server->getDefaultRefresh(),
      '#required' => TRUE,
      '#description' => $this->t('How long a change made here takes to reach this server. Shorter means more Drush calls doing nothing; ten minutes is a reasonable default.'),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $server->isNew() ? TRUE : $server->status(),
      '#description' => $this->t('A disabled server is not matched, so its worker falls back to the jobs assigned to no server.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $hostname = trim((string) $form_state->getValue('hostname'));

    // Two servers on one hostname means whichever loads first wins, quietly.
    foreach ($this->entityTypeManager->getStorage('druker_server')->loadByProperties(['hostname' => $hostname]) as $existing) {
      if ($existing->id() !== $this->entity->id()) {
        $form_state->setErrorByName('hostname', $this->t('%hostname is already used by the %label server.', [
          '%hostname' => $hostname,
          '%label' => $existing->label(),
        ]));
        break;
      }
    }
  }

  /**
   * Machine name uniqueness check.
   */
  public function exists(string $id): bool {
    return (bool) $this->entityTypeManager->getStorage('druker_server')->load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = $this->entity->save();

    $this->messenger()->addStatus($this->t('Server %label saved.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));

    return $result;
  }

}
