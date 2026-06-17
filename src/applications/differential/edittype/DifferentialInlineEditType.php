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

    $changesets = id(new DifferentialChangeset())->loadAllWhere(
      'diffID = %d',
      $diff->getID());

    $changeset = self::getChangesetForPath($changesets, idx($value, 'path'));

    $comment = $template->getApplicationTransactionCommentObject()
      ->setChangesetID($changeset->getID())
      ->setContent((string)idx($value, 'content'))
      ->setLineNumber((int)idx($value, 'line', 0))
      ->setLineLength((int)idx($value, 'length', 0))
      ->setIsNewFile((int)idx($value, 'isNewFile', 0));

    $xaction = $this->newTransaction($template)
      ->attachComment($comment);

    return array($xaction);
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
