<?php

final class DifferentialRevisionToggleDiscardCommentsTransaction
  extends DifferentialRevisionActionTransaction {

  const TRANSACTIONTYPE = 'toggle-discard-comments';
  const ACTIONKEY = 'toggle-discard-comments';

  protected function getRevisionActionLabel(
    DifferentialRevision $revision,
    PhabricatorUser $viewer) {

    if ($revision->getDiscardDraftComments()) {
      return pht('Keep Draft Comments');
    }

    return pht('Discard Draft Comments On Publish');
  }

  protected function getRevisionActionDescription(
    DifferentialRevision $revision,
    PhabricatorUser $viewer) {

    if ($revision->getDiscardDraftComments()) {
      return pht(
        'Comments made while this revision is a draft will be kept when it '.
        'publishes.');
    }

    return pht(
      'Every comment made while this revision is a draft will stop being '.
      'shown when it publishes for review. You can change this back until '.
      'the revision publishes.');
  }

  protected function validateAction($object, PhabricatorUser $viewer) {
    if ($object->getShouldBroadcast()) {
      throw new Exception(
        pht(
          'You can not change this setting because this revision has already '.
          'published, so there is no draft conversation left to discard.'));
    }

    if (!$this->isViewerRevisionAuthor($object, $viewer)) {
      throw new Exception(
        pht(
          'You can not change this setting because you are not the author of '.
          'this revision.'));
    }
  }

  public function getIcon() {
    return 'fa-eraser';
  }

  public function getColor() {
    return 'grey';
  }

  protected function getRevisionActionOrder() {
    return 250;
  }

  public function getActionName() {
    if ($this->getNewValue()) {
      return pht('Will Discard Draft Comments');
    }

    return pht('Will Keep Draft Comments');
  }

  public function generateOldValue($object) {
    return (bool)$object->getDiscardDraftComments();
  }

  /**
   * The menu item is one control for both directions, and the comment action
   * it renders as carries no value of its own, so the new value comes from the
   * revision rather than from the submit.
   */
  public function generateNewValue($object, $value) {
    return !$object->getDiscardDraftComments();
  }

  public function applyInternalEffects($object, $value) {
    $object->setDiscardDraftComments($value);
  }

  public function getTitle() {
    if ($this->getNewValue()) {
      return pht(
        '%s set this revision to discard draft comments when it publishes.',
        $this->renderAuthor());
    } else {
      return pht(
        '%s set this revision to keep draft comments when it publishes.',
        $this->renderAuthor());
    }
  }

  public function getTitleForFeed() {
    if ($this->getNewValue()) {
      return pht(
        '%s set %s to discard draft comments when it publishes.',
        $this->renderAuthor(),
        $this->renderObject());
    } else {
      return pht(
        '%s set %s to keep draft comments when it publishes.',
        $this->renderAuthor(),
        $this->renderObject());
    }
  }

}
