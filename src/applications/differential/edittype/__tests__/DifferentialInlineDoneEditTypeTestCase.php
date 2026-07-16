<?php

final class DifferentialInlineDoneEditTypeTestCase
  extends PhabricatorTestCase {

  public function testDoneStateForFlag() {
    $this->assertEqual(
      PhabricatorInlineComment::STATE_DONE,
      DifferentialInlineDoneEditType::doneStateForFlag(true));
    $this->assertEqual(
      PhabricatorInlineComment::STATE_UNDONE,
      DifferentialInlineDoneEditType::doneStateForFlag(false));
  }

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
    $this->assertTrue($xaction->getIgnoreOnNoEffect());
    $this->assertEqual(
      array('PHID-XCMT-test' => array('authorPHID' => 'PHID-USER-test')),
      $xaction->getMetadataValue('inline.details'));
  }

}
