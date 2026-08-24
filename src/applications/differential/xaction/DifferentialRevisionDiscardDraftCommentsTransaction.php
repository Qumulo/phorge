<?php

/**
 * Set whether a revision discards its draft comments when it publishes.
 *
 * This carries an explicit value, so callers are idempotent. The action menu
 * uses @{class:DifferentialRevisionToggleDiscardCommentsTransaction} instead,
 * which flips the stored value.
 */
final class DifferentialRevisionDiscardDraftCommentsTransaction
  extends DifferentialRevisionTransactionType {

  const TRANSACTIONTYPE = 'discard-comments';
  const EDITKEY = 'discard-comments';

  public function generateOldValue($object) {
    return (bool)$object->getDiscardDraftComments();
  }

  public function generateNewValue($object, $value) {
    return (bool)$value;
  }

  public function applyInternalEffects($object, $value) {
    $object->setDiscardDraftComments($value);
  }

  public function validateTransactions($object, array $xactions) {
    $errors = array();
    $actor = $this->getActor();

    // The default revision edit policy is "all users", so without this check
    // anyone could arm the discard on somebody else's draft and destroy a
    // conversation they have no part in.
    $actor_phid = $actor->getPHID();
    if (!$actor_phid) {
      // Herald and other system actors act without a user identity.
      return $errors;
    }

    if ($actor_phid === $object->getAuthorPHID()) {
      return $errors;
    }

    foreach ($xactions as $xaction) {
      $errors[] = $this->newInvalidError(
        pht(
          'Only the author of a revision can change whether it discards the '.
          'comments made while it was a draft.'),
        $xaction);
    }

    return $errors;
  }

  public function getIcon() {
    return 'fa-eraser';
  }

  public function getColor() {
    return 'grey';
  }

  public function getTitle() {
    if ($this->getNewValue()) {
      return pht(
        '%s set this revision to discard draft comments when it publishes.',
        $this->renderAuthor());
    } else {
      return pht(
        '%s set this revision to keep draft comments when it publishes.',
        $this->renderAuthor());
    }
  }

  public function getTitleForFeed() {
    if ($this->getNewValue()) {
      return pht(
        '%s set %s to discard draft comments when it publishes.',
        $this->renderAuthor(),
        $this->renderObject());
    } else {
      return pht(
        '%s set %s to keep draft comments when it publishes.',
        $this->renderAuthor(),
        $this->renderObject());
    }
  }

  public function getTransactionTypeForConduit($xaction) {
    return 'discard-comments';
  }

  public function getFieldValuesForConduit($xaction, $data) {
    return array(
      'old' => $xaction->getOldValue(),
      'new' => $xaction->getNewValue(),
    );
  }

}
