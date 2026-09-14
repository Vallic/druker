<?php

declare(strict_types=1);

namespace Drupal\Tests\druker\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Drupal\druker\Event\CollectJobsEvent;
use Drupal\Tests\UnitTestCase;

/**
 * Covers the event other modules use to add jobs of their own.
 */
#[CoversClass(CollectJobsEvent::class)]
#[Group('druker')]
class CollectJobsEventTest extends UnitTestCase {

  /**
   * The event says which server the jobs are being collected for.
   */
  public function testGetHostname(): void {
    $event = new CollectJobsEvent('web-01');
    $this->assertSame('web-01', $event->getHostname());
  }

  /**
   * The refresh starts at whatever the server said.
   */
  public function testRefreshDefaultsToWhatItWasGiven(): void {
    $event = new CollectJobsEvent('web-01', [], 300);
    $this->assertSame(300, $event->getRefresh());
  }

  /**
   * An event told nothing comes out on the module default.
   */
  public function testRefreshFallsBackToTheDefault(): void {
    $event = new CollectJobsEvent('web-01');
    $this->assertSame(CollectJobsEvent::DEFAULT_REFRESH, $event->getRefresh());
    $this->assertSame(1800, $event->getRefresh());
  }

  /**
   * The floor applies to what was configured, not only to what is asked for.
   *
   * A server saved before the minimum existed, or a config YAML written by
   * hand, can carry a value the worker should not be made to honour.
   */
  public function testStoredRefreshBelowTheFloorIsClamped(): void {
    $event = new CollectJobsEvent('web-01', [], 5);
    $this->assertSame(CollectJobsEvent::MINIMUM_REFRESH, $event->getRefresh());
  }

  /**
   * A subscriber can move it, in either direction.
   */
  public function testSubscriberCanChangeTheRefresh(): void {
    $event = new CollectJobsEvent('web-01', [], 600);

    $event->setRefresh(60);
    $this->assertSame(60, $event->getRefresh());

    $event->setRefresh(3600);
    $this->assertSame(3600, $event->getRefresh());
  }

  /**
   * Asking for an unreasonably short refresh is clamped, not obeyed.
   *
   * Every refresh is a Drush call on every server; a two-second poll means
   * the worker spends its life asking what to do rather than doing it.
   */
  public function testTooShortRefreshIsClamped(): void {
    $event = new CollectJobsEvent('web-01', [], 600);
    $event->setRefresh(2);

    $this->assertSame(CollectJobsEvent::MINIMUM_REFRESH, $event->getRefresh());
  }

  /**
   * An event built with no jobs starts empty.
   */
  public function testGetJobsEmptyByDefault(): void {
    $event = new CollectJobsEvent('web-01');
    $this->assertSame([], $event->getJobs());
  }

  /**
   * Jobs passed in at construction are returned.
   */
  public function testGetJobsReturnsSeedJobs(): void {
    $job = ['id' => 1, 'command' => 'cron', 'type' => 'cron'];
    $event = new CollectJobsEvent('web-01', [$job]);
    $this->assertSame([$job], $event->getJobs());
  }

  /**
   * A subscriber can add a job.
   */
  public function testAddJobAppendsToList(): void {
    $event = new CollectJobsEvent('web-01');
    $job1 = ['id' => 10, 'command' => 'foo'];
    $job2 = ['id' => 11, 'command' => 'bar'];
    $event->addJob($job1);
    $event->addJob($job2);
    $this->assertSame([$job1, $job2], $event->getJobs());
  }

  /**
   * Added jobs go after the ones already there, not instead of them.
   */
  public function testAddJobAppendsBeyondSeedJobs(): void {
    $seed = ['id' => 1, 'command' => 'seed'];
    $extra = ['id' => 2, 'command' => 'extra'];
    $event = new CollectJobsEvent('web-01', [$seed]);
    $event->addJob($extra);
    $this->assertSame([$seed, $extra], $event->getJobs());
  }

}
