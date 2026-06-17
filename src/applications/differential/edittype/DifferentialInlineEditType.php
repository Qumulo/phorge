<?php

final class DifferentialInlineEditType extends PhabricatorEditType {

  protected function newConduitParameterType() {
    return new ConduitWildParameterType();
  }

  public function generateTransactions(
    PhabricatorApplicationTransaction $template,
    array $spec) {

    $viewer = $this->getEditField()->getViewer();
    $value = idx($spec, 'value');
    if (!is_array($value)) {
      throw new Exception(
        pht('Inline comment transaction value must be a map.'));
    }

    $comment = $template->getApplicationTransactionCommentObject()
      ->setContent((string)idx($value, 'content'));

    $reply_phid = idx($value, 'replyToCommentPHID');
    if (strlen($reply_phid)) {
      // A reply inherits its location from the comment it answers, so the
      // caller supplies only the parent and the content.
      $parent = id(new DifferentialDiffInlineCommentQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($reply_phid))
        ->executeOne();
      if (!$parent) {
        throw new Exception(
          pht(
            'Inline comment "%s" does not exist or is not visible.',
            $reply_phid));
      }

      self::applyReplyLocation($comment, $parent)
        ->setReplyToCommentPHID($parent->getPHID());
    } else {
      $changeset = self::getChangesetForPath(
        $this->loadChangesets($viewer, $value),
        idx($value, 'path'));

      $comment
        ->setChangesetID($changeset->getID())
        ->setLineNumber((int)idx($value, 'line', 0))
        ->setLineLength((int)idx($value, 'length', 0))
        ->setIsNewFile((int)idx($value, 'isNewFile', 0));
    }

    $xaction = $this->newTransaction($template)
      ->attachComment($comment);

    return array($xaction);
  }

  private function loadChangesets(PhabricatorUser $viewer, array $value) {
    $diff_phid = idx($value, 'diffPHID');
    if (!strlen($diff_phid)) {
      throw new Exception(
        pht('Inline comment transaction requires a "diffPHID".'));
    }

    $diff = id(new DifferentialDiffQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($diff_phid))
      ->executeOne();
    if (!$diff) {
      throw new Exception(
        pht('Diff "%s" does not exist or is not visible.', $diff_phid));
    }

    return id(new DifferentialChangeset())->loadAllWhere(
      'diffID = %d',
      $diff->getID());
  }

  public static function applyReplyLocation(
    DifferentialTransactionComment $comment,
    DifferentialTransactionComment $parent) {

    return $comment
      ->setChangesetID($parent->getChangesetID())
      ->setLineNumber($parent->getLineNumber())
      ->setLineLength($parent->getLineLength())
      ->setIsNewFile($parent->getIsNewFile());
  }

  public static function getChangesetForPath(array $changesets, $path) {
    foreach ($changesets as $changeset) {
      if ($changeset->getFilename() === $path) {
        return $changeset;
      }
    }

    throw new Exception(
      pht('Diff has no changeset for path "%s".', $path));
  }

}
