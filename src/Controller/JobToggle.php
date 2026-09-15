<?php

declare(strict_types=1);

namespace Drupal\druker\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\druker\Entity\CronJobInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Switches one job on or off from the list.
 *
 * Disabling is the ordinary way to stop a job without losing what it was:
 * the command, the schedule and the server assignment all stay put, and
 * nothing is told about the job until it is switched back on. Having to open
 * the form and find a checkbox to do that made it look like a bigger decision
 * than it is.
 *
 * Only jobs get this. A server's enabled flag is config, and on a site that
 * overrides it per environment — the usual reason to have the flag at all —
 * saving the entity would write the override into the stored config and
 * quietly change what every other environment does. That one stays a
 * deliberate trip to the form.
 */
class JobToggle extends ControllerBase {

  /**
   * Flips the job's enabled flag and returns to the list.
   */
  public function toggle(CronJobInterface $druker_job): RedirectResponse {
    $enabled = !$druker_job->get('status')->value;
    $druker_job->set('status', $enabled)->save();

    $this->messenger()->addStatus($enabled
      ? $this->t('%job is enabled. Workers pick it up when they next ask for their schedule.', ['%job' => $druker_job->label()])
      : $this->t('%job is disabled. No worker is told about it until it is enabled again.', ['%job' => $druker_job->label()]));

    return $this->redirect('entity.druker_job.collection');
  }

}
