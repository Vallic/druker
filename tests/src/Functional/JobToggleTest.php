<?php

declare(strict_types=1);

namespace Drupal\Tests\druker\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\druker\Entity\CronJob;
use Drupal\druker\Entity\CronJobInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers switching a job on and off from the list.
 */
#[Group('druker')]
class JobToggleTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['druker'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The job under test.
   */
  protected CronJobInterface $job;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->job = CronJob::create([
      'label' => 'Mail queue',
      'command' => 'advancedqueue:queue:process mail',
      'timing_cron' => '0 3 * * *',
      'runner' => CronJobInterface::RUNNER_DRUSH,
      'status' => TRUE,
      'async' => TRUE,
    ]);
    $this->job->save();
  }

  /**
   * Reloads the job from storage.
   */
  protected function reloaded(): CronJobInterface {
    return \Drupal::entityTypeManager()
      ->getStorage('druker_job')
      ->loadUnchanged($this->job->id());
  }

  /**
   * The list offers to switch a job off, and switching it off works.
   */
  public function testJobCanBeDisabledFromTheList(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer druker']));
    $this->drupalGet('admin/config/system/druker/jobs');

    $this->assertSession()->linkExists('Disable');
    $this->clickLink('Disable');

    $this->assertSession()->addressEquals('admin/config/system/druker/jobs');
    $this->assertSession()->pageTextContains('No worker is told about it until it is enabled again.');
    $this->assertFalse((bool) $this->reloaded()->get('status')->value);

    // The offer flips round, and the job is gone from every payload.
    $this->assertSession()->linkExists('Enable');
    $this->assertSame([], \Drupal::service('druker.job_manager')->getJobsForServer('any-box')['jobs']);
  }

  /**
   * And back on again, which is the point of disabling rather than deleting.
   */
  public function testDisabledJobCanBeEnabledAgain(): void {
    $this->job->set('status', FALSE)->save();

    $this->drupalLogin($this->drupalCreateUser(['administer druker']));
    $this->drupalGet('admin/config/system/druker/jobs');
    $this->clickLink('Enable');

    $this->assertTrue((bool) $this->reloaded()->get('status')->value);
    $this->assertCount(1, \Drupal::service('druker.job_manager')->getJobsForServer('any-box')['jobs']);

    // Nothing else about the job was disturbed by the round trip.
    $this->assertSame('0 3 * * *', $this->reloaded()->getTimingCron());
  }

  /**
   * The link changes state, so it must carry a token and refuse without one.
   */
  public function testTogglingNeedsValidToken(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer druker']));
    $this->drupalGet('admin/config/system/druker/jobs/' . $this->job->id() . '/toggle');

    $this->assertSession()->statusCodeEquals(403);
    $this->assertTrue((bool) $this->reloaded()->get('status')->value, 'The job was left alone.');
  }

  /**
   * Without the permission there is no toggle at all.
   */
  public function testTogglingNeedsThePermission(): void {
    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet('admin/config/system/druker/jobs/' . $this->job->id() . '/toggle');

    $this->assertSession()->statusCodeEquals(403);
    $this->assertTrue((bool) $this->reloaded()->get('status')->value);
  }

}
