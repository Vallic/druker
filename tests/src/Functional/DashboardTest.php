<?php

declare(strict_types=1);

namespace Drupal\Tests\druker\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\druker\Entity\CronJob;
use Drupal\druker\Entity\CronJobInterface;
use Drupal\druker\Entity\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers what the dashboard says about interval jobs.
 *
 * The day grid exists to make a pile-up visible, and an interval job fills
 * every hour of it. So does an hourly cron job — three orders of magnitude
 * apart in how often they run, and the grid has to tell them apart or it is
 * answering the question wrongly.
 */
#[Group('druker')]
class DashboardTest extends BrowserTestBase {

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

    Server::create([
      'id' => 'box_a',
      'label' => 'Box A',
      'hostname' => 'box-a',
      'status' => TRUE,
    ])->save();

    foreach ([
      [
        'label' => 'Bid queue',
        'command' => 'advancedqueue:queue:process bid_queue',
        'timing_every' => 25,
        'timing_offset' => 4,
      ],
      ['label' => 'Nightly cron', 'command' => 'cron', 'timing_cron' => '0 2 * * *'],
      ['label' => 'Hourly index', 'command' => 'search-api:index', 'timing_cron' => '0 * * * *'],
      ['label' => 'One-off migration', 'command' => 'migrate:import x', 'timing_once' => 1790000000],
    ] as $values) {
      CronJob::create($values + [
        'status' => TRUE,
        'runner' => CronJobInterface::RUNNER_DRUSH,
        'async' => TRUE,
      ])->save();
    }

    $this->drupalLogin($this->drupalCreateUser(['administer druker']));
    $this->drupalGet('admin/config/system/druker');
  }

  /**
   * Interval jobs are counted in their own right.
   */
  public function testIntervalJobsAreCounted(): void {
    $this->assertSession()->pageTextContains('Interval jobs');
    $this->assertSession()->elementTextContains('css', '.druker-summary', 'Interval jobs');
  }

  /**
   * An interval row carries its period, so it cannot be read as hourly.
   */
  public function testTheGridShowsThePeriod(): void {
    $this->assertSession()->elementTextContains('css', '.druker-day', 'every 25 s');
  }

  /**
   * An interval row is drawn differently from one that runs in every hour.
   */
  public function testIntervalRowsAreDrawnAsUnbroken(): void {
    $this->assertSession()->elementExists('css', '.druker-day__cell.is-on.is-interval');

    // The hourly job fills the day too, and must not be styled as one.
    $busy = $this->getSession()->getPage()->findAll('css', '.druker-day__cell.is-on.is-busy:not(.is-interval)');
    $this->assertNotEmpty($busy, 'An hourly cron job still reads as a busy row, not an interval one.');
  }

  /**
   * A disabled server counts only the jobs nothing else is left to run.
   *
   * The card used to count every job naming the server, so a job assigned to
   * this one and to a live one was reported as abandoned on the card while
   * the warning directly above it said the opposite.
   */
  public function testTheCardCountsOnlyTrulyStrandedJobs(): void {
    Server::create([
      'id' => 'spare',
      'label' => 'Spare',
      'hostname' => 'spare-box',
      'status' => FALSE,
    ])->save();

    $defaults = [
      'status' => TRUE,
      'runner' => CronJobInterface::RUNNER_DRUSH,
      'async' => TRUE,
      'timing_cron' => '0 3 * * *',
    ];

    // Named only by the disabled server: nothing runs it.
    CronJob::create(['label' => 'Orphan', 'command' => 'cron', 'server' => ['spare']] + $defaults)->save();
    // Named by the disabled server and a live one: still runs.
    CronJob::create(['label' => 'Covered', 'command' => 'cron', 'server' => ['spare', 'box_a']] + $defaults)->save();

    $this->drupalGet('admin/config/system/druker');

    $this->assertSession()->elementTextContains('css', '.druker-card__stranded', 'one job it names is left with nobody to run it');
    $this->assertSession()->elementTextNotContains('css', '.druker-card__stranded', '2 jobs');

    // And the warnings say the same two things, which is the point.
    $this->assertSession()->pageTextContains('Orphan names only servers that are missing or disabled');
    $this->assertSession()->pageTextContains('Covered names a server that is missing or disabled. The others still run it.');
  }

  /**
   * A one-time job has a date rather than a shape, so it is left out.
   */
  public function testOneTimeJobsAreNotDrawn(): void {
    $this->assertSession()->elementTextNotContains('css', '.druker-day', 'One-off migration');
  }

  /**
   * The server card says the period and the offset.
   *
   * The offset is the whole reason the same job on two machines does not
   * collide, so a card without it makes the pair look identical.
   */
  public function testTheServerCardShowsPeriodAndOffset(): void {
    $this->assertSession()->elementTextContains('css', '.druker-servers', 'every 25 s +4');
  }

}
