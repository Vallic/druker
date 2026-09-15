<?php

declare(strict_types=1);

namespace Drupal\Tests\druker\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\druker\Entity\CronJob;
use Drupal\druker\Entity\CronJobInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers interval jobs stored as entities and set in the admin UI.
 *
 * The event path is covered by CollectJobsEventTest. What is tested here is
 * the other half: a job someone typed a period into, from the entity through
 * formatJob() to the payload the worker parses.
 */
#[Group('druker')]
class IntervalJobTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('druker_job');
    $this->installEntitySchema('user');

    $this->jobManager = $this->container->get('druker.job_manager');
  }

  /**
   * Creates a saved job from the values given.
   */
  protected function job(array $values): CronJobInterface {
    $job = CronJob::create($values + [
      'label' => 'Test job',
      'command' => 'advancedqueue:queue:process mail',
      'runner' => CronJobInterface::RUNNER_DRUSH,
      'status' => TRUE,
      'async' => TRUE,
    ]);
    $job->save();

    return $job;
  }

  /**
   * Returns the payload entry for a job, by its id.
   */
  protected function payloadFor(CronJobInterface $job): ?array {
    foreach ($this->jobManager->getJobsForServer('any-host')['jobs'] as $entry) {
      if ((int) $entry['id'] === (int) $job->id()) {
        return $entry;
      }
    }

    return NULL;
  }

  /**
   * A period typed into the form reaches the worker as an interval job.
   */
  public function testStoredPeriodBecomesAnIntervalJob(): void {
    $job = $this->job(['timing_every' => 20, 'timing_offset' => 7]);

    $this->assertSame(20, $job->getTimingEvery());
    $this->assertSame(7, $job->getTimingOffset());

    $entry = $this->payloadFor($job);

    $this->assertSame('interval', $entry['type']);
    $this->assertSame(20, $entry['every']);
    $this->assertSame(7, $entry['offset']);
    $this->assertArrayNotHasKey('cron', $entry);
  }

  /**
   * An offset left alone is zero, not NULL, so the worker never has to guess.
   */
  public function testTheOffsetDefaultsToZero(): void {
    $entry = $this->payloadFor($this->job(['timing_every' => 30]));

    $this->assertSame(0, $entry['offset']);
  }

  /**
   * The other two types are unchanged by the third existing.
   */
  public function testCronAndOnceStillFormatAsTheyDid(): void {
    $cron = $this->payloadFor($this->job(['timing_cron' => '@daily']));
    $this->assertSame('cron', $cron['type']);
    $this->assertSame('0 0 * * *', $cron['cron']);
    $this->assertArrayNotHasKey('every', $cron);

    $once = $this->payloadFor($this->job(['timing_once' => 1747392000]));
    $this->assertSame('once', $once['type']);
    $this->assertSame(1747392000, $once['run_at']);
    $this->assertArrayNotHasKey('every', $once);
  }

  /**
   * Whether a schedule is readable is one question, asked for all three kinds.
   *
   * The dashboard's warnings and its day grid both rely on the same answer.
   */
  public function testScheduleIsReadableCoversEveryKind(): void {
    $this->assertTrue($this->jobManager->scheduleIsReadable($this->job(['timing_cron' => '0 2 * * *'])));
    $this->assertTrue($this->jobManager->scheduleIsReadable($this->job(['timing_once' => 1747392000])));
    $this->assertTrue($this->jobManager->scheduleIsReadable($this->job(['timing_every' => 30])));

    $this->assertFalse($this->jobManager->scheduleIsReadable($this->job(['timing_cron' => '99 * * * *'])));
    // Below the floor, so the worker would drop it: the dashboard has to say
    // so rather than drawing it onto the day as though it runs.
    $this->assertFalse($this->jobManager->scheduleIsReadable($this->job(['timing_every' => 1])));
  }

  /**
   * The floor and the sign of the offset are the worker's rules, mirrored.
   */
  public function testTheIntervalRulesMatchTheWorker(): void {
    $this->assertTrue($this->jobManager->isValidInterval(5));
    $this->assertTrue($this->jobManager->isValidInterval(20, 7));
    $this->assertFalse($this->jobManager->isValidInterval(4));
    $this->assertFalse($this->jobManager->isValidInterval(0));
    $this->assertFalse($this->jobManager->isValidInterval(20, -1));
  }

}
