<?php

final class DifferentialInlineEditTypeTestCase extends PhabricatorTestCase {

  public function testGetChangesetForPathMatchesByFilename() {
    $changesets = $this->newDiff()->getChangesets();

    $changeset = DifferentialInlineEditType::getChangesetForPath(
      $changesets,
      'src');

    $this->assertEqual('src', $changeset->getFilename());
  }

  public function testGetChangesetForPathThrowsWhenAbsent() {
    $changesets = $this->newDiff()->getChangesets();

    $caught = null;
    try {
      DifferentialInlineEditType::getChangesetForPath($changesets, 'nope.c');
    } catch (Throwable $ex) {
      $caught = $ex;
    }

    $this->assertTrue($caught instanceof Exception);
  }

  public function testApplyReplyLocationCopiesParentAnchor() {
    $parent = id(new DifferentialTransactionComment())
      ->setChangesetID(7)
      ->setLineNumber(42)
      ->setLineLength(3)
      ->setIsNewFile(1);

    $comment = new DifferentialTransactionComment();
    DifferentialInlineEditType::applyReplyLocation($comment, $parent);

    $this->assertEqual(7, (int)$comment->getChangesetID());
    $this->assertEqual(42, (int)$comment->getLineNumber());
    $this->assertEqual(3, (int)$comment->getLineLength());
    $this->assertEqual(1, (int)$comment->getIsNewFile());
  }

  private function newDiff() {
    $parser = new ArcanistDiffParser();
    $raw_diff = <<<EODIFF
diff --git a/src b/src
index 123457..bb216b1 100644
--- a/src
+++ b/src
@@ -1,5 +1,5 @@
 Line a
-Line b
+Line 2
 Line c
 Line d
 Line e
EODIFF;

    return DifferentialDiff::newFromRawChanges(
      PhabricatorUser::getOmnipotentUser(),
      $parser->parseDiff($raw_diff));
  }

}
