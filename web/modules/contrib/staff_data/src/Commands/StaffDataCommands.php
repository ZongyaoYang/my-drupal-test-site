<?php

namespace Drupal\staff_data\Commands;

use Drupal\staff_data\StaffDataService;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile.
 */
class StaffDataCommands extends DrushCommands {
  /**
   * Commands to sync staff data from HR.
   *
   * @var \Drupal\staff_data\StaffDataService
   *   Staff Data service.
   */
  protected $service;

  /**
   * StaffDataService constructor.
   *
   * @param \Drupal\staff_data\StaffDataService $service
   *   The HR staff service.
   */
  public function __construct(StaffDataService $service) {
    $this->service = $service;
  }

  /**
   * Retrieve and sync staff data from Workday into Drupal person nodes.
   *
   * @command staff-data:sync
   *
   * @usage drush staff-data:sync
   *   Retrieve and sync staff data from Workday into Drupal person nodes.
   *
   * @validate-module-enabled staff_data
   *
   * @aliases sds, staff-data-sync
   */
  public function sync() {
    $this->service->retrieveAndSync();
  }

}
