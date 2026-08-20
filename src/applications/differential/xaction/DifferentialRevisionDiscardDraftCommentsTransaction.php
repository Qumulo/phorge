<?php

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
