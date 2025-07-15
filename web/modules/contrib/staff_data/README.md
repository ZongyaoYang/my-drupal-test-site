# staff_data
This module syncs staff profiles from Workday to the Person content type through cron.

The sync is one-way i.e. it syncs data from Workday to Drupal, and not Drupal to Workday. The module will sync all records from a given org and any of that org's sub-orgs (if any sub-orgs exist).

It uses a credential file uploaded to your site to access the Workday API. This file is uploaded at `/sites/default/files/private/staff_data_endpoint.json`.

The module is configured at `/admin/config/people/staff_data_settings`.

If you change the org acronym, all staff from the previous org will be removed and staff from the new org will be created. If someone leaves the org, they will be deleted from your site, or if someone’s position changes, the position will change on your site, etc.

Once the module creates a person node, it will continue managing that node, syncing the following fields: first name, last name, email, phone, job title, organization, and photo. If you make edits to those fields in Drupal, they will get overwritten on the next sync. Any other fields will be ignored by the module.

If the person has a photo in Workday, it will get pulled in to Drupal, added to the Media Library, and used as their photo here.

If you want the module to stop managing a person, change the 'Authored on' datetime of the node, and that entry will not be synced going forward. If you create a person manually in Drupal, the module will ignore that entry.
