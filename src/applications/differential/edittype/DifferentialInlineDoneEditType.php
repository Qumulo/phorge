<?php

final class DifferentialInlineDoneEditType extends PhabricatorEditType {

  protected function newConduitParameterType() {
    return new ConduitWildParameterType();
  }

  public function generateTransactions(
    PhabricatorApplicationTransaction $template,
    array $spec) {

    $value = idx($spec, 'value');
    if (!is_array($value)) {
      throw new Exception(
        pht('Inline "done" transaction value must be a map.'));
    }

    $comment_phid = idx($value, 'commentPHID');
    if (!strlen($comment_phid)) {
      throw new Exception(
        pht('Inline "done" transaction requires a "commentPHID".'));
    }

    $comment = id(new DifferentialTransactionComment())->loadOneWhere(
      'phid = %s',
      $comment_phid);
    if (!$comment) {
      throw new Exception(
        pht('Inline comment "%s" does not exist.', $comment_phid));
    }

    $done = (bool)idx($value, 'done', true);
    $new_state = $done
      ? PhabricatorInlineComment::STATE_DONE
      : PhabricatorInlineComment::STATE_UNDONE;
    $old_state = $comment->getFixedState();

    // The done-state lives directly on the comment; the transaction below only
    // records the change for history (mirroring newInlineStateTransaction).
    $comment->setFixedState($new_state)->save();

    $xaction = self::newDoneStateTransaction(
      $this->newTransaction($template),
      $comment,
      $old_state,
      $new_state);

    return array($xaction);
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
