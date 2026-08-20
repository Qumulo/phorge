<?php

final class PhabricatorDiscardDraftCommentsSetting
  extends PhabricatorSelectSetting {

  const SETTINGKEY = 'differential-discard-draft-comments';

  const VALUE_KEEP_DRAFT_COMMENTS = 'keep';
  const VALUE_DISCARD_DRAFT_COMMENTS = 'discard';

  public function getSettingName() {
    return pht('Discard Draft Comments');
  }

  protected function getSettingOrder() {
    return 400;
  }

  public function getSettingPanelKey() {
    return PhabricatorDiffPreferencesSettingsPanel::PANELKEY;
  }

  protected function getControlInstructions() {
    return pht(
      'Revisions you create can discard every comment made while they were '.
      'drafts at the moment they publish for review, so a reviewer opens a '.
      'clean page instead of reading a self-review conversation.'.
      "\n\n".
      'This chooses the starting value for new revisions only. Each revision '.
      'keeps its own setting after that, which you can change from the '.
      'action menu while it is still a draft.');
  }

  public function getSettingDefaultValue() {
    return self::VALUE_KEEP_DRAFT_COMMENTS;
  }

  protected function getSelectOptions() {
    return array(
      self::VALUE_KEEP_DRAFT_COMMENTS => pht('Keep Draft Comments'),
      self::VALUE_DISCARD_DRAFT_COMMENTS => pht('Discard On Publish'),
    );
  }

}
