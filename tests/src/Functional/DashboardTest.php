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
