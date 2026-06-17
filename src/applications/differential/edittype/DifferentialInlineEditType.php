<?php

final class DifferentialInlineEditType extends PhabricatorEditType {

  protected function newConduitParameterType() {
    return new ConduitWildParameterType();
  }

  public function generateTransactions(
    PhabricatorApplicationTransaction $template,
    array $spec) {

    $viewer = $this->getEditField()->getViewer();
    $revision = $this->getEditField()->getObject();
    $value = idx($spec, 'value');
    if (!is_array($value)) {
      throw new Exception(
        pht('Inline comment transaction value must be a map.'));
    }

    // The render and state queries filter inlines by revisionPHID, so a
    // published comment is only visible once this is set.
    $comment = $template->getApplicationTransactionCommentObject()
      ->setRevisionPHID($revision->getPHID())
      ->setContent((string)idx($value, 'content'));

    $reply_phid = (string)idx($value, 'replyToCommentPHID');
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
      if ($parent->getRevisionPHID() !== $revision->getPHID()) {
        throw new Exception(
          pht(
            'Inline comment "%s" is not on the revision being edited.',
            $reply_phid));
      }

      self::applyReplyLocation($comment, $parent)
        ->setReplyToCommentPHID($parent->getPHID())
        ->attachReplyToComment($parent);
    } else {
      $changeset = self::getChangesetForPath(
        $this->loadChangesets($viewer, $revision, $value),
        idx($value, 'path'));

      // The editor reads getReplyToComment() on apply for every inline, so a
      // fresh (non-reply) comment must attach a null parent explicitly.
      $comment
        ->setChangesetID($changeset->getID())
        ->setLineNumber((int)idx($value, 'line', 0))
        ->setLineLength((int)idx($value, 'length', 0))
        ->setIsNewFile((int)idx($value, 'isNewFile', 0))
        ->attachReplyToComment(null);
    }

    $xaction = $this->newTransaction($template)
      ->attachComment($comment);

    return array($xaction);
  }

  private function loadChangesets(
    PhabricatorUser $viewer,
    DifferentialRevision $revision,
    array $value) {

    $diff_phid = (string)idx($value, 'diffPHID');
    if (strlen($diff_phid)) {
      $diff = id(new DifferentialDiffQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($diff_phid))
        ->executeOne();
      if (!$diff) {
        throw new Exception(
          pht('Diff "%s" does not exist or is not visible.', $diff_phid));
      }
    } else {
      // Default to the revision's most recent diff so callers anchor on the
      // current code without a separate lookup.
      $diff = $revision->loadActiveDiff();
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
