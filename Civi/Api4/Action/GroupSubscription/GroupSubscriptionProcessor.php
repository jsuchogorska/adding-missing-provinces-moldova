<?php

namespace Civi\Api4\Action\GroupSubscription;

trait GroupSubscriptionProcessor {

  /**
   * Toggle behavior of confirming subscriptions via email.
   *
   * @var bool
   */
  protected $doubleOptin = TRUE;

  public function getDoubleOptin(): bool {
    return $this->doubleOptin;
  }

  public function setDoubleOptin(bool $doubleOptin) {
    $this->doubleOptin = $doubleOptin;
    return $this;
  }

  /**
   * Function to process groups
   *
   * @param array $submittedData
   *
   * @return void
   */
  protected function writeRecord($submittedData) {
    // get all visible groups
    $allGroups = self::getEnabledGroups();

    $contactId = $submittedData['contact_id'];

    // get the current groups for this contact
    $currentGroups = \Civi\Api4\GroupContact::get(FALSE)
      ->addSelect('group_id.name', 'status')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('group_id.is_active', '=', TRUE)
      ->addWhere('group_id.is_hidden', '=', FALSE)
      ->execute()->column('status', 'group_id.name');

    // loop through submitted groups
    foreach ($submittedData as $groupName => $optIn) {
      $groupInfo = $allGroups[$groupName] ?? NULL;
      if (!$groupInfo) {
        continue;
      }
      $currentStatus = $currentGroups[$groupName] ?? NULL;

      // Add to group
      if ($optIn) {
        if ($currentStatus === 'Added') {
          // Nothing to do
          continue;
        }

        // check if double opt-in is enabled
        $doConfirm = $this->doubleOptin && ($groupInfo['visibility'] === 'Public Pages');
        if ($doConfirm) {
          $contactPrimaryEmail = self::getContactPrimaryEmail($contactId);
          if (!$contactPrimaryEmail) {
            \Civi::log()->warning("Could not subscribe contact id $contactId to group '{$groupInfo['title']}' - no email for contact.");
            $submittedData[$groupName] = FALSE;
            continue;
          }
          $newStatus = 'Pending';
        }
        else {
          $newStatus = 'Added';
        }

        self::saveGroupStatus($contactId, $groupName, $newStatus);

        if ($doConfirm) {
          self::triggerDoubleOptin($contactId, $groupName, $contactPrimaryEmail);
        }
      }
      // FALSE will be treated as an unsubscribe request, but NULL and '' will be ignored.
      elseif ($optIn === FALSE) {
        // Remove contact from group
        if ($currentStatus && $currentStatus !== 'Removed') {
          self::saveGroupStatus($contactId, $groupName, 'Removed');
        }
      }
    }

    return $submittedData;
  }

  /**
   * Add or update groupContact status
   *
   * @param int $contactId
   * @param string $groupName
   * @param string $status
   *
   * @return void
   */
  private static function saveGroupStatus($contactId, $groupName, $status) {
    \Civi\Api4\GroupContact::save(FALSE)
      ->addRecord([
        'group_id.name' => $groupName,
        'contact_id' => $contactId,
        'status' => $status,
      ])
      ->setMatch(['contact_id', 'group_id'])
      ->execute();
  }

  /**
   * Function to trigger double optin process
   *
   * @param int $contactId
   * @param string $groupName
   * @param string $email
   *
   * @return void
   */
  private static function triggerDoubleOptin($contactId, $groupName, $email) {
    // FIXME: Implement this in APIv4
    $groupId = \CRM_Contact_DAO_Group::getDbVal('id', $groupName, 'name');
    civicrm_api3('MailingEventSubscribe', 'create', [
      'contact_id' => $contactId,
      'group_id' => $groupId,
      'email' => $email,
    ]);
  }

  /**
   * Function to get contact primary email
   *
   * @param int $contactId
   *
   * @return string
   */
  private static function getContactPrimaryEmail($contactId) {
    $emails = \Civi\Api4\Email::get(FALSE)
      ->addSelect('email')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('is_primary', '=', TRUE)
      ->setLimit(1)
      ->execute();

    return $emails[0]['email'] ?? NULL;
  }

  public static function getEnabledGroups(): array {
    if (!isset(\Civi::$statics[__CLASS__][__FUNCTION__])) {
      \Civi::$statics[__CLASS__][__FUNCTION__] = (array) \Civi\Api4\Group::get(FALSE)
        ->addSelect('id', 'name', 'frontend_title', 'title', 'frontend_description', 'description', 'visibility')
        ->addWhere('is_active', '=', TRUE)
        ->addWhere('is_hidden', '=', FALSE)
        ->execute()->indexBy('name');
    }
    return \Civi::$statics[__CLASS__][__FUNCTION__];
  }

}
