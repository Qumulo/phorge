<?php

final class DifferentialInlineDoneEditTypeTestCase
  extends PhabricatorTestCase {

  public function testNewDoneStateTransactionRecordsStateChange() {
    $comment = id(new DifferentialTransactionComment())
      ->setPHID('PHID-XCMT-test')
      ->setAuthorPHID('PHID-USER-test');

    $xaction = DifferentialInlineDoneEditType::newDoneStateTransaction(
      new DifferentialTransaction(),
      $comment,
      PhabricatorInlineComment::STATE_UNDONE,
      PhabricatorInlineComment::STATE_DONE);

    $this->assertEqual(
      array('PHID-XCMT-test' => PhabricatorInlineComment::STATE_UNDONE),
      $xaction->getOldValue());
    $this->assertEqual(
      array('PHID-XCMT-test' => PhabricatorInlineComment::STATE_DONE),
      $xaction->getNewValue());
  }

}
