<?php

namespace Drupal\Tests\staff_data\Functional;

use Drupal\Tests\unity_profile\Functional\UnityBrowserTestBase;

/**
 * Tests the staff config settings.
 */
class StaffDataTest extends UnityBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static array $optInModules = [
    'people',
    'staff_data',
  ];

  /**
   * The admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service('module_installer')->uninstall(['simplesamlphp_auth']);

    // Login as an administrator.
    $this->adminUser = $this->drupalCreateUser([
      'administer blocks',
      'administer site configuration',
      'view the administration theme',
    ]);

    $this->drupalLogin($this->adminUser);
  }

  /**
   * Test the staff data settings.
   */
  public function testStaffDataApiSettings() {
    // Check if the org field appears on the config page.
    $this->drupalGet('admin/config/people/staff_data_settings');
    $this->assertSession()->fieldExists('staff_data_org');
  }

}
