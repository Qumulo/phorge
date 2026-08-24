<?php

final class DifferentialRevisionCommentsDiscardedTransaction
  extends DifferentialRevisionTransactionType {

  const TRANSACTIONTYPE = 'comments-discarded';

  public function generateOldValue($object) {
    return null;
  }

  public function generateNewValue($object, $value) {
    return (int)$value;
  }

  public function getIcon() {
    return 'fa-eraser';
  }

  public function getColor() {
    return 'grey';
  }

  public function getTitle() {
    $count = (int)$this->getNewValue();

    return pht(
      '%s discarded %s draft comment(s) when this revision published.',
      $this->renderAuthor(),
      new PhutilNumber($count));
  }

  public function getTitleForFeed() {
    $count = (int)$this->getNewValue();

    return pht(
      '%s discarded %s draft comment(s) when %s published.',
      $this->renderAuthor(),
      new PhutilNumber($count),
      $this->renderObject());
  }

  public function getTransactionTypeForConduit($xaction) {
    return 'comments-discarded';
  }

  public function getFieldValuesForConduit($xaction, $data) {
    return array(
      'count' => (int)$xaction->getNewValue(),
    );
  }

}
