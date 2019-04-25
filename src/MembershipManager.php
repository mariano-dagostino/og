<?php

namespace Drupal\og;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\og\Entity\OgMembership;

/**
 * Service for managing memberships and group content.
 */
class MembershipManager implements MembershipManagerInterface {

  /**
   * The static cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $staticCache;

  /**
   * The entity type manager.
   *
   * @var \Drupal\core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The OG group audience helper.
   *
   * @var \Drupal\og\OgGroupAudienceHelperInterface
   */
  protected $groupAudienceHelper;

  /**
   * Constructs a MembershipManager object.
   *
   * @param \Drupal\core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\og\OgGroupAudienceHelperInterface $group_audience_helper
   *   The OG group audience helper.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The static cache backend.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, OgGroupAudienceHelperInterface $group_audience_helper, CacheBackendInterface $cache) {
    assert($cache instanceof CacheTagsInvalidatorInterface, 'The cache backend must support cache tag invalidation.');
    $this->entityTypeManager = $entity_type_manager;
    $this->groupAudienceHelper = $group_audience_helper;
    $this->staticCache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserGroupIds(AccountInterface $user, array $states = [OgMembershipInterface::STATE_ACTIVE]) {
    $group_ids = [];

    /** @var \Drupal\og\Entity\OgMembership[] $memberships */
    $memberships = $this->getMemberships($user, $states);
    foreach ($memberships as $membership) {
      $group_ids[$membership->getGroupEntityType()][] = $membership->getGroupId();
    }

    return $group_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserGroups(AccountInterface $user, array $states = [OgMembershipInterface::STATE_ACTIVE]) {
    $groups = [];

    foreach ($this->getUserGroupIds($user, $states) as $entity_type => $entity_ids) {
      $groups[$entity_type] = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($entity_ids);
    }

    return $groups;
  }

  /**
   * {@inheritdoc}
   */
  public function getMemberships(AccountInterface $user, array $states = [OgMembershipInterface::STATE_ACTIVE]) {
    // When an empty array is passed, retrieve memberships with all possible
    // states.
    $states = $this->prepareConditionArray($states, OgMembership::ALL_STATES);

    $cid = [
      __METHOD__,
      $user->id(),
      implode('|', $states),
    ];
    $cid = implode(':', $cid);

    // Use cached result if it exists.
    if (!$membership_ids = $this->staticCache->get($cid)->data ?? []) {
      $query = $this->entityTypeManager
        ->getStorage('og_membership')
        ->getQuery()
        ->condition('uid', $user->id())
        ->condition('state', $states, 'IN');

      $membership_ids = $query->execute();
      $this->cacheMembershipIds($cid, $membership_ids);
    }

    return $this->loadMemberships($membership_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getMembership(EntityInterface $group, AccountInterface $user, array $states = [OgMembershipInterface::STATE_ACTIVE]) {
    foreach ($this->getMemberships($user, $states) as $membership) {
      if ($membership->getGroupEntityType() === $group->getEntityTypeId() && $membership->getGroupId() === $group->id()) {
        return $membership;
      }
    }

    // No membership matches the request.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupMembershipIdsByRoleNames(EntityInterface $group, array $role_names, array $states = [OgMembershipInterface::STATE_ACTIVE]) {
    if (empty($role_names)) {
      throw new \InvalidArgumentException('The array of role names should not be empty.');
    }

    // In case the 'member' role is one of the requested roles, we just need to
    // return all memberships. We can safely ignore all other roles.
    $retrieve_all_memberships = FALSE;
    if (in_array(OgRoleInterface::AUTHENTICATED, $role_names)) {
      $retrieve_all_memberships = TRUE;
      $role_names = [OgRoleInterface::AUTHENTICATED];
    }

    $role_names = $this->prepareConditionArray($role_names);
    $states = $this->prepareConditionArray($states, OgMembership::ALL_STATES);

    $cid = [
      __METHOD__,
      $group->getEntityTypeId(),
      $group->id(),
      implode('|', $role_names),
      implode('|', $states),
    ];
    $cid = implode(':', $cid);

    // Only query the database if no cached result exists.
    if (!$membership_ids = $this->staticCache->get($cid)->data ?? []) {
      $entity_type_id = $group->getEntityTypeId();

      $query = $this->entityTypeManager
        ->getStorage('og_membership')
        ->getQuery()
        ->condition('entity_type', $entity_type_id)
        ->condition('entity_id', $group->id())
        ->condition('state', $states, 'IN');

      if (!$retrieve_all_memberships) {
        $bundle_id = $group->bundle();
        $role_ids = array_map(function ($role_name) use ($entity_type_id, $bundle_id) {
          return implode('-', [$entity_type_id, $bundle_id, $role_name]);
        }, $role_names);

        $query->condition('roles', $role_ids, 'IN');
      }

      $membership_ids = $query->execute();
      $this->cacheMembershipIds($cid, $membership_ids);
    }

    return $membership_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupMembershipsByRoleNames(EntityInterface $group, array $role_names, array $states = [OgMembershipInterface::STATE_ACTIVE]) {
    $ids = $this->getGroupMembershipIdsByRoleNames($group, $role_names, $states);
    return $this->loadMemberships($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function createMembership(EntityInterface $group, AccountInterface $user, $membership_type = OgMembershipInterface::TYPE_DEFAULT) {
    /** @var \Drupal\user\UserInterface|\Drupal\Core\Session\AccountInterface $user */
    /** @var \Drupal\og\OgMembershipInterface $membership */
    $membership = OgMembership::create(['type' => $membership_type]);
    $membership
      ->setOwner($user)
      ->setGroup($group);

    return $membership;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupIds(EntityInterface $entity, $group_type_id = NULL, $group_bundle = NULL) {
    // This does not work for user entities.
    if ($entity->getEntityTypeId() === 'user') {
      throw new \InvalidArgumentException('\Drupal\og\MembershipManager::getGroupIds() cannot be used for user entities. Use \Drupal\og\MembershipManager::getUserGroups() instead.');
    }

    $cid = [
      __METHOD__,
      $entity->getEntityTypeId(),
      $entity->id(),
      $group_type_id,
      $group_bundle,
    ];

    $cid = implode(':', $cid);

    if ($group_ids = $this->staticCache->get($cid)->data ?? []) {
      // Return cached values.
      return $group_ids;
    }

    $group_ids = [];
    $tags = $entity->getCacheTagsToInvalidate();

    $fields = $this->groupAudienceHelper->getAllGroupAudienceFields($entity->getEntityTypeId(), $entity->bundle(), $group_type_id, $group_bundle);
    foreach ($fields as $field) {
      $target_type_id = $field->getFieldStorageDefinition()->getSetting('target_type');
      $target_type_definition = $this->entityTypeManager->getDefinition($target_type_id);

      // Optionally filter by group type.
      if (!empty($group_type_id) && $group_type_id !== $target_type_id) {
        continue;
      }

      $values = $entity->get($field->getName())->getValue();
      if (empty($values[0])) {
        // Entity doesn't reference any groups.
        continue;
      }

      // Compile a list of group target IDs.
      $target_ids = array_map(function ($value) {
        return $value['target_id'];
      }, $entity->get($field->getName())->getValue());

      if (empty($target_ids)) {
        continue;
      }

      // Query the database to get the actual list of groups. The target IDs may
      // contain groups that no longer exist. Entity reference doesn't clean up
      // orphaned target IDs.
      $entity_type = $this->entityTypeManager->getDefinition($target_type_id);
      $query = $this->entityTypeManager
        ->getStorage($target_type_id)
        ->getQuery()
        // Disable entity access check so fetching the groups related to group
        // content are not affected by the current user. Furthermore, when
        // rebuilding node access and the groups are nodes, we should not try to
        // retrieve node access records which do not exist because the rebuild
        // process has already erased the grants table.
        ->accessCheck(FALSE)
        ->condition($entity_type->getKey('id'), $target_ids, 'IN');

      // Optionally filter by group bundle.
      if (!empty($group_bundle)) {
        $query->condition($entity_type->getKey('bundle'), $group_bundle);
      }

      // Add the list cache tags for the entity type, so that the cache gets
      // invalidated if one of the group entities is deleted.
      $tags = Cache::mergeTags($target_type_definition->getListCacheTags(), $tags);

      $group_ids = NestedArray::mergeDeep($group_ids, [$target_type_id => $query->execute()]);
    }

    $this->staticCache->set($cid, $group_ids, Cache::PERMANENT, $tags);

    return $group_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroups(EntityInterface $entity, $group_type_id = NULL, $group_bundle = NULL) {
    $groups = [];

    foreach ($this->getGroupIds($entity, $group_type_id, $group_bundle) as $entity_type => $entity_ids) {
      $groups[$entity_type] = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($entity_ids);
    }

    return $groups;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupCount(EntityInterface $entity, $group_type_id = NULL, $group_bundle = NULL) {
    return array_reduce($this->getGroupIds($entity, $group_type_id, $group_bundle), function ($carry, $item) {
      return $carry + count($item);
    }, 0);
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupContentIds(EntityInterface $entity, array $entity_types = []) {
    $group_content = [];

    // Retrieve the fields which reference our entity type and bundle.
    $query = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->getQuery()
      ->condition('type', OgGroupAudienceHelperInterface::GROUP_REFERENCE);

    // Optionally filter group content entity types.
    if ($entity_types) {
      $query->condition('entity_type', $entity_types, 'IN');
    }

    /** @var \Drupal\field\FieldStorageConfigInterface[] $fields */
    $fields = array_filter(FieldStorageConfig::loadMultiple($query->execute()), function (FieldStorageConfigInterface $field) use ($entity) {
      $type_matches = $field->getSetting('target_type') === $entity->getEntityTypeId();
      // If the list of target bundles is empty, it targets all bundles.
      $bundle_matches = empty($field->getSetting('target_bundles')) || in_array($entity->bundle(), $field->getSetting('target_bundles'));
      return $type_matches && $bundle_matches;
    });

    // Compile the group content.
    foreach ($fields as $field) {
      $group_content_entity_type = $field->getTargetEntityTypeId();

      // Group the group content per entity type.
      if (!isset($group_content[$group_content_entity_type])) {
        $group_content[$group_content_entity_type] = [];
      }

      // Query all group content that references the group through this field.
      $results = $this->entityTypeManager
        ->getStorage($group_content_entity_type)
        ->getQuery()
        ->condition($field->getName() . '.target_id', $entity->id())
        ->execute();

      $group_content[$group_content_entity_type] = array_merge($group_content[$group_content_entity_type], $results);
    }

    return $group_content;
  }

  /**
   * {@inheritdoc}
   */
  public function isMember(EntityInterface $group, AccountInterface $user, array $states = [OgMembershipInterface::STATE_ACTIVE]) {
    $group_ids = $this->getUserGroupIds($user, $states);
    $entity_type_id = $group->getEntityTypeId();
    return !empty($group_ids[$entity_type_id]) && in_array($group->id(), $group_ids[$entity_type_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function isMemberPending(EntityInterface $group, AccountInterface $user) {
    return $this->isMember($group, $user, [OgMembershipInterface::STATE_PENDING]);
  }

  /**
   * {@inheritdoc}
   */
  public function isMemberBlocked(EntityInterface $group, AccountInterface $user) {
    return $this->isMember($group, $user, [OgMembershipInterface::STATE_BLOCKED]);
  }

  /**
   * Prepares a conditional array for use in a cache identifier and query.
   *
   * This will filter out any duplicate values from the array and sort the
   * values so that a consistent cache identifier can be generated. Optionally
   * it can substitute an empty array with a default value.
   *
   * @param array $value
   *   The array to prepare.
   * @param array|null $default
   *   An optional default value to use in case the passed in value is empty. If
   *   set to NULL this will be ignored.
   *
   * @return array
   *   The prepared array.
   */
  protected function prepareConditionArray(array $value, array $default = NULL) {
    // Fall back to the default value if the passed in value is empty and a
    // default value is given.
    if (empty($value) && $default !== NULL) {
      $value = $default;
    }
    sort($value);
    return array_unique($value);
  }

  /**
   * Stores the given list of membership IDs in the static cache backend.
   *
   * @param string $cid
   *   The cache ID.
   * @param array $membership_ids
   *   The list of membership IDs to store in the static cache.
   */
  protected function cacheMembershipIds($cid, array $membership_ids) {
    $tags = Cache::buildTags('og_membership', $membership_ids);
    // Also invalidate the list cache tags so that if a new membership is
    // created it will appear in this list.
    $tags = Cache::mergeTags(['og_membership_list'], $tags);
    $this->staticCache->set($cid, $membership_ids, Cache::PERMANENT, $tags);
  }

  /**
   * Returns the full membership entities with the given memberships IDs.
   *
   * @param array $ids
   *   The IDs of the memberships to load.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The membership entities.
   */
  protected function loadMemberships(array $ids) {
    if (empty($ids)) {
      return [];
    }

    return $this->entityTypeManager
      ->getStorage('og_membership')
      ->loadMultiple($ids);
  }

}
