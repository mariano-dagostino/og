<?php

namespace Drupal\og\Plugin\Validation\Constraint;

use Drupal\Core\Access\AccessResult;
use Drupal\og\Og;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Checks if referenced entities are valid.
 */
class ValidOgMembershipReferenceConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    /* @var \Drupal\Core\Field\FieldItemInterface $value */
    if (!isset($value)) {
      return;
    }

    $entity = \Drupal::entityTypeManager()
      ->getStorage($value->getFieldDefinition()->getFieldStorageDefinition()->getSetting('target_type'))
      ->load($value->get('target_id')->getValue());

    if (!$entity) {
      // Entity with that entity ID does not exists. This could happen if a
      // stale entity is passed for validation.
      return;
    }

    $params['%label'] = $entity->label();

    if (!Og::isGroup($entity->getEntityTypeId(), $entity->bundle())) {
      $this->context->addViolation($constraint->NotValidGroup, $params);
    }

    /** @var AccessResult $access */
    $access = \Drupal::service('og.access')->userAccessEntity('create', $entity, \Drupal::currentUser()->getAccount());

    if ($access->isForbidden()) {
      $this->context->addViolation($constraint->NotAllowedToPostInGroup, $params);
    }
  }

}
