<?php

namespace Drupal\staff_data\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * The settings configuration form for Staff Data.
 *
 * @internal
 */
class StaffDataSettingsForm extends ConfigFormBase {

  /**
   * Staff data settings.
   *
   * @var string
   */
  protected $settingsId = 'staff_data.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'staff_data_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $staff_data_org = $this->config($this->settingsId)->get('org');

    $staff_data = \Drupal::service('staff_data.manager');
    $endpoint = $staff_data::getEndpoint();
    if (!$endpoint) {
      $info = 'Please upload a credentials file to: ' . $staff_data::CREDENTIALS_FILE;
    }
    else {
      $info = '<li>A credentials file exists.</li>';
      foreach (['url', 'username', 'password'] as $item) {
        $info .= '<li>"' . $item . '" ' . (isset($endpoint->{$item}) && !empty($endpoint->{$item}) ? 'was' : 'was not') . ' found.</li>';
      }
    }

    $form['staff_data_help'] = [
      '#type' => 'details',
      '#title' => 'Documentation and Help',
    ];

    $form['staff_data_help']['staff_data_info'] = [
      '#type' => 'html_tag',
      '#tag' => 'ul',
      '#value' => "<li>This module syncs staff profiles from Workday to the Person content type.</li>
<li>The <b>sync is one-way</b> i.e. it syncs data <b>from Workday to Drupal</b>, and not Drupal to Workday.</li>
<li>The module will sync <b>all records from a given org</b> (configured below) and <b>any of that org's sub-orgs</b> (if any sub-orgs exist).</li>
<li>It uses <b>a credential file</b> uploaded to your site to access the Workday API (path is listed below).</li>
<li>If you <b>change the org acronym</b>, all staff from the previous org will be removed and staff from the new org will be created.</li>
<li>It <b>runs on cron</b> and will try to sync any changes from Workday to your Drupal site. If someone leaves the org, they will be deleted from your site, or if someone’s position changes, the position will change on your site, etc.</li>
<li>Once the module creates a person node, it will continue managing that node, <b>syncing the following fields</b>: first name, last name, email, phone, job title, organization, and photo. If you make edits to those fields in Drupal, they will get overwritten on the next sync. Any other fields will be ignored by the module.</li>
<li>If the person has a photo in Workday, it will get pulled in to Drupal, added to the Media Library, and used as their photo here.</li>
<li>If <b>you want the module to stop managing a person</b>, change the 'Authored on' datetime of the node, and that entry will not be synced going forward.</li>
<li>If you <b>create a person manually in Drupal</b>, the module will ignore that entry.</li>",
    ];

    $form['staff_data_check'] = [
      '#type' => 'details',
      '#title' => $this
        ->t('Workday API Credentials Check'),
    ];

    $form['staff_data_check']['staff_data_endpoint'] = [
      '#type' => 'html_tag',
      '#tag' => 'ul',
      '#value' => $info,
    ];

    $form['staff_data_org'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Top Level Org Acronym'),
      '#default_value' => strtoupper($staff_data_org),
      '#maxlength' => 10,
      '#pattern' => '[a-zA-Z]+',
      '#required' => TRUE,
      '#size' => 20,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = \Drupal::configFactory()->getEditable($this->settingsId);
    $saved_org = strtoupper($config->get('org'));
    $form_value = strtoupper($form_state->getValue('staff_data_org'));
    if ($saved_org !== $form_value) {
      $config->set('org', $form_value)->save();

      // Delete existing nodes...
      $staff_data = \Drupal::service('staff_data.manager');
      $staff_data->deletePersonsFromDb($staff_data->loadPersonsFromDb(TRUE));

      // ... and repopulate
      $staff_data->retrieveAndSync();
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      $this->settingsId,
    ];
  }

}
