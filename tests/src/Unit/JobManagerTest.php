<?php

declare(strict_types=1);

namespace Drupal\Tests\druker\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Site\Settings;
use Drupal\druker\Entity\CronJobInterface;
use Drupal\druker\Entity\ServerInterface;
use Drupal\druker\Event\CollectJobsEvent;
use Drupal\druker\JobManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Covers what the worker is told to run.
 */
#[CoversClass(JobManager::class)]
#[Group('druker')]
class JobManagerTest extends UnitTestCase {

  /**
   * The manager under test.
   */
  protected JobManager $manager;

  /**
   * Mocked entity storage.
   */
  protected EntityTypeManagerInterface&MockObject $entityTypeManager;

  /**
   * Mocked dispatcher, so other modules can add jobs.
   */
  protected EventDispatcherInterface&MockObject $eventDispatcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

    // Shell jobs off, which is what an untouched site looks like. The tests
    // that care about the gate build their own manager.
    $this->manager = $this->managerWith([]);
  }

  /**
   * A manager reading the given settings.php values.
   */
  private function managerWith(array $settings): JobManager {
    return new JobManager(
      $this->entityTypeManager,
      $this->eventDispatcher,
      new Settings($settings),
    );
  }

  /**
   * Shorthands and plain English resolve to real cron expressions.
   */
  #[DataProvider('provideCronExpressions')]
  public function testResolveCronExpression(string $input, string $expected): void {
    $this->assertSame($expected, $this->manager->resolveCronExpression($input));
  }

  /**
   * Validation has to accept and reject exactly what the worker does.
   *
   * The worker parses cron itself and drops what it cannot read, silently as
   * far as the admin UI is concerned: the job just never runs. These cases
   * mirror the worker's own TestCronMatching and TestRejectsNonsense, and if
   * the two ever drift this is the test that should fail first.
   */
  #[DataProvider('provideCronValidity')]
  public function testCronValidationMatchesTheWorker(string $input, bool $valid): void {
    $this->assertSame($valid, $this->manager->isValidCronExpression($input), $input);
  }

  /**
   * Expressions the worker accepts, and ones it refuses.
   */
  public static function provideCronValidity(): array {
    return [
      // Accepted.
      'every minute' => ['* * * * *', TRUE],
      'at two' => ['0 2 * * *', TRUE],
      'step' => ['*/5 * * * *', TRUE],
      'list' => ['15,45 * * * *', TRUE],
      'range' => ['0 9-17 * * *', TRUE],
      'range with step' => ['0 0-23/2 * * *', TRUE],
      'sunday as seven' => ['0 0 * * 7', TRUE],
      'shortcut' => ['@daily', TRUE],
      'english' => ['every 5 minutes', TRUE],
      'english hours' => ['every 3 hours', TRUE],

      // Refused, and the reason each one matters.
      'too few fields' => ['* * * *', FALSE],
      'too many fields' => ['* * * * * *', FALSE],
      'minute out of range' => ['60 * * * *', FALSE],
      'hour out of range' => ['* 24 * * *', FALSE],
      'day zero' => ['* * 0 * *', FALSE],
      'month thirteen' => ['* * * 13 *', FALSE],
      'weekday eight' => ['* * * * 8', FALSE],
      'zero step' => ['*/0 * * * *', FALSE],
      'backwards range' => ['5-1 * * * *', FALSE],
      'words' => ['every fortnight', FALSE],
      'nonsense' => ['not a cron', FALSE],
      'empty' => ['', FALSE],
    ];
  }

  /**
   * Expressions and shorthands, with what they should resolve to.
   */
  public static function provideCronExpressions(): array {
    return [
      '@yearly'          => ['@yearly', '0 0 1 1 *'],
      '@annually'        => ['@annually', '0 0 1 1 *'],
      '@monthly'         => ['@monthly', '0 0 1 * *'],
      '@weekly'          => ['@weekly', '0 0 * * 0'],
      '@daily'           => ['@daily', '0 0 * * *'],
      '@midnight'        => ['@midnight', '0 0 * * *'],
      '@hourly'          => ['@hourly', '0 * * * *'],
      'every minute'     => ['every minute', '* * * * *'],
      'every 5 minutes'  => ['every 5 minutes', '*/5 * * * *'],
      'every 1 minute'   => ['every 1 minute', '*/1 * * * *'],
      'every hour'       => ['every hour', '0 * * * *'],
      'every 2 hours'    => ['every 2 hours', '0 */2 * * *'],
      'every 1 hour'     => ['every 1 hour', '0 */1 * * *'],
      'every day'        => ['every day', '0 0 * * *'],
      'daily'            => ['daily', '0 0 * * *'],
      'every week'       => ['every week', '0 0 * * 0'],
      'weekly'           => ['weekly', '0 0 * * 0'],
      'every month'      => ['every month', '0 0 1 * *'],
      'monthly'          => ['monthly', '0 0 1 * *'],
      'uppercase @DAILY' => ['@DAILY', '0 0 * * *'],
      '5-part standard'  => ['0 2 * * *', '0 2 * * *'],
      '5-part complex'   => ['*/15 0-5 * * 1-5', '*/15 0-5 * * 1-5'],
      'unknown passthru' => ['not-a-cron', 'not-a-cron'],
      'whitespace trim'  => ['  @daily  ', '0 0 * * *'],
    ];
  }

  /**
   * The payload has the three keys the worker reads.
   */
  public function testGetJobsForServerReturnsExpectedShape(): void {
    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: 'web-01',
      serverRefresh: 300,
      jobs: [],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $this->eventDispatcher->method('dispatch')
      ->willReturnArgument(0);

    $result = $this->manager->getJobsForServer('web-01');

    $this->assertSame('web-01', $result['server']);
    $this->assertSame(300, $result['refresh']);
    $this->assertSame([], $result['jobs']);
  }

  /**
   * An unknown hostname still gets a schedule, on the default refresh.
   */
  public function testGetJobsForServerUsesDefaultRefreshWhenNoServer(): void {
    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: NULL,
      serverRefresh: NULL,
      jobs: [],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $this->eventDispatcher->method('dispatch')
      ->willReturnArgument(0);

    $result = $this->manager->getJobsForServer('unknown-host');

    $this->assertSame(CollectJobsEvent::DEFAULT_REFRESH, $result['refresh']);
  }

  /**
   * A recurring job carries its resolved expression.
   */
  public function testGetJobsForServerFormatsCronJob(): void {
    $job = $this->makeCronJob(
      id: 1,
      label: 'Send emails',
      command: 'advancedqueue:queue:process mail',
      timingCron: '@daily',
      timingOnce: NULL,
      async: TRUE,
      dependsOn: NULL,
    );

    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: 'web-01',
      serverRefresh: 600,
      jobs: [$job],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $this->eventDispatcher->method('dispatch')
      ->willReturnArgument(0);

    $result = $this->manager->getJobsForServer('web-01');

    $this->assertCount(1, $result['jobs']);
    $formatted = $result['jobs'][0];
    $this->assertSame(1, $formatted['id']);
    $this->assertSame('Send emails', $formatted['name']);
    $this->assertSame('advancedqueue:queue:process mail', $formatted['command']);
    $this->assertSame('cron', $formatted['type']);
    $this->assertSame('0 0 * * *', $formatted['cron']);
    $this->assertTrue($formatted['async']);
    $this->assertNull($formatted['depends_on']);
    $this->assertArrayNotHasKey('run_at', $formatted);
  }

  /**
   * A one-time job carries its moment instead.
   */
  public function testGetJobsForServerFormatsOnceJob(): void {
    $runAt = 1747392000;
    $job = $this->makeCronJob(
      id: 2,
      label: 'One-time import',
      command: 'migrate:import example_articles',
      timingCron: '',
      timingOnce: $runAt,
      async: FALSE,
      dependsOn: 1,
    );

    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: 'web-01',
      serverRefresh: 600,
      jobs: [$job],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $this->eventDispatcher->method('dispatch')
      ->willReturnArgument(0);

    $result = $this->manager->getJobsForServer('web-01');

    $formatted = $result['jobs'][0];
    $this->assertSame('once', $formatted['type']);
    $this->assertSame($runAt, $formatted['run_at']);
    $this->assertFalse($formatted['async']);
    $this->assertSame(1, $formatted['depends_on']);
    $this->assertArrayNotHasKey('cron', $formatted);
  }

  /**
   * An interval job carries its period and offset instead.
   */
  public function testGetJobsForServerFormatsIntervalJob(): void {
    $job = $this->makeCronJob(
      id: 3,
      label: 'Process the bid queue',
      command: 'advancedqueue:queue:process bid_queue',
      timingCron: '',
      timingOnce: NULL,
      async: TRUE,
      dependsOn: NULL,
      timingEvery: 25,
      timingOffset: 4,
    );

    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: 'web-01',
      serverRefresh: 600,
      jobs: [$job],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $this->eventDispatcher->method('dispatch')
      ->willReturnArgument(0);

    $formatted = $this->manager->getJobsForServer('web-01')['jobs'][0];

    $this->assertSame('interval', $formatted['type']);
    $this->assertSame(25, $formatted['every']);
    $this->assertSame(4, $formatted['offset']);
    $this->assertArrayNotHasKey('cron', $formatted);
    $this->assertArrayNotHasKey('run_at', $formatted);
  }

  /**
   * Other modules get their chance to add jobs.
   */
  public function testGetJobsForServerDispatchesEvent(): void {
    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: 'web-01',
      serverRefresh: 600,
      jobs: [],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $capturedEvent = NULL;
    $this->eventDispatcher->method('dispatch')
      ->willReturnCallback(function (CollectJobsEvent $event) use (&$capturedEvent): CollectJobsEvent {
        $capturedEvent = $event;
        return $event;
      });

    $this->manager->getJobsForServer('web-01');

    $this->assertInstanceOf(CollectJobsEvent::class, $capturedEvent);
    $this->assertSame('web-01', $capturedEvent->getHostname());
  }

  /**
   * And what they add comes back in the payload.
   */
  public function testGetJobsForServerIncludesEventSubscriberJobs(): void {
    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: 'web-01',
      serverRefresh: 600,
      jobs: [],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $extraJob = ['id' => 'dynamic-1', 'command' => 'foo:bar', 'type' => 'cron'];
    $this->eventDispatcher->method('dispatch')
      ->willReturnCallback(function (CollectJobsEvent $event) use ($extraJob): CollectJobsEvent {
        $event->addJob($extraJob);
        return $event;
      });

    $result = $this->manager->getJobsForServer('web-01');

    $this->assertCount(1, $result['jobs']);
    $this->assertSame($extraJob, $result['jobs'][0]);
  }

  /**
   * Builds storage mocks for both druker_job and druker_server.
   *
   * @param string|null $serverHostname
   *   NULL means no server found.
   * @param int|null $serverRefresh
   *   NULL when no server found.
   * @param array $jobs
   *   CronJobInterface mocks.
   *
   * @return array{ContentEntityStorageInterface, EntityStorageInterface}
   *   The job storage and the server storage.
   */
  private function buildStorageMocks(
    ?string $serverHostname,
    ?int $serverRefresh,
    array $jobs,
  ): array {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('orConditionGroup')->willReturnSelf();
    $query->method('notExists')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $ids = [];
    foreach ($jobs as $j) {
      $ids[] = $j->id();
    }
    $query->method('execute')->willReturn($ids);

    $jobStorage = $this->createMock(ContentEntityStorageInterface::class);
    $jobStorage->method('getQuery')->willReturn($query);
    $jobStorage->method('loadMultiple')->willReturn($jobs);

    $serverStorage = $this->createMock(EntityStorageInterface::class);

    if ($serverHostname !== NULL) {
      $server = $this->createMock(ServerInterface::class);
      $server->method('id')->willReturn(1);
      $server->method('getDefaultRefresh')->willReturn($serverRefresh);
      $serverStorage->method('loadByProperties')->willReturn([$server]);
    }
    else {
      $serverStorage->method('loadByProperties')->willReturn([]);
    }

    return [$jobStorage, $serverStorage];
  }

  /**
   * A mocked job, so the manager has something to format.
   */
  private function makeCronJob(
    int $id,
    string $label,
    string $command,
    string $timingCron,
    ?int $timingOnce,
    bool $async,
    ?int $dependsOn,
    string $runner = CronJobInterface::RUNNER_DRUSH,
    ?int $timingEvery = NULL,
    int $timingOffset = 0,
  ): CronJobInterface {
    $job = $this->createMock(CronJobInterface::class);
    $job->method('id')->willReturn($id);
    $job->method('label')->willReturn($label);
    $job->method('getCommand')->willReturn($command);
    $job->method('getTimingCron')->willReturn($timingCron);
    $job->method('getTimingOnce')->willReturn($timingOnce);
    $job->method('getTimingEvery')->willReturn($timingEvery);
    $job->method('getTimingOffset')->willReturn($timingOffset);
    $job->method('isAsync')->willReturn($async);
    $job->method('getDependsOnId')->willReturn($dependsOn);
    $job->method('getRunner')->willReturn($runner);

    // Derived the way CronJob derives it, so the double cannot claim to be a
    // kind of job its own fields disagree with.
    $job->method('getTimingType')->willReturn(match (TRUE) {
      $timingOnce !== NULL => CronJobInterface::TIMING_ONCE,
      $timingEvery !== NULL => CronJobInterface::TIMING_INTERVAL,
      default => CronJobInterface::TIMING_CRON,
    });

    return $job;
  }

  /**
   * The hours a schedule fires in, which is what the dashboard draws.
   */
  #[DataProvider('provideScheduleHours')]
  public function testScheduleHours(string $expression, array $expected): void {
    $this->assertSame($expected, $this->manager->scheduleHours($expression), $expression);
  }

  /**
   * Expressions and the hours of the day they land in.
   */
  public static function provideScheduleHours(): array {
    return [
      'every minute' => ['* * * * *', range(0, 23)],
      'on the hour' => ['0 * * * *', range(0, 23)],
      'one time of day' => ['0 3 * * *', [3]],
      'two times' => ['0 3,15 * * *', [3, 15]],
      'every six hours' => ['0 */6 * * *', [0, 6, 12, 18]],
      'business hours' => ['0 9-17 * * *', range(9, 17)],
      'shorthand' => ['@daily', [0]],
      'english' => ['every 5 minutes', range(0, 23)],

      // Unreadable anywhere means unreadable everywhere: the hour field of
      // "99 * * * *" is fine on its own, and the job still never runs.
      'bad minute' => ['99 * * * *', []],
      'bad shape' => ['not a cron', []],
      'empty' => ['', []],
    ];
  }

  /**
   * A hostname nobody configured gets only the jobs assigned to no server.
   *
   * Not every job on the site, which is what it used to get. A typo in a
   * hostname, or a machine coming up before anyone adds its Server record,
   * must not turn it into a box that runs another server's nightly backup.
   */
  public function testAnUnknownHostGetsOnlyUnassignedJobs(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('orConditionGroup')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    // The whole point: the query is narrowed to jobs with no server.
    $query->expects($this->once())
      ->method('notExists')
      ->with('server')
      ->willReturnSelf();

    $jobStorage = $this->createMock(ContentEntityStorageInterface::class);
    $jobStorage->method('getQuery')->willReturn($query);
    $jobStorage->method('loadMultiple')->willReturn([]);

    $serverStorage = $this->createMock(EntityStorageInterface::class);
    $serverStorage->method('loadByProperties')->willReturn([]);

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $payload = $this->manager->getJobsForServer('a-machine-nobody-configured');

    $this->assertSame('a-machine-nobody-configured', $payload['server']);
    $this->assertSame(CollectJobsEvent::DEFAULT_REFRESH, $payload['refresh'], 'And the default refresh, since no server said otherwise.');
  }

  /**
   * What a subscriber sets for the refresh is what the worker is told.
   */
  public function testSubscriberCanChangeTheRefresh(): void {
    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: 'web-01',
      serverRefresh: 600,
      jobs: [],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $this->eventDispatcher->method('dispatch')
      ->willReturnCallback(static function (CollectJobsEvent $event): CollectJobsEvent {
        // What a subscriber does: the server said ten minutes, something
        // knows better right now.
        $event->setRefresh(60);
        return $event;
      });

    $this->assertSame(60, $this->manager->getJobsForServer('web-01')['refresh']);
  }

  /**
   * The server's own setting is what a subscriber starts from.
   */
  public function testTheSubscriberSeesTheServersRefresh(): void {
    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: 'web-01',
      serverRefresh: 300,
      jobs: [],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $seen = NULL;

    $this->eventDispatcher->method('dispatch')
      ->willReturnCallback(static function (CollectJobsEvent $event) use (&$seen): CollectJobsEvent {
        $seen = $event->getRefresh();
        return $event;
      });

    $this->manager->getJobsForServer('web-01');

    $this->assertSame(300, $seen);
  }

  /**
   * A shell job never reaches the worker unless the site opted in.
   *
   * The gate is on this side because the setting lives in settings.php, which
   * only Drupal can read — and because a worker that decided for itself would
   * be taking the word of a payload for what it is allowed to execute.
   */
  public function testShellJobsAreWithheldUnlessTheSiteOptsIn(): void {
    $drush = $this->makeCronJob(
      id: 1,
      label: 'A Drush job',
      command: 'core:status',
      timingCron: '@daily',
      timingOnce: NULL,
      async: TRUE,
      dependsOn: NULL,
    );

    $shell = $this->makeCronJob(
      id: 2,
      label: 'A shell job',
      command: 'rsync -a /src /dest',
      timingCron: '@daily',
      timingOnce: NULL,
      async: TRUE,
      dependsOn: NULL,
      runner: CronJobInterface::RUNNER_SHELL,
    );

    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: 'web-01',
      serverRefresh: 600,
      jobs: [$drush, $shell],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $withheld = $this->managerWith([])->getJobsForServer('web-01');
    $this->assertSame(['A Drush job'], array_column($withheld['jobs'], 'name'));
    $this->assertFalse($this->managerWith([])->shellAllowed());

    $allowed = $this->managerWith([JobManager::ALLOW_SHELL => TRUE])->getJobsForServer('web-01');
    $this->assertSame(['A Drush job', 'A shell job'], array_column($allowed['jobs'], 'name'));
    $this->assertTrue($this->managerWith([JobManager::ALLOW_SHELL => TRUE])->shellAllowed());
  }

  /**
   * The runner travels with the job, so the worker knows how to run it.
   */
  public function testTheRunnerIsInThePayload(): void {
    $job = $this->makeCronJob(
      id: 1,
      label: 'Backup',
      command: 'tar -czf /backups/site.tgz .',
      timingCron: '@daily',
      timingOnce: NULL,
      async: TRUE,
      dependsOn: NULL,
      runner: CronJobInterface::RUNNER_SHELL,
    );

    [$jobStorage, $serverStorage] = $this->buildStorageMocks(
      serverHostname: 'web-01',
      serverRefresh: 600,
      jobs: [$job],
    );

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['druker_job', $jobStorage],
        ['druker_server', $serverStorage],
      ]);

    $payload = $this->managerWith([JobManager::ALLOW_SHELL => TRUE])->getJobsForServer('web-01');

    $this->assertSame('shell', $payload['jobs'][0]['runner']);
  }

}
