<?php

declare(strict_types=1);

namespace Drupal\Tests\druker\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\druker\Entity\CronJob;
use Drupal\druker\Entity\CronJobInterface;
use Drupal\druker\Entity\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the one question the card and the warnings both have to answer.
 *
 * Whether a job is stranded was worked out in two places and they disagreed:
 * a job naming a disabled server and a live one was counted as abandoned on
 * the card while the warning beside it said the live server still ran it.
 */
#[Group('druker')]
class StrandedJobsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['druker', 'system', 'user'];

  /**
   * The job manager under test.
   *
   * @var \Drupal\druker\JobManager
   */
  protected $jobManager;

  /**
   * Every server, keyed by id, as the callers hold them.
   *
   * @var \Drupal\druker\Entity\ServerInterface[]
   */
  protected array $servers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('druker_job');
    $this->installEntitySchema('user');

    Server::create(['id' => 'live', 'label' => 'Live', 'hostname' => 'live-box', 'status' => TRUE])->save();
    Server::create(['id' => 'off', 'label' => 'Off', 'hostname' => 'off-box', 'status' => FALSE])->save();

    $this->jobManager = $this->container->get('druker.job_manager');
    $this->servers = $this->container->get('entity_type.manager')
      ->getStorage('druker_server')
      ->loadMultiple();
  }

  /**
   * Builds a saved job naming the given servers.
   */
  protected function job(array $servers): CronJobInterface {
    $job = CronJob::create([
      'label' => 'Test job',
      'command' => 'cron',
      'timing_cron' => '0 3 * * *',
      'runner' => CronJobInterface::RUNNER_DRUSH,
      'status' => TRUE,
      'async' => TRUE,
      'server' => $servers,
    ]);
    $job->save();

    return $job;
  }

  /**
   * A job naming nothing but disabled or missing servers is stranded.
   */
  public function testJobNamingOnlyDeadServersIsStranded(): void {
    $this->assertTrue($this->jobManager->isStranded($this->job(['off']), $this->servers));
    $this->assertTrue($this->jobManager->isStranded($this->job(['gone']), $this->servers));
    $this->assertTrue($this->jobManager->isStranded($this->job(['off', 'gone']), $this->servers));
  }

  /**
   * One server left is enough: that one still runs it.
   */
  public function testJobWithOneLiveServerIsNotStranded(): void {
    $this->assertFalse($this->jobManager->isStranded($this->job(['off', 'live']), $this->servers));
    $this->assertSame(['live'], $this->jobManager->reachableServerIds($this->job(['off', 'live']), $this->servers));
  }

  /**
   * A job naming no server runs everywhere, which is not nowhere.
   */
  public function testJobNamingNoServerIsNotStranded(): void {
    $this->assertFalse($this->jobManager->isStranded($this->job([]), $this->servers));
    $this->assertSame([], $this->jobManager->reachableServerIds($this->job([]), $this->servers));
  }

}
