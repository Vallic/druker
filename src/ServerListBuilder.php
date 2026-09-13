<?php

declare(strict_types=1);

namespace Drupal\druker;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\druker\Entity\ServerInterface;

/**
 * Lists the servers, and what each one is called on the network.
 */
class ServerListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Name');
    $header['hostname'] = $this->t('Hostname');
    $header['refresh'] = $this->t('Re-reads the schedule');
    $header['status'] = $this->t('Status');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof ServerInterface);

    $row['label'] = $entity->label();
    $row['hostname'] = [
      'data' => [
        '#type' => 'inline_template',
        '#template' => '<code>{{ hostname }}</code>',
        '#context' => ['hostname' => $entity->getHostname()],
      ],
    ];
    $row['refresh'] = $this->formatPlural(
      $entity->getDefaultRefresh(),
      'Every second',
      'Every @count seconds',
    );
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No servers yet. A worker finds its jobs by matching its own hostname against these, so add one per machine that will run it.');

    return $build;
  }

}
