<?php

declare(strict_types=1);

namespace Drupal\Tests\druker\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\druker\Entity\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the config schema for the server entity type.
 *
 * KernelTestBase validates every config write against the schema, so saving
 * a server here is the assertion: a missing key, a wrong type or a schema
 * that does not exist at all fails the test on save.
 */
#[Group('druker')]
class ServerSchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['druker', 'system', 'user'];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->container->get('entity_type.manager');
  }

  /**
   * A saved server matches its schema, with every key covered.
   */
  public function testSavedServerMatchesItsSchema(): void {
    Server::create([
      'id' => 'web_01',
      'label' => 'Web 01',
      'hostname' => 'web10',
      'status' => TRUE,
      'default_refresh' => 1800,
    ])->save();

    $stored = $this->config('druker.server.web_01');

    $this->assertSame('web10', $stored->get('hostname'));
    $this->assertSame(1800, $stored->get('default_refresh'));
  }

  /**
   * The schema is what casts a written value to the type it declares.
   *
   * This is the bug the schema fixes rather than merely documents. Config::
   * save() casts data to its schema types, so with no schema there was
   * nothing to cast against and a seed script writing the integer 1 for the
   * enabled flag left an integer in the stored config. Saving a server
   * through the entity API always normalized it, which is why the four
   * servers on a real site disagreed with each other.
   */
  public function testTheSchemaCastsWrittenValuesToTheDeclaredType(): void {
    $this->config('druker.server.web_02')
      ->setData([
        'id' => 'web_02',
        'label' => 'Web 02',
        'hostname' => 'web11',
        // What a seed script hands over, and what used to be stored as-is.
        'status' => 1,
        'default_refresh' => 1800,
      ])
      ->save();

    $status = $this->config('druker.server.web_02')->get('status');

    $this->assertIsBool($status);
    $this->assertTrue($status);
  }

  /**
   * Defaults are filled in for anything the caller left out.
   */
  public function testTheRefreshDefaultsToHalfAnHour(): void {
    Server::create([
      'id' => 'web_03',
      'label' => 'Web 03',
      'hostname' => 'web12',
    ])->save();

    $this->assertSame(1800, $this->config('druker.server.web_03')->get('default_refresh'));
  }

}
