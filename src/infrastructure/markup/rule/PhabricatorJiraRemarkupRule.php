<?php

final class PhabricatorJiraRemarkupRule
  extends PhabricatorRemarkupCustomInlineRule {

  public function getPriority() {
    // Run before the hyperlink rule (400) to catch story references first.
    return 350.0;
  }

  public function apply($text) {
    // Match story references like PREFIX-123, JIRA-42, QFS-12345, etc.
    return preg_replace_callback(
      '/\b([A-Z]+-\d+)\b/',
      array($this, 'markupStoryReference'),
      $text);
  }

  protected function markupStoryReference(array $matches) {
    $story_ref = $matches[1];
    $jira_base_url = 'https://qumulo.atlassian.net/browse/';

    if ($this->getEngine()->isTextMode()) {
      return $story_ref.' <'.$jira_base_url.$story_ref.'>';
    }

    $link = phutil_tag(
      'a',
      array(
        'href' => $jira_base_url.$story_ref,
        'target' => '_blank',
        'rel' => 'noreferrer',
      ),
      $story_ref);

    return $this->getEngine()->storeText($link);
  }

}
