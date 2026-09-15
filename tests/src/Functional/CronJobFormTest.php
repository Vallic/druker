<?php

declare(strict_types=1);

namespace Drupal\Tests\druker\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\druker\Entity\CronJobInterface;
use Drupal\druker\Event\CollectJobsEvent;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the job form, where a person says when a job runs.
 *
 * A job is one of three kinds and the form asks which before it asks
 * anything else, so most of what is worth testing is that the answer is
 * believed: the fields of the other two kinds are ignored however they were
 * filled in, and the one that was chosen is checked properly.
 */
#[Group('druker')]
class CronJobFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['druker'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalLogin($this->drupalCreateUser(['administer druker']));
  }

  /**
   * Submits the add form with the values given on top of the defaults.
   */
  protected function submitJob(array $values): void {
    $this->drupalGet('admin/config/system/druker/jobs/add');
    $this->submitForm($values + [
      'label[0][value]' => 'Test job',
      'command[0][value]' => 'advancedqueue:queue:process mail',
    ], 'Save');
  }

  /**
   * The only job that was saved.
   */
  protected function savedJob(): ?CronJobInterface {
    $jobs = \Drupal::entityTypeManager()->getStorage('druker_job')->loadMultiple();
    $job = reset($jobs);

    return $job instanceof CronJobInterface ? $job : NULL;
  }

  /**
   * Opens the list, which is where a saved schedule is shown back.
   *
   * Saving lands on the job's own page rather than the list: the entity has a
   * canonical route, so that is where ContentEntityForm sends you.
   */
  protected function openList(): void {
    $this->drupalGet('admin/config/system/druker/jobs');
  }

  /**
   * The form asks which kind of job this is, and offers all three.
   */
  public function testTheFormAsksWhichKind(): void {
    $this->drupalGet('admin/config/system/druker/jobs/add');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('timing_type');
    $this->assertSession()->pageTextContains('When it runs');

    foreach ([CronJobInterface::TIMING_CRON, CronJobInterface::TIMING_INTERVAL, CronJobInterface::TIMING_ONCE] as $type) {
      $this->assertSession()->elementExists('css', 'input[name="timing_type"][value="' . $type . '"]');
    }

    // A new job opens on the kind most jobs are.
    $this->assertSession()->checkboxChecked('edit-timing-type-cron');
  }

  /**
   * Each kind's fields are wired to the choice, so only one set is shown.
   */
  public function testTheFieldsFollowTheChoice(): void {
    $this->drupalGet('admin/config/system/druker/jobs/add');

    foreach (['timing-cron', 'timing-every', 'timing-offset', 'timing-once'] as $field) {
      $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-' . $field . '-wrapper"][data-drupal-states]');
    }
  }

  /**
   * An interval job saves its period and is shown back with it.
   */
  public function testIntervalJobIsSavedAndListed(): void {
    $this->submitJob([
      'timing_type' => CronJobInterface::TIMING_INTERVAL,
      'timing_every[0][value]' => '20',
      'timing_offset[0][value]' => '7',
    ]);

    $job = $this->savedJob();
    $this->assertSame(CronJobInterface::TIMING_INTERVAL, $job->getTimingType());
    $this->assertSame(20, $job->getTimingEvery());
    $this->assertSame(7, $job->getTimingOffset());

    $this->openList();
    $this->assertSession()->pageTextContains('Every 20 seconds, offset 7');
  }

  /**
   * A period on its own needs no offset, and is listed without one.
   */
  public function testPeriodWithoutAnOffsetIsListedPlainly(): void {
    $this->submitJob([
      'timing_type' => CronJobInterface::TIMING_INTERVAL,
      'timing_every[0][value]' => '30',
    ]);

    $this->openList();
    $this->assertSession()->pageTextContains('Every 30 seconds');
    $this->assertSession()->pageTextNotContains('offset');
  }

  /**
   * A recurring job stays recurring.
   *
   * Core's timestamp widget fills an empty run-at date with the request time,
   * which used to leave every job saved here a one-time job that had already
   * had its turn. Nothing in the UI said so: the form took the cron
   * expression and the list then showed the job as running once.
   */
  public function testRecurringJobStaysRecurring(): void {
    $this->submitJob([
      'timing_type' => CronJobInterface::TIMING_CRON,
      'timing_cron[0][value]' => '0 2 * * *',
    ]);

    $job = $this->savedJob();
    $this->assertSame(CronJobInterface::TIMING_CRON, $job->getTimingType());
    $this->assertSame('0 2 * * *', $job->getTimingCron());
    $this->assertNull($job->getTimingOnce(), 'An empty run-at date stays empty.');

    $this->openList();
    $this->assertSession()->pageTextNotContains('Once,');
  }

  /**
   * An interval job is not clobbered by the same widget.
   */
  public function testIntervalJobStaysAnInterval(): void {
    $this->submitJob([
      'timing_type' => CronJobInterface::TIMING_INTERVAL,
      'timing_every[0][value]' => '30',
    ]);

    $this->assertNull($this->savedJob()->getTimingOnce());
  }

  /**
   * A date that was actually entered still saves as a one-time job.
   */
  public function testOneTimeJobSavesItsDate(): void {
    $this->submitJob([
      'timing_type' => CronJobInterface::TIMING_ONCE,
      'timing_once[0][value][date]' => '2027-03-01',
      'timing_once[0][value][time]' => '02:30:00',
    ]);

    $job = $this->savedJob();
    $this->assertSame(CronJobInterface::TIMING_ONCE, $job->getTimingType());
    $this->assertNotNull($job->getTimingOnce());

    $this->openList();
    $this->assertSession()->pageTextContains('Once, 2027-03-01');
  }

  /**
   * The kinds that were not chosen are ignored, however they were filled in.
   *
   * #states hides those fields in the browser; it does not stop them posting.
   */
  public function testTheKindsNotChosenAreIgnored(): void {
    $this->submitJob([
      'timing_type' => CronJobInterface::TIMING_INTERVAL,
      'timing_every[0][value]' => '30',
      'timing_cron[0][value]' => '0 2 * * *',
      'timing_once[0][value][date]' => '2027-03-01',
      'timing_once[0][value][time]' => '02:30:00',
    ]);

    $job = $this->savedJob();

    $this->assertSame(CronJobInterface::TIMING_INTERVAL, $job->getTimingType());
    $this->assertSame('', $job->getTimingCron());
    $this->assertNull($job->getTimingOnce());
  }

  /**
   * Switching an existing job to another kind leaves nothing behind.
   */
  public function testSwitchingKindClearsTheOldSchedule(): void {
    $this->submitJob([
      'timing_type' => CronJobInterface::TIMING_CRON,
      'timing_cron[0][value]' => '0 2 * * *',
    ]);

    $job = $this->savedJob();
    $this->drupalGet('admin/config/system/druker/jobs/' . $job->id() . '/edit');

    // The form opens on the kind the job already is.
    $this->assertSession()->checkboxChecked('edit-timing-type-cron');

    $this->submitForm([
      'timing_type' => CronJobInterface::TIMING_INTERVAL,
      'timing_every[0][value]' => '45',
    ], 'Save');

    $job = $this->savedJob();
    $this->assertSame(CronJobInterface::TIMING_INTERVAL, $job->getTimingType());
    $this->assertSame(45, $job->getTimingEvery());
    $this->assertSame('', $job->getTimingCron());
  }

  /**
   * Each kind says what it is missing, rather than one message for all three.
   */
  public function testEachKindAsksForItsOwnField(): void {
    $this->submitJob(['timing_type' => CronJobInterface::TIMING_CRON]);
    $this->assertSession()->pageTextContains('A recurring job needs a cron expression.');

    $this->submitJob(['timing_type' => CronJobInterface::TIMING_INTERVAL]);
    $this->assertSession()->pageTextContains('An interval job needs a period in seconds.');

    $this->submitJob(['timing_type' => CronJobInterface::TIMING_ONCE]);
    $this->assertSession()->pageTextContains('A one-time job needs a date and time to run at.');
  }

  /**
   * An unreadable cron expression is refused here.
   *
   * Rather than dropped in silence by the worker, which says so only in a log.
   */
  public function testAnUnreadableCronExpressionIsRefused(): void {
    $this->submitJob([
      'timing_type' => CronJobInterface::TIMING_CRON,
      'timing_cron[0][value]' => '99 * * * *',
    ]);

    $this->assertSession()->pageTextContains('is not a valid cron expression');
  }

  /**
   * A period under the floor is refused for the same reason.
   */
  public function testPeriodUnderTheFloorIsRefused(): void {
    $this->submitJob([
      'timing_type' => CronJobInterface::TIMING_INTERVAL,
      'timing_every[0][value]' => (string) (CollectJobsEvent::MINIMUM_INTERVAL - 1),
    ]);

    $this->assertSession()->pageTextContains('must be at least ' . CollectJobsEvent::MINIMUM_INTERVAL . ' seconds');
  }

}
