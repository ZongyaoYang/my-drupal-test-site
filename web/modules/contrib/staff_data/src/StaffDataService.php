<?php
namespace Drupal\staff_data;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;

/**
 * Creates Person entities based on data from the HR API.
 * Currently coming from Workday
 */
class StaffDataService {

  /**
   * A marker on records.
   *
   * Denotes they should be handled by this module and auto-populated.
   * If records don't have this marker, they are to be ignored by this module.
   */
  const MAGIC_CREATED_TIME = 54712500;

  /**
   * Location of the api credentials json file.
   */
  const CREDENTIALS_FILE = DRUPAL_ROOT . '/sites/default/files/private/staff_data_endpoint.json';

  /**
   * The module name.
   *
   * @var string
   */
  protected static string $module = '';

  /**
   * Photo directory.
   *
   * @var string
   */
  protected static string $photoDirectory = '';

  /**
   * API endpoint.
   *
   * @var \stdClass
   */
  protected static ?\stdClass $endpoint = NULL;

  /**
   * Constructor.
   */
  public function __construct() {
    self::$module = strtolower(basename(dirname(__DIR__)));
    self::$photoDirectory = 'public://' . self::$module . '_photos';
    self::preparePhotoDirectory();
    self::getEndpoint();
  }

  /**
   * Create the photo directory.
   *
   * @return void
   *   Does not return a value.
   */
  public static function preparePhotoDirectory() {
    \Drupal::service('file_system')->prepareDirectory(self::$photoDirectory, FileSystemInterface::CREATE_DIRECTORY);
  }

  /**
   * Get the HR API endpoint.
   *
   * @return mixed
   *   The API endpoint.
   */
  public static function getEndpoint() {
    if (!self::$endpoint && file_exists(self::CREDENTIALS_FILE)) {
      self::$endpoint = json_decode(trim(file_get_contents(self::CREDENTIALS_FILE)));
    }

    return self::$endpoint;
  }

  /**
   * Get person data from the API endpoint.
   *
   * @return void
   *   Does not return a value.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function retrieveAndSync() {
    $persons = $this->getPersonsFromApi(
      \Drupal::config(self::$module . '.settings')->get('org')
    );

    if ($persons) {
      if (PHP_SAPI != 'cli' && count($persons) > 50) {
        \Drupal::logger(self::$module)->info('Too many staff to sync with cron. Please use the "drush staff-data:sync" command.');
      }
      else {
        $this->syncPersons($persons);
      }
    }
  }

  /**
   * Get the staff data from api (workday, people whatever).
   *
   * @param string $org
   *   Organization acronym.
   *
   * @return array
   *   Returns person data.
   */
  protected function getPersonsFromApi(string $org) {
    $persons = [];
    try {
      if (!self::$endpoint) {
        throw new \Exception('No endpoint found');
      }
      $url = rtrim(self::$endpoint->url, '/') . '/persons' . ($org ? '?' . http_build_query(['org' => $org]) : '');

      $items = $this->getApiData($url);

      foreach ($items as $item) {
        if (isset($item->workEmail)) {
          $persons[strtolower($item->workEmail)] = $item;
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger(self::$module)->error($e->getMessage());
    }

    return $persons;
  }

  /**
   * Return data from the API, json decoded.
   *
   * @param string $url
   *   API url.
   *
   * @return mixed
   *   Data decoded from JSON.
   *
   * @throws \Exception
   */
  protected function getApiData(string $url) {
    $response = \Drupal::httpClient()->get($url, ['auth' => [self::$endpoint->username, self::$endpoint->password]]);
    if ($response->getStatusCode() != '200') {
      throw new \Exception('Bad HTTP Response: ' . $response->getStatusCode());
    }

    $decoded = json_decode($response->getBody());
    if (!$decoded) {
      throw new \Exception('No response in body: ' . var_export($decoded, TRUE));
    }

    if (is_array($decoded)) {
      \Drupal::logger(self::$module)->info('Retrieved ' . count($decoded) . ' records from ' . $url);
    }
    /* 
    // since check for updates on every picture from WD then commenting out as only sees to be for pictures
    else {
      \Drupal::logger(self::$module)->info('Retrieved payload from ' . $url);
    }
    */

    return $decoded;
  }

  /**
   * Sync people data from the API with the database.
   *
   * @param array $api_people
   *   People data from the API.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function syncPersons(array $api_people) {
    $db_persons = $this->loadPersonsFromDb();

    // Person in api, and is in db: only manage if magic marker: sync.
    // Person not in api, and is in db: only manage if magic marker: delete.
    // Person in api, and not in db: should be managed: create.
    foreach ($db_persons as $db_person) {
      /** @var \Drupal\node\Entity\Node $db_person */

      $db_email = strtolower($db_person->get('field_email')->value);

      // They are managed by the module.
      if ($db_person->getCreatedTime() == self::MAGIC_CREATED_TIME) {
        if (isset($api_people[$db_email])) {
          $this->syncPerson($db_person, $api_people[$db_email]);
        }
        else {
          $this->deletePerson($db_person);
        }
      }

      // They've already been managed above, or they don't have the
      // marker and shouldn't be managed. Either way, remove them
      // from the list so they don't get (re)created below.
      if (isset($api_people[$db_email])) {
        unset($api_people[$db_email]);
      }
    }

    // Any remaining api entries, create in the db.
    if ($api_people) {
      \Drupal::logger(self::$module)->info('Creating ' . count($api_people) . ' new Person nodes');
      foreach ($api_people as $api_person) {
        $this->createPerson($api_person);
      }
    }
  }

  /**
   * Get all person nodes.
   *
   * @param bool $only_managed
   *   Managed people.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of Person entities.
   */
  public function loadPersonsFromDb($only_managed = FALSE) {
    $storage = \Drupal::entityTypeManager()
      ->getStorage('node');

    $query = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'person', '=');
    if ($only_managed) {
      $query = $query->condition('created', self::MAGIC_CREATED_TIME, '=');
    }
    $pids = $query->execute();

    \Drupal::logger(self::$module)->info('Loaded ' . count($pids) . ' records from DB');

    return $storage
      ->loadMultiple($pids);
  }

  /**
   * Performs a sync of data from the API and database.
   *
   * @param \Drupal\node\entity\Node $db_person
   *   Person data from the database.
   * @param \stdClass $api_person
   *   Person data from the API.
   *
   * @return void
   *   Does not return data.
   */
  protected function syncPerson(Node $db_person, \stdClass $api_person) {
    $api_person = $this->normalizeApiPerson($api_person);
    $dirty = FALSE;
    // Compare api person versus db person, and update any fields
    // that have changed.
    foreach ($api_person as $field_name => $value) {
      $node_field = 'field_' . $field_name;
      if ($db_person->hasField($node_field) && $db_person->get($node_field)->value != $value) {
        \Drupal::logger(self::$module)->debug($node_field . ': ' . $db_person->get($node_field)->value . ' != ' . $value);
        $db_person->set($node_field, $value);
        $dirty = TRUE;
      }
      elseif ($field_name == 'has_photo' && $value) {
        if ($this->syncPhoto($db_person, $api_person)) {
          $dirty = TRUE;
        }
      }
      elseif ($field_name == 'has_photo' && $value === 0) {
        $this->detachStaffPhoto($db_person);
        $dirty = TRUE;
      }
    }
    if ($dirty) {
      \Drupal::logger(self::$module)->debug('Updating ' . $db_person->getTitle());
      $db_person->save();
    }
  }

  /**
   * Normalize the API person data.
   *
   * @param object $api_person
   *   Person API data.
   *
   * @return \stdClass
   *   Return the normalized data.
   */
  protected function normalizeApiPerson(object $api_person) {
    $normalized = new \stdClass();
    $normalized->first_name = $api_person->preferredName ?? ($api_person->firstName ?? '');
    $normalized->last_name = $api_person->lastName ?? '';
    $normalized->email = strtolower(($api_person->workEmail ?? ''));
    $normalized->phone = $api_person->workPhone ?? '';
    $normalized->job_title = $api_person->position ?? '';
    $normalized->organization = $api_person->organization ?? '';
    $normalized->has_photo = $api_person->HasPhoto === '1';
    $normalized->username = $api_person->username ?? '';

    return $normalized;
  }

  /**
   * Sync the photo with the API and the database.
   *
   * @param \Drupal\node\entity\Node $db_person
   *   Person database data.
   * @param \stdClass $api_person
   *   Person API data.
   *
   * @return bool
   *   Returns whether a person has a photo or not.
   */
  protected function syncPhoto(Node $db_person, \stdClass $api_person): bool {
    // Just a sanity check.
    if (!isset($api_person->has_photo) || !$api_person->has_photo || !$api_person->username) {
      return FALSE;
    }

    try {
      $photo = $this->getApiPhoto($api_person);

      // get current primary image added and set defaults
      $media_entity = $db_person->get('field_primary_image')->entity ?? FALSE;
      $image_filename = FALSE;

      // user HAS a pic added
      if ($media_entity) {
        // get the file of the media object used in the node
        $file = $media_entity->get('field_media_image')->entity ?? FALSE;
        if ($file) {
          // set the filename and path
          $image_filename = $file->getFilename();
          $image_uri = $file->getFileUri();

          // if the pic is not in the right dir, names dont match, photo not on server, or size doesnt match
          if ( (strpos($image_uri, self::$photoDirectory . '/') !== 0) || ($photo->LocalFileName !== $image_filename) || (!file_exists($photo->LocalPath) || filesize($photo->LocalPath) != $photo->FileSize) ) {
            \Drupal::logger(self::$module)->info('Syncing photo for ' . $api_person->username);
            return $this->attachStaffPhoto($photo, $db_person);
          }
        }
        else {
          // no file found so attach as failsafe
          \Drupal::logger(self::$module)->info('No photo file so adding for ' . $api_person->username);
          return $this->attachStaffPhoto($photo, $db_person);
        }
      }
      // user NO pic added 
      else {
        \Drupal::logger(self::$module)->info('Adding photo for ' . $api_person->username);
        return $this->attachStaffPhoto($photo, $db_person);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger(self::$module)->error($e->getMessage());
    }

    return FALSE;
  }

  /**
   * Get the person photo from the API.
   *
   * @param \stdClass $person
   *   Person data from the API.
   *
   * @return mixed
   *   Returns the photo.
   *
   * @throws \Exception
   */
  protected function getApiPhoto(\stdClass $person) {
    if (!self::$endpoint) {
      throw new \Exception('No endpoint found');
    }
    $url = rtrim(self::$endpoint->url, '/') . '/personPhotos/' . $person->username;

    $photo = $this->getApiData($url);
    if (!$photo || !isset($photo->File) || !$photo->File || !isset($photo->FileName) || !$photo->FileName) {
      throw new \Exception('No photo found for ' . $person->username);
    }

    $photo_extension = strtolower(pathinfo($photo->FileName, PATHINFO_EXTENSION)) === 'jpeg' ? 'jpg' : strtolower(pathinfo($photo->FileName, PATHINFO_EXTENSION));
    $photo->LocalPath = strtolower(self::$photoDirectory . '/' . $person->username . '.' . $photo_extension);
    $photo->LocalFileName = strtolower($person->username . '.' . $photo_extension);
    $photo->FileSize = strlen(base64_decode($photo->File));
    $photo->Name = $person->first_name . ' ' . $person->last_name;

    return $photo;
  }

  /**
   * Create a media entity of the photo and attach it to the Person entity.
   *
   * @param \stdClass $photo
   *   The local photo.
   * @param \Drupal\node\Entity\Node $db_person
   *   Person data from the database.
   *
   * @return bool
   *   Return whether a photo has been attached to the Person entity.
   *
   * @throws \Exception
   */
  protected function attachStaffPhoto(\stdClass $photo, Node $db_person): bool {
    try {
      // get current primary image added and set defaults
      $media_entity = $db_person->get('field_primary_image')->entity ?? FALSE;
      $image_filename = FALSE;
      $dirty_image = FALSE;
      $has_media = FALSE;

      // check if media object (in correct dir) already added via filename
      $file_check = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $photo->LocalPath]);
      if (!empty($file_check)) {
        $has_media = TRUE;
      }

      // user HAS a pic added
      if ($media_entity) {
        // get the file of the media object
        $file = $media_entity->get('field_media_image')->entity ?? FALSE;
        if ($file) {
          // set the filename and path
          $image_filename = $file->getFilename();
          $image_uri = $file->getFileUri();

          // if the pic names dont match
          // upload new pic with standarized filename
          // dont delete old pic as could be default placeholder - user.png
          if ($photo->LocalFileName !== $image_filename) {
            $dirty_image = TRUE;
          }

          // if the pic is not in the right dir
          // upload the new pic in the right dir
          // from previous bugfix where module caused pics to be saved in YYYY-MM default dir instead of staff_data_photos
          elseif (strpos($image_uri, self::$photoDirectory . '/') !== 0) {
            $dirty_image = TRUE;
          }

          // photo not on server in the right dir or size doesnt match
          // should just be a failsafe
          elseif (!file_exists($photo->LocalPath) || filesize($photo->LocalPath) != $photo->FileSize) {
            $dirty_image = TRUE;
            $has_media = FALSE;
          }
        }
        // has media but no file
        else {
          $dirty_image = TRUE;
          $has_media = FALSE;
        }
      }
      // user NO pic added
      else {
        $dirty_image = TRUE;
      }



      // if have dirty image needing to be updated
      if ($dirty_image) {
        // if have media object (or file on server) already then use that
        if ($has_media) {
          // get the media object
          $file_media = reset($file_check);
          $media_entities = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties([
            'field_media_image.target_id' => $file_media->id(),
          ]);

          // check if got a real one
          if (!empty($media_entities)) {
            $media_entity = reset($media_entities);
            $db_person->set('field_primary_image', ['target_id' => $media_entity->id()]);
            \Drupal::logger(self::$module)->info('Reused existing media object: ' . $file_media->getFilename());
          }
          // have file but no media object so create
          else {
            // Handle filefield_paths module if needed
            $this->toggleFileFieldPaths(false);
            
            // create media based on file
            $media_image = Media::create([
              'bundle' => 'image',
              'name' => $photo->Name,
              'uid' => \Drupal::currentUser()->id(),
              'langcode' => \Drupal::languageManager()->getDefaultLanguage()->getId(),
              "thumbnail" => [
                "target_id" => $file_media->id(),
                "alt" => $photo->Name,
              ],
              "field_media_image" => [
                "target_id" => $file_media->id(),
                "alt" => $photo->Name,
              ],
            ]);
            $media_image->setPublished();
            $media_image->save();
            $db_person->set('field_primary_image', $media_image);


            // Handle filefield_paths module if needed
            $this->toggleFileFieldPaths(true);

            \Drupal::logger(self::$module)->info('Reused existing file to create media object: ' . $file_media->getFilename());
          }
        }
        // no media object so create
        else {
          // Handle filefield_paths module if needed
          $this->toggleFileFieldPaths(false);


          // save the image using file_system so into right dir
          $file_system = \Drupal::service('file_system');
          $file_saved = $file_system->saveData(base64_decode($photo->File), $photo->LocalPath, FileSystemInterface::EXISTS_REPLACE);

          // check if got a file saved
          if ($file_saved) {
            \Drupal::logger(self::$module)->info('Created file: ' . basename($photo->LocalPath));
            $file = File::create([ 'uri' => $photo->LocalPath, ]);
            $file->save();

            $media_image = Media::create([
              'bundle' => 'image',
              'name' => $photo->Name,
              'uid' => \Drupal::currentUser()->id(),
              'langcode' => \Drupal::languageManager()->getDefaultLanguage()->getId(),
              "thumbnail" => [
                "target_id" => $file->id(),
                "alt" => $photo->Name,
              ],
              "field_media_image" => [
                "target_id" => $file->id(),
                "alt" => $photo->Name,
              ],
            ]);
            $media_image->setPublished();
            $media_image->save();
            $db_person->set('field_primary_image', $media_image);
            \Drupal::logger(self::$module)->info('Created media: ' . $photo->Name);
          }
          // no file saved
          else {
            \Drupal::logger(self::$module)->info('Failed to save file: ' . $photo->LocalPath);
          }

          // Handle filefield_paths module if needed
          $this->toggleFileFieldPaths(true);
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger(self::$module)->error($e->getMessage());
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Toggles filefield_paths module settings
   * If not using module will return
   */
  protected function toggleFileFieldPaths(bool $enabled): void {
    if (!\Drupal::moduleHandler()->moduleExists('filefield_paths')) {
      return;
    }

    $field_config = \Drupal::entityTypeManager()->getStorage('field_config')->load('media.image.field_media_image');

    if ($field_config->getThirdPartySetting('filefield_paths', 'enabled') !== null) {
      $field_config->setThirdPartySetting('filefield_paths', 'enabled', $enabled);
      $field_config->save();
    }
  }

  /**
   * Detach media object from Person entity.
   *
   * @param \Drupal\node\Entity\Node $db_person
   *   Person data from the database.
   *
   * @return void
   *   Does not return data.
   *
   */
  protected function detachStaffPhoto(Node $db_person) {
    \Drupal::logger(self::$module)->debug('Removing media object for ' . $db_person->getTitle());
    $db_person->set('field_primary_image', '');
  }

  /**
   * Delete a Person entity.
   *
   * @param \Drupal\node\Entity\Node $db_person
   *   Person data from the database.
   *
   * @return void
   *   Does not return data.
   *
   */
  protected function deletePerson(Node $db_person) {
    \Drupal::logger(self::$module)->debug('Deleting person ' . $db_person->getTitle());
    $db_person->delete();
  }

  /**
   * Creates a Person entity.
   *
   * @param \stdClass $api_person
   *   Person data from the API.
   *
   * @return void
   *   Does not return data.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createPerson(\stdClass $api_person) {
    $normalized = $this->normalizeApiPerson($api_person);

    $node = Node::create([
      'type' => 'person',
      'title' => $normalized->first_name . ' ' . $normalized->last_name,
      // This is the marker to let the module know to "manage" this node.
      'created' => self::MAGIC_CREATED_TIME,
      'changed' => time(),
      'field_email' => ['value' => $normalized->email],
      'field_first_name' => ['value' => $normalized->first_name],
      'field_last_name' => ['value' => $normalized->last_name],
      'field_job_title' => ['value' => $normalized->job_title],
      'field_organization' => ['value' => $normalized->organization],
      'field_phone' => ['value' => $normalized->phone],
    ]);
    $node->setPublished();

    if ($normalized->has_photo) {
      $this->syncPhoto($node, $normalized);
    }

    $node->save();
  }

  /**
   * Delete all persons from the database.
   *
   * @param array $persons
   *   All persons.
   *
   * @return void
   *   Does not return any data.
   */
  public function deletePersonsFromDb(array $persons) {
    \Drupal::entityTypeManager()
      ->getStorage('node')
      ->delete($persons);
  }

}