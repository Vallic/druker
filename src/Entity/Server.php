<?php

declare(strict_types=1);

namespace Drupal\druker\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\druker\Event\CollectJobsEvent;
use Drupal\druker\Form\ServerForm;
use Drupal\druker\ServerListBuilder;

/**
 * One machine running a worker, matched to it by hostname.
 */
#[ConfigEntityType(
  id: 'druker_server',
  label: new TranslatableMarkup('Server'),
  label_collection: new TranslatableMarkup('Servers'),
  config_prefix: 'server',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => ServerListBuilder::class,
    'form' => [
      'add' => ServerForm::class,
      'edit' => ServerForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  // No canonical link: a config entity has no view builder, so the route
  // provider never makes that route, and the list builder 500s the moment it
  // tries to link a row to it.
  links: [
    'add-form' => '/admin/config/system/druker/servers/add',
    'edit-form' => '/admin/config/system/druker/servers/{druker_server}/edit',
    'delete-form' => '/admin/config/system/druker/servers/{druker_server}/delete',
    'collection' => '/admin/config/system/druker/servers',
  ],
  admin_permission: 'administer druker',
  config_export: [
    'id',
    'label',
    'hostname',
    'status',
    'default_refresh',
  ],
)]
class Server extends ConfigEntityBase implements ServerInterface {

  /**
   * The machine name.
   */
  protected string $id = '';

  /**
   * The human-readable name.
   */
  protected string $label = '';

  /**
   * The hostname the worker on this server reports.
   */
  protected string $hostname = '';

  // phpcs:ignore
  protected $status = TRUE;

  /**
   * Seconds between the worker asking for its schedule again.
   */
  protected int $default_refresh = CollectJobsEvent::DEFAULT_REFRESH;

  /**
   * {@inheritdoc}
   */
  public function getHostname(): string {
    return $this->hostname;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultRefresh(): int {
    return $this->default_refresh;
  }

}
