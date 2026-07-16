<?php

final class DifferentialRevisionInlineTransactionTestCase
  extends PhabricatorTestCase {

  public function testInlineConduitExposesIsNewFile() {
    $new_file = $this->newInlineConduitFields(1);
    $old_file = $this->newInlineConduitFields(0);
    $this->assertEqual(true, idx($new_file, 'isNewFile'));
    $this->assertEqual(false, idx($old_file, 'isNewFile'));
  }

  private function newInlineConduitFields($is_new_file) {
    $diff = id(new DifferentialDiff())->setID(123);

    $changeset = id(new DifferentialChangeset())
      ->setFilename('fs/foo.c');
    $changeset->attachDiff($diff);

    $comment = id(new DifferentialTransactionComment())
      ->setChangesetID(1)
      ->setLineNumber(92)
      ->setLineLength(0)
      ->setIsNewFile($is_new_file);

    $xaction = id(new DifferentialTransaction())->attachComment($comment);

    $type = new DifferentialRevisionInlineTransaction();
    return $type->getFieldValuesForConduit($xaction, array(1 => $changeset));
  }

}
