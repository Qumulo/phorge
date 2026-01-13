<?php

final class DifferentialRevisionCommandeerAbandonTransaction
  extends DifferentialRevisionActionTransaction {

  const TRANSACTIONTYPE = 'differential.revision.cmdabandon';
  const ACTIONKEY = 'commandeer-abandon';

  protected function getRevisionActionLabel(
    DifferentialRevision $revision,
    PhabricatorUser $viewer) {
    return pht('Commandeer and Abandon Revision');
  }

  protected function getRevisionActionDescription(
    DifferentialRevision $revision,
    PhabricatorUser $viewer) {
    return pht('Take over this revision and abandon it.');
  }

  public function getIcon() {
    return 'fa-ban';
  }

  public function getColor() {
    return 'indigo';
  }

  protected function getRevisionActionOrder() {
    // Place after regular Commandeer (which is 700)
    return 750;
  }

  public function generateOldValue($object) {
    return array(
      'authorPHID' => $object->getAuthorPHID(),
      'status' => $object->getModernRevisionStatus(),
    );
  }

  public function generateNewValue($object, $value) {
    $actor = $this->getActor();
    return array(
      'authorPHID' => $actor->getPHID(),
      'status' => DifferentialRevisionStatus::ABANDONED,
    );
  }

  public function applyInternalEffects($object, $value) {
    $actor = $this->getActor();

    // Commandeer: change author to current user
    $object->setAuthorPHID($actor->getPHID());

    // Abandon: set status to abandoned
    $object->setModernRevisionStatus(
      DifferentialRevisionStatus::ABANDONED);
  }

  protected function validateAction($object, PhabricatorUser $viewer) {
    // Can't commandeer closed revisions (except abandoned ones which we'd reclaim)
    if ($object->isClosed() && !$object->isAbandoned()) {
      throw new Exception(
        pht('You cannot commandeer and abandon this revision because it has '.
            'already been closed.'));
    }

    // Can't commandeer your own revision (but you can just abandon it directly)
    if ($this->isViewerRevisionAuthor($object, $viewer)) {
      throw new Exception(
        pht('You cannot commandeer your own revision. Use "Abandon" instead.'));
    }
  }

  public function getTitle() {
    return pht(
      '%s commandeered and abandoned this revision.',
      $this->renderAuthor());
  }

  public function getTitleForFeed() {
    return pht(
      '%s commandeered and abandoned %s.',
      $this->renderAuthor(),
      $this->renderObject());
  }

  public function getActionName() {
    return pht('Commandeered and Abandoned');
  }

  public function getCommandKeyword() {
    return 'commandeer-abandon';
  }

  public function getCommandAliases() {
    return array();
  }

  public function getCommandSummary() {
    return pht('Take over and abandon a revision.');
  }

  public function getTransactionTypeForConduit($xaction) {
    return 'commandeer-abandon';
  }

  public function getFieldValuesForConduit($object, $data) {
    return array();
  }

}
