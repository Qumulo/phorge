<?php

final class DifferentialInlineDoneEditType extends PhabricatorEditType {

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
        pht('Inline "done" transaction value must be a map.'));
    }

    $comment_phid = (string)idx($value, 'commentPHID');
    if (!strlen($comment_phid)) {
      throw new Exception(
        pht('Inline "done" transaction requires a "commentPHID".'));
    }

    // Scope the lookup to the revision being edited and to comments the actor
    // can see, so this can't flip the state of a hidden or unrelated comment.
    $comment = id(new DifferentialDiffInlineCommentQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($comment_phid))
      ->withObjectPHIDs(array($revision->getPHID()))
      ->executeOne();
    if (!$comment) {
      throw new Exception(
        pht(
          'Inline comment "%s" does not exist on the revision being edited.',
          $comment_phid));
    }

    $new_state = self::doneStateForFlag((bool)idx($value, 'done', true));

    // The TYPE_INLINESTATE apply path writes fixedState; this transaction only
    // carries the old -> new change.
    $xaction = self::newDoneStateTransaction(
      $this->newTransaction($template),
      $comment,
      $comment->getFixedState(),
      $new_state);

    return array($xaction);
  }

  public static function doneStateForFlag($done) {
    if ($done) {
      return PhabricatorInlineComment::STATE_DONE;
    }
    return PhabricatorInlineComment::STATE_UNDONE;
  }

  public static function newDoneStateTransaction(
    PhabricatorApplicationTransaction $xaction,
    DifferentialTransactionComment $comment,
    $old_state,
    $new_state) {

    $phid = $comment->getPHID();

    return $xaction
      ->setIgnoreOnNoEffect(true)
      ->setMetadataValue(
        'inline.details',
        array($phid => array('authorPHID' => $comment->getAuthorPHID())))
      ->setOldValue(array($phid => $old_state))
      ->setNewValue(array($phid => $new_state));
  }

}
