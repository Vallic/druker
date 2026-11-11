<?php

declare(strict_types=1);

namespace Drupal\Tests\druker\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\druker\Entity\CronJob;
use Drupal\druker\Entity\CronJobInterface;
use Drupal\druker\JobManager;
use Drupal\druker\Wake;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Asking the worker to run a job now.
 *
 * The worker's half — listing the directory and claiming a signal — is
 * covered by worker/wake_test.go; this is the site's half, and the payload
 * that tells the worker where to look.
 */
#[Group('druker')]
class WakeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['druker', 'system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    // The default directory is the private file system's, when there is one.
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('druker_job');
    $this->installEntitySchema('user');
  }

  /**
   * A signal is a file named after the job, in the private file system.
   */
  public function testEachSignalIsAnEmptyFileNamedAfterTheJob(): void {
    $wake = $this->container->get('druker.wake');
    $private = rtrim((string) $this->container->get('file_system')->realpath('private://'), '/');
    $this->assertSame($private . '/druker/wake', $wake->directory());

    $job = $this->job();
    $this->assertTrue($wake->wake($job));
    $this->assertFileExists($wake->directory() . '/' . $job->id());

    // A second signal before the worker gets to it is the same file.
    $this->assertTrue($wake->wake((int) $job->id()));
    $this->assertSame([(string) $job->id()], array_values(array_diff(scandir($wake->directory()), ['.', '..'])));

    $this->assertFalse($wake->wake(0), 'No job has id 0.');
  }

  /**
   * The schedule tells the worker where signals are.
   */
  public function testTheScheduleNamesTheDirectory(): void {
    $this->job();
    $payload = $this->container->get('druker.job_manager')->getJobsForServer('web-01');
    $this->assertSame($this->container->get('druker.wake')->directory(), $payload['wake_dir']);
    $this->assertCount(1, $payload['jobs']);
  }

  /**
   * The setting moves the directory, or switches waking off.
   */
  public function testTheSettingMovesOrSwitchesItOff(): void {
    $custom = $this->siteDirectory . '/wake-here';
    $wake = $this->wake([Wake::SETTING => $custom . '/']);
    $this->assertSame($custom, $wake->directory());
    $this->assertTrue($wake->wake(5));
    $this->assertFileExists($custom . '/5');

    $off = $this->wake([Wake::SETTING => FALSE]);
    $this->assertSame('', $off->directory());
    $this->assertFalse($off->wake(5));
    $manager = new JobManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('event_dispatcher'),
      Settings::getInstance(),
      $off,
    );
    $this->assertSame('', $manager->getJobsForServer('web-01')['wake_dir'], 'The worker is told waking is off.');
  }

  /**
   * Without a private file system, a directory of the site's own.
   */
  public function testWithoutPrivateFilesTheSiteGetsItsOwnTemporaryDirectory(): void {
    $manager = $this->createMock('Drupal\Core\StreamWrapper\StreamWrapperManagerInterface');
    $manager->method('isValidScheme')->willReturnCallback(static fn (string $scheme): bool => $scheme !== 'private');
    $wake = new Wake($this->container->get('file_system'), $manager, new Settings(['hash_salt' => 'one'] + Settings::getAll()), new NullLogger());
    $temporary = rtrim((string) $this->container->get('file_system')->realpath('temporary://'), '/');

    $this->assertStringStartsWith($temporary . '/druker-wake-', $wake->directory());
  }

  /**
   * A directory that cannot be written fails quietly: the job keeps its turn.
   */
  public function testAnUnwritableDirectoryFailsQuietly(): void {
    $blocked = $this->siteDirectory . '/blocked';
    file_put_contents($blocked, 'a file, not a directory');
    $this->assertFalse($this->wake([Wake::SETTING => $blocked . '/wake'])->wake(5));
  }

  /**
   * A wake service with its own settings.
   *
   * @param array<string, mixed> $settings
   *   The settings.
   */
  protected function wake(array $settings): Wake {
    return new Wake(
      $this->container->get('file_system'),
      $this->container->get('stream_wrapper_manager'),
      // On top of the site's: a Settings object is also the global one.
      new Settings($settings + Settings::getAll()),
      new NullLogger(),
    );
  }

  /**
   * A job, saved.
   */
  protected function job(): CronJobInterface {
    $job = CronJob::create([
      'label' => 'Process the mail queue',
      'command' => 'advancedqueue:queue:process mail',
      'runner' => CronJobInterface::RUNNER_DRUSH,
      'status' => TRUE,
      'timing_every' => 3600,
    ]);
    $job->save();
    return $job;
  }

}
