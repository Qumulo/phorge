<?php

/**
 * Shows the patch stacks awaiting the viewer's review, collapsed so that only
 * the first revision in each stack which still needs the viewer's review is
 * listed.
 *
 * Qumulo strings patches together with Differential's stack dependencies
 * ("Depends On"). A stock "Needs Review" query panel lists every revision in
 * every stack, which buries the one patch at the bottom of each stack that is
 * actually reviewable right now. This panel groups revisions into stacks and
 * shows only the bottom-most revision of each stack which the viewer has not
 * reviewed yet.
 */
final class QumuloReviewStacksPanelType
  extends PhabricatorDashboardPanelType {

  // Ignore revisions nobody has touched in this long; they are not a live
  // part of anyone's review queue.
  const STALE_DAYS = 30;

  // Bounds on the dependency graph walk. Stacks are normally small; these
  // exist so a pathological dependency graph can not stall the dashboard.
  const MAX_WALK_HOPS = 25;
  const MAX_GRAPH_SIZE = 500;

  public function getPanelTypeKey() {
    return 'qumulo.review-stacks';
  }

  public function getPanelTypeName() {
    return pht('Review Stacks (Qumulo)');
  }

  public function getIcon() {
    return 'fa-align-left';
  }

  public function getPanelTypeDescription() {
    return pht(
      'Show patch stacks waiting on your review, collapsed to the first '.
      'revision in each stack that you have not reviewed yet.');
  }

  protected function newEditEngineFields(PhabricatorDashboardPanel $panel) {
    // This panel has nothing to configure: it always shows the stacks waiting
    // on the viewer.
    return array();
  }

  public function renderPanelContent(
    PhabricatorUser $viewer,
    PhabricatorDashboardPanel $panel,
    PhabricatorDashboardPanelRenderingEngine $engine) {

    $stacks = $this->newStacks($viewer);

    $list = $this->newStackList($viewer, $stacks);

    return id(new PhabricatorApplicationSearchResultView())
      ->setObjectList($list)
      ->setNoDataString(
        pht('No patch stacks are waiting on your review.'));
  }

  public function adjustPanelHeader(
    PhabricatorUser $viewer,
    PhabricatorDashboardPanel $panel,
    PhabricatorDashboardPanelRenderingEngine $engine,
    PHUIHeaderView $header) {

    $uri = urisprintf(
      '/differential/?responsiblePHIDs=%s&statuses=%s&bucket=%s#R',
      $viewer->getPHID(),
      DifferentialRevisionStatus::NEEDS_REVIEW,
      DifferentialRevisionRequiredActionResultBucket::BUCKETKEY);

    $button = id(new PHUIButtonView())
      ->setTag('a')
      ->setText(pht('View All'))
      ->setIcon('fa-search')
      ->setHref($uri)
      ->setColor(PHUIButtonView::GREY);

    $header->addActionLink($button);

    return $header;
  }


/* -(  Building Stacks  )---------------------------------------------------- */


  /**
   * Find the stacks which are waiting on the viewer.
   *
   * Returns a list of dictionaries, each describing one stack, ordered so the
   * most recently updated stack comes first:
   *
   *   - `revision`: the first revision in the stack the viewer has not
   *     reviewed, which is the one they should look at now.
   *   - `position`: 1-based position of that revision within the stack.
   *   - `size`: total number of revisions in the stack.
   *   - `pending`: how many other revisions in the stack are also waiting on
   *     the viewer, and are hidden behind this one.
   *
   * @return list<map<string, wild>>
   */
  private function newStacks(PhabricatorUser $viewer) {
    $viewer_phid = $viewer->getPHID();
    if (!$viewer_phid) {
      return array();
    }

    // A revision can name the viewer as a reviewer directly, or reach them
    // through one of their teams or owners packages. Expanding the viewer this
    // way is what "viewer()" does in a saved query, so this panel sees the same
    // revisions as the query panel it replaces.
    $responsible_phids = DifferentialResponsibleDatasource
      ::expandResponsibleUsers($viewer, array($viewer_phid));
    $responsible_phids = array_fuse($responsible_phids);

    $window = PhabricatorTime::getNow() -
      (self::STALE_DAYS * phutil_units('1 day in seconds'));

    // Revisions where the viewer is a reviewer (not the author) which are
    // still open for review. This mirrors the constraints on the "Needs
    // Review" query panel this panel replaces.
    $seed_revisions = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withResponsibleUsers(array_keys($responsible_phids))
      ->withoutAuthors(array($viewer_phid))
      ->withStatuses(array(DifferentialRevisionStatus::NEEDS_REVIEW))
      ->withUpdatedEpochBetween($window, null)
      ->needReviewers(true)
      ->execute();

    $seed_revisions = mpull($seed_revisions, null, 'getPHID');

    // How each revision reaches the viewer: named individually, or only
    // through a team. Naming someone directly is a stronger signal that the
    // review is really theirs, so those stacks rank first.
    $reviewer_paths = array();
    foreach ($seed_revisions as $phid => $revision) {
      $path = $this->getReviewPath(
        $revision,
        $viewer_phid,
        $responsible_phids);

      if ($path === null) {
        unset($seed_revisions[$phid]);
        continue;
      }

      $reviewer_paths[$phid] = $path;
    }

    if (!$seed_revisions) {
      return array();
    }

    list($parent_map, $graph_phids) = $this->walkStackGraph(
      array_keys($seed_revisions));

    // Load every revision in the graph, including closed ones: a landed parent
    // still occupies a position in the stack. Revisions the viewer can not see
    // are dropped here, which splits the stack around them.
    $revisions = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withPHIDs($graph_phids)
      ->needReviewers(true)
      ->needDrafts(true)
      ->execute();
    $revisions = mpull($revisions, null, 'getPHID');

    // Restrict the graph to revisions which actually loaded.
    $edges = array();
    foreach ($parent_map as $child_phid => $parent_phids) {
      if (!isset($revisions[$child_phid])) {
        continue;
      }
      foreach ($parent_phids as $parent_phid) {
        if (!isset($revisions[$parent_phid])) {
          continue;
        }
        $edges[$child_phid][$parent_phid] = $parent_phid;
      }
    }

    $components = $this->newComponents(array_keys($revisions), $edges);
    $depths = $this->newDepths(array_keys($revisions), $edges);

    $stacks = array();
    foreach ($components as $component) {
      // Order the stack bottom-first: shallower revisions depend on nothing in
      // the stack, so they are reviewable first. Ties break by revision ID so
      // ordering is stable and reads like the stack was built.
      $order = array();
      foreach ($component as $phid) {
        $order[$phid] = sprintf(
          '%08d%012d',
          idx($depths, $phid, 0),
          $revisions[$phid]->getID());
      }
      asort($order);

      $pending = array();
      foreach ($order as $phid => $key) {
        if (isset($seed_revisions[$phid])) {
          $pending[] = $phid;
        }
      }

      if (!$pending) {
        continue;
      }

      $target_phid = head($pending);
      $target = $revisions[$target_phid];

      $position = 1;
      foreach ($order as $phid => $key) {
        if ($phid === $target_phid) {
          break;
        }
        $position++;
      }

      $path = $reviewer_paths[$target_phid];

      $stacks[] = array(
        'revision' => $target,
        'position' => $position,
        'size' => count($order),
        'pending' => (count($pending) - 1),
        'is_direct' => $path['is_direct'],
        'team_phids' => $path['team_phids'],
      );
    }

    // Stacks which name the viewer as a reviewer directly come first; stacks
    // which only reach them through a team follow. Within each group, the most
    // recently updated stack is first.
    usort($stacks, array($this, 'compareStacks'));

    return $stacks;
  }

  /**
   * Order stacks for display: personally-named stacks ahead of team ones, then
   * most recently updated first.
   */
  private function compareStacks(array $u, array $v) {
    if ($u['is_direct'] !== $v['is_direct']) {
      return $u['is_direct'] ? -1 : 1;
    }

    return ($v['revision']->getDateModified() -
      $u['revision']->getDateModified());
  }

  /**
   * How, if at all, does this revision still need the viewer's review?
   *
   * Returns null if the review is not outstanding for the viewer. Otherwise,
   * returns a map describing how the revision reaches them:
   *
   *   - `is_direct`: true if the viewer is named as a reviewer personally.
   *   - `team_phids`: teams and packages which are named as reviewers and
   *     which reach the viewer.
   *
   * @return map<string, wild>|null
   */
  private function getReviewPath(
    DifferentialRevision $revision,
    $viewer_phid,
    array $responsible_phids) {

    if (!$revision->isNeedsReview()) {
      return null;
    }

    if ($revision->getAuthorPHID() === $viewer_phid) {
      return null;
    }

    $is_direct = false;
    $team_phids = array();

    foreach ($revision->getReviewers() as $reviewer) {
      $reviewer_phid = $reviewer->getReviewerPHID();

      if (!isset($responsible_phids[$reviewer_phid])) {
        continue;
      }

      if (!$this->isOutstanding($reviewer)) {
        continue;
      }

      if ($reviewer_phid === $viewer_phid) {
        $is_direct = true;
      } else {
        $team_phids[$reviewer_phid] = $reviewer_phid;
      }
    }

    if (!$is_direct && !$team_phids) {
      return null;
    }

    return array(
      'is_direct' => $is_direct,
      'team_phids' => array_keys($team_phids),
    );
  }

  /**
   * Is this reviewer still expected to act on the revision?
   */
  private function isOutstanding(DifferentialReviewer $reviewer) {
    switch ($reviewer->getReviewerStatus()) {
      case DifferentialReviewerStatus::STATUS_ADDED:
      case DifferentialReviewerStatus::STATUS_COMMENTED:
      case DifferentialReviewerStatus::STATUS_BLOCKING:
      case DifferentialReviewerStatus::STATUS_ACCEPTED_OLDER:
      case DifferentialReviewerStatus::STATUS_REJECTED_OLDER:
        return true;
      case DifferentialReviewerStatus::STATUS_ACCEPTED:
        // "Request Review" voids an acceptance, which puts the revision back
        // in front of the reviewer.
        return (bool)$reviewer->getVoidedPHID();
      default:
        // Already rejected, or resigned: the ball is not in our court.
        return false;
    }
  }

  /**
   * Walk stack dependencies outward from a set of revisions.
   *
   * Returns a `list($parent_map, $all_phids)` pair, where `$parent_map` maps a
   * revision PHID to the PHIDs of the revisions it depends on.
   */
  private function walkStackGraph(array $seed_phids) {
    $parent_edge = DifferentialRevisionDependsOnRevisionEdgeType::EDGECONST;
    $child_edge = DifferentialRevisionDependedOnByRevisionEdgeType::EDGECONST;

    $seen = array_fuse($seed_phids);
    $frontier = $seed_phids;
    $parent_map = array();

    for ($hop = 0; $hop < self::MAX_WALK_HOPS; $hop++) {
      if (!$frontier) {
        break;
      }

      if (count($seen) >= self::MAX_GRAPH_SIZE) {
        break;
      }

      $query = id(new PhabricatorEdgeQuery())
        ->withSourcePHIDs($frontier)
        ->withEdgeTypes(array($parent_edge, $child_edge));
      $query->execute();

      $next = array();
      foreach ($frontier as $phid) {
        $parents = $query->getDestinationPHIDs(
          array($phid),
          array($parent_edge));
        foreach ($parents as $parent_phid) {
          $parent_map[$phid][$parent_phid] = $parent_phid;
          if (!isset($seen[$parent_phid])) {
            $seen[$parent_phid] = $parent_phid;
            $next[] = $parent_phid;
          }
        }

        $children = $query->getDestinationPHIDs(
          array($phid),
          array($child_edge));
        foreach ($children as $child_phid) {
          $parent_map[$child_phid][$phid] = $phid;
          if (!isset($seen[$child_phid])) {
            $seen[$child_phid] = $child_phid;
            $next[] = $child_phid;
          }
        }
      }

      $frontier = $next;
    }

    return array($parent_map, array_keys($seen));
  }

  /**
   * Group revisions into connected components: one component per stack.
   *
   * @return list<list<string>>
   */
  private function newComponents(array $phids, array $edges) {
    $adjacent = array();
    foreach ($edges as $child_phid => $parent_phids) {
      foreach ($parent_phids as $parent_phid) {
        $adjacent[$child_phid][$parent_phid] = $parent_phid;
        $adjacent[$parent_phid][$child_phid] = $child_phid;
      }
    }

    $seen = array();
    $components = array();
    foreach ($phids as $phid) {
      if (isset($seen[$phid])) {
        continue;
      }

      $component = array();
      $stack = array($phid);
      $seen[$phid] = true;

      while ($stack) {
        $cursor = array_pop($stack);
        $component[] = $cursor;

        foreach (idx($adjacent, $cursor, array()) as $next_phid) {
          if (isset($seen[$next_phid])) {
            continue;
          }
          $seen[$next_phid] = true;
          $stack[] = $next_phid;
        }
      }

      $components[] = $component;
    }

    return $components;
  }

  /**
   * Compute each revision's depth: the length of the longest chain of
   * dependencies beneath it. Depth 0 means nothing in the stack blocks it.
   *
   * @return map<string, int>
   */
  private function newDepths(array $phids, array $edges) {
    $depths = array();

    foreach ($phids as $phid) {
      $this->computeDepth($phid, $edges, $depths, array());
    }

    return $depths;
  }

  private function computeDepth($phid, array $edges, array &$depths, array $path) {
    if (isset($depths[$phid])) {
      return $depths[$phid];
    }

    // Dependency cycles are prevented when edges are written, but guard
    // against them anyway rather than recursing forever.
    if (isset($path[$phid])) {
      return 0;
    }
    $path[$phid] = true;

    $depth = 0;
    foreach (idx($edges, $phid, array()) as $parent_phid) {
      $parent_depth = $this->computeDepth($parent_phid, $edges, $depths, $path);
      $depth = max($depth, $parent_depth + 1);
    }

    $depths[$phid] = $depth;

    return $depth;
  }


/* -(  Rendering  )---------------------------------------------------------- */


  /**
   * Render the chosen revisions.
   *
   * This hands off to @{class:DifferentialRevisionListView}, the same view
   * every other revision list in the install uses, so rows here are built
   * identically to rows anywhere else. The only thing this panel changes is
   * *which* revisions are in the list.
   */
  private function newStackList(PhabricatorUser $viewer, array $stacks) {
    $revisions = array();
    foreach ($stacks as $stack) {
      $revisions[] = $stack['revision'];
    }

    $view = id(new DifferentialRevisionListView())
      ->setViewer($viewer)
      ->setRevisions($revisions)
      ->setCustomFieldLists($this->loadListCustomFields($viewer, $revisions))
      ->setNoBox(true);

    return $view->render();
  }

  /**
   * Load the custom fields the install renders on revision list items.
   *
   * @return map<string, PhabricatorCustomFieldList>
   */
  private function loadListCustomFields(
    PhabricatorUser $viewer,
    array $revisions) {

    $role = PhabricatorCustomField::ROLE_LIST;

    $query = new PhabricatorCustomFieldStorageQuery();
    $lists = array();

    foreach ($revisions as $revision) {
      $field_list = PhabricatorCustomField::getObjectFields($revision, $role);
      $field_list->readFieldsFromObject($revision);
      foreach ($field_list->getFields() as $field) {
        $field->setViewer($viewer);
      }
      $lists[$revision->getPHID()] = $field_list;
      $query->addFields($field_list->getFields());
    }

    $query->execute();

    return $lists;
  }

}
root@phorge-dev:/phorge/phorge# cat src/extensions/QumuloReviewStacksPanelType.php
<?php

/**
 * Shows the patch stacks awaiting the viewer's review, collapsed so that only
 * the first revision in each stack which still needs the viewer's review is
 * listed.
 *
 * Qumulo strings patches together with Differential's stack dependencies
 * ("Depends On"). A stock "Needs Review" query panel lists every revision in
 * every stack, which buries the one patch at the bottom of each stack that is
 * actually reviewable right now. This panel groups revisions into stacks and
 * shows only the bottom-most revision of each stack which the viewer has not
 * reviewed yet.
 */
final class QumuloReviewStacksPanelType
  extends PhabricatorDashboardPanelType {

  // Ignore revisions nobody has touched in this long; they are not a live
  // part of anyone's review queue.
  const STALE_DAYS = 30;

  // Bounds on the dependency graph walk. Stacks are normally small; these
  // exist so a pathological dependency graph can not stall the dashboard.
  const MAX_WALK_HOPS = 25;
  const MAX_GRAPH_SIZE = 500;

  public function getPanelTypeKey() {
    return 'qumulo.review-stacks';
  }

  public function getPanelTypeName() {
    return pht('Review Stacks (Qumulo)');
  }

  public function getIcon() {
    return 'fa-align-left';
  }


  public function getPanelTypeDescription() {
    return pht(
      'Show patch stacks waiting on your review, collapsed to the first '.
      'revision in each stack that you have not reviewed yet.');
  }

  protected function newEditEngineFields(PhabricatorDashboardPanel $panel) {
    // This panel has nothing to configure: it always shows the stacks waiting
    // on the viewer.
    return array();
  }

  public function renderPanelContent(
    PhabricatorUser $viewer,
    PhabricatorDashboardPanel $panel,
    PhabricatorDashboardPanelRenderingEngine $engine) {

    $stacks = $this->newStacks($viewer);

    $list = $this->newStackList($viewer, $stacks);

    return id(new PhabricatorApplicationSearchResultView())
      ->setObjectList($list)
      ->setNoDataString(
        pht('No patch stacks are waiting on your review.'));
  }

  public function adjustPanelHeader(
    PhabricatorUser $viewer,
    PhabricatorDashboardPanel $panel,
    PhabricatorDashboardPanelRenderingEngine $engine,
    PHUIHeaderView $header) {

    $uri = urisprintf(
      '/differential/?responsiblePHIDs=%s&statuses=%s&bucket=%s#R',
      $viewer->getPHID(),
      DifferentialRevisionStatus::NEEDS_REVIEW,
      DifferentialRevisionRequiredActionResultBucket::BUCKETKEY);

    $button = id(new PHUIButtonView())
      ->setTag('a')
      ->setText(pht('View All'))
      ->setIcon('fa-search')
      ->setHref($uri)
      ->setColor(PHUIButtonView::GREY);

    $header->addActionLink($button);

    return $header;
  }


/* -(  Building Stacks  )---------------------------------------------------- */


  /**
   * Find the stacks which are waiting on the viewer.
   *
   * Returns a list of dictionaries, each describing one stack, ordered so the
   * most recently updated stack comes first:
   *
   *   - `revision`: the first revision in the stack the viewer has not
   *     reviewed, which is the one they should look at now.
   *   - `position`: 1-based position of that revision within the stack.
   *   - `size`: total number of revisions in the stack.
   *   - `pending`: how many other revisions in the stack are also waiting on
   *     the viewer, and are hidden behind this one.
   *
   * @return list<map<string, wild>>
   */
  private function newStacks(PhabricatorUser $viewer) {
    $viewer_phid = $viewer->getPHID();
    if (!$viewer_phid) {
      return array();
    }

    // A revision can name the viewer as a reviewer directly, or reach them
    // through one of their teams or owners packages. Expanding the viewer this
    // way is what "viewer()" does in a saved query, so this panel sees the same
    // revisions as the query panel it replaces.
    $responsible_phids = DifferentialResponsibleDatasource
      ::expandResponsibleUsers($viewer, array($viewer_phid));
    $responsible_phids = array_fuse($responsible_phids);

    $window = PhabricatorTime::getNow() -
      (self::STALE_DAYS * phutil_units('1 day in seconds'));

    // Revisions where the viewer is a reviewer (not the author) which are
    // still open for review. This mirrors the constraints on the "Needs
    // Review" query panel this panel replaces.
    $seed_revisions = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withResponsibleUsers(array_keys($responsible_phids))
      ->withoutAuthors(array($viewer_phid))
      ->withStatuses(array(DifferentialRevisionStatus::NEEDS_REVIEW))
      ->withUpdatedEpochBetween($window, null)
      ->needReviewers(true)
      ->execute();

    $seed_revisions = mpull($seed_revisions, null, 'getPHID');

    // How each revision reaches the viewer: named individually, or only
    // through a team. Naming someone directly is a stronger signal that the
    // review is really theirs, so those stacks rank first.
    $reviewer_paths = array();
    foreach ($seed_revisions as $phid => $revision) {
      $path = $this->getReviewPath(
        $revision,
        $viewer_phid,
        $responsible_phids);

      if ($path === null) {
        unset($seed_revisions[$phid]);
        continue;
      }

      $reviewer_paths[$phid] = $path;
    }

    if (!$seed_revisions) {
      return array();
    }

    list($parent_map, $graph_phids) = $this->walkStackGraph(
      array_keys($seed_revisions));

    // Load every revision in the graph, including closed ones: a landed parent
    // still occupies a position in the stack. Revisions the viewer can not see
    // are dropped here, which splits the stack around them.
    $revisions = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withPHIDs($graph_phids)
      ->needReviewers(true)
      ->needDrafts(true)
      ->execute();
    $revisions = mpull($revisions, null, 'getPHID');

    // Restrict the graph to revisions which actually loaded.
    $edges = array();
    foreach ($parent_map as $child_phid => $parent_phids) {
      if (!isset($revisions[$child_phid])) {
        continue;
      }
      foreach ($parent_phids as $parent_phid) {
        if (!isset($revisions[$parent_phid])) {
          continue;
        }
        $edges[$child_phid][$parent_phid] = $parent_phid;
      }
    }

    $components = $this->newComponents(array_keys($revisions), $edges);
    $depths = $this->newDepths(array_keys($revisions), $edges);

    $stacks = array();
    foreach ($components as $component) {
      // Order the stack bottom-first: shallower revisions depend on nothing in
      // the stack, so they are reviewable first. Ties break by revision ID so
      // ordering is stable and reads like the stack was built.
      $order = array();
      foreach ($component as $phid) {
        $order[$phid] = sprintf(
          '%08d%012d',
          idx($depths, $phid, 0),
          $revisions[$phid]->getID());
      }
      asort($order);

      $pending = array();
      foreach ($order as $phid => $key) {
        if (isset($seed_revisions[$phid])) {
          $pending[] = $phid;
        }
      }

      if (!$pending) {
        continue;
      }

      $target_phid = head($pending);
      $target = $revisions[$target_phid];

      $position = 1;
      foreach ($order as $phid => $key) {
        if ($phid === $target_phid) {
          break;
        }
        $position++;
      }

      $path = $reviewer_paths[$target_phid];

      $stacks[] = array(
        'revision' => $target,
        'position' => $position,
        'size' => count($order),
        'pending' => (count($pending) - 1),
        'is_direct' => $path['is_direct'],
        'team_phids' => $path['team_phids'],
      );
    }

    // Stacks which name the viewer as a reviewer directly come first; stacks
    // which only reach them through a team follow. Within each group, the most
    // recently updated stack is first.
    usort($stacks, array($this, 'compareStacks'));

    return $stacks;
  }

  /**
   * Order stacks for display: personally-named stacks ahead of team ones, then
   * most recently updated first.
   */
  private function compareStacks(array $u, array $v) {
    if ($u['is_direct'] !== $v['is_direct']) {
      return $u['is_direct'] ? -1 : 1;
    }

    return ($v['revision']->getDateModified() -
      $u['revision']->getDateModified());
  }

  /**
   * How, if at all, does this revision still need the viewer's review?
   *
   * Returns null if the review is not outstanding for the viewer. Otherwise,
   * returns a map describing how the revision reaches them:
   *
   *   - `is_direct`: true if the viewer is named as a reviewer personally.
   *   - `team_phids`: teams and packages which are named as reviewers and
   *     which reach the viewer.
   *
   * @return map<string, wild>|null
   */
  private function getReviewPath(
    DifferentialRevision $revision,
    $viewer_phid,
    array $responsible_phids) {

    if (!$revision->isNeedsReview()) {
      return null;
    }

    if ($revision->getAuthorPHID() === $viewer_phid) {
      return null;
    }

    $is_direct = false;
    $team_phids = array();

    foreach ($revision->getReviewers() as $reviewer) {
      $reviewer_phid = $reviewer->getReviewerPHID();

      if (!isset($responsible_phids[$reviewer_phid])) {
        continue;
      }

      if (!$this->isOutstanding($reviewer)) {
        continue;
      }

      if ($reviewer_phid === $viewer_phid) {
        $is_direct = true;
      } else {
        $team_phids[$reviewer_phid] = $reviewer_phid;
      }
    }

    if (!$is_direct && !$team_phids) {
      return null;
    }

    return array(
      'is_direct' => $is_direct,
      'team_phids' => array_keys($team_phids),
    );
  }

  /**
   * Is this reviewer still expected to act on the revision?
   */
  private function isOutstanding(DifferentialReviewer $reviewer) {
    switch ($reviewer->getReviewerStatus()) {
      case DifferentialReviewerStatus::STATUS_ADDED:
      case DifferentialReviewerStatus::STATUS_COMMENTED:
      case DifferentialReviewerStatus::STATUS_BLOCKING:
      case DifferentialReviewerStatus::STATUS_ACCEPTED_OLDER:
      case DifferentialReviewerStatus::STATUS_REJECTED_OLDER:
        return true;
      case DifferentialReviewerStatus::STATUS_ACCEPTED:
        // "Request Review" voids an acceptance, which puts the revision back
        // in front of the reviewer.
        return (bool)$reviewer->getVoidedPHID();
      default:
        // Already rejected, or resigned: the ball is not in our court.
        return false;
    }
  }

  /**
   * Walk stack dependencies outward from a set of revisions.
   *
   * Returns a `list($parent_map, $all_phids)` pair, where `$parent_map` maps a
   * revision PHID to the PHIDs of the revisions it depends on.
   */
  private function walkStackGraph(array $seed_phids) {
    $parent_edge = DifferentialRevisionDependsOnRevisionEdgeType::EDGECONST;
    $child_edge = DifferentialRevisionDependedOnByRevisionEdgeType::EDGECONST;

    $seen = array_fuse($seed_phids);
    $frontier = $seed_phids;
    $parent_map = array();

    for ($hop = 0; $hop < self::MAX_WALK_HOPS; $hop++) {
      if (!$frontier) {
        break;
      }

      if (count($seen) >= self::MAX_GRAPH_SIZE) {
        break;
      }

      $query = id(new PhabricatorEdgeQuery())
        ->withSourcePHIDs($frontier)
        ->withEdgeTypes(array($parent_edge, $child_edge));
      $query->execute();

      $next = array();
      foreach ($frontier as $phid) {
        $parents = $query->getDestinationPHIDs(
          array($phid),
          array($parent_edge));
        foreach ($parents as $parent_phid) {
          $parent_map[$phid][$parent_phid] = $parent_phid;
          if (!isset($seen[$parent_phid])) {
            $seen[$parent_phid] = $parent_phid;
            $next[] = $parent_phid;
          }
        }

        $children = $query->getDestinationPHIDs(
          array($phid),
          array($child_edge));
        foreach ($children as $child_phid) {
          $parent_map[$child_phid][$phid] = $phid;
          if (!isset($seen[$child_phid])) {
            $seen[$child_phid] = $child_phid;
            $next[] = $child_phid;
          }
        }
      }

      $frontier = $next;
    }

    return array($parent_map, array_keys($seen));
  }

  /**
   * Group revisions into connected components: one component per stack.
   *
   * @return list<list<string>>
   */
  private function newComponents(array $phids, array $edges) {
    $adjacent = array();
    foreach ($edges as $child_phid => $parent_phids) {
      foreach ($parent_phids as $parent_phid) {
        $adjacent[$child_phid][$parent_phid] = $parent_phid;
        $adjacent[$parent_phid][$child_phid] = $child_phid;
      }
    }

    $seen = array();
    $components = array();
    foreach ($phids as $phid) {
      if (isset($seen[$phid])) {
        continue;
      }

      $component = array();
      $stack = array($phid);
      $seen[$phid] = true;

      while ($stack) {
        $cursor = array_pop($stack);
        $component[] = $cursor;

        foreach (idx($adjacent, $cursor, array()) as $next_phid) {
          if (isset($seen[$next_phid])) {
            continue;
          }
          $seen[$next_phid] = true;
          $stack[] = $next_phid;
        }
      }

      $components[] = $component;
    }

    return $components;
  }

  /**
   * Compute each revision's depth: the length of the longest chain of
   * dependencies beneath it. Depth 0 means nothing in the stack blocks it.
   *
   * @return map<string, int>
   */
  private function newDepths(array $phids, array $edges) {
    $depths = array();

    foreach ($phids as $phid) {
      $this->computeDepth($phid, $edges, $depths, array());
    }

    return $depths;
  }

  private function computeDepth($phid, array $edges, array &$depths, array $path) {
    if (isset($depths[$phid])) {
      return $depths[$phid];
    }

    // Dependency cycles are prevented when edges are written, but guard
    // against them anyway rather than recursing forever.
    if (isset($path[$phid])) {
      return 0;
    }
    $path[$phid] = true;

    $depth = 0;
    foreach (idx($edges, $phid, array()) as $parent_phid) {
      $parent_depth = $this->computeDepth($parent_phid, $edges, $depths, $path);
      $depth = max($depth, $parent_depth + 1);
    }

    $depths[$phid] = $depth;

    return $depth;
  }


/* -(  Rendering  )---------------------------------------------------------- */


  /**
   * Render the chosen revisions.
   *
   * This hands off to @{class:DifferentialRevisionListView}, the same view
   * every other revision list in the install uses, so rows here are built
   * identically to rows anywhere else. The only thing this panel changes is
   * *which* revisions are in the list.
   */
  private function newStackList(PhabricatorUser $viewer, array $stacks) {
    $revisions = array();
    foreach ($stacks as $stack) {
      $revisions[] = $stack['revision'];
    }

    $view = id(new DifferentialRevisionListView())
      ->setViewer($viewer)
      ->setRevisions($revisions)
      ->setCustomFieldLists($this->loadListCustomFields($viewer, $revisions))
      ->setNoBox(true);

    return $view->render();
  }

  /**
   * Load the custom fields the install renders on revision list items.
   *
   * @return map<string, PhabricatorCustomFieldList>
   */
  private function loadListCustomFields(
    PhabricatorUser $viewer,
    array $revisions) {

    $role = PhabricatorCustomField::ROLE_LIST;

    $query = new PhabricatorCustomFieldStorageQuery();
    $lists = array();

    foreach ($revisions as $revision) {
      $field_list = PhabricatorCustomField::getObjectFields($revision, $role);
      $field_list->readFieldsFromObject($revision);
      foreach ($field_list->getFields() as $field) {
        $field->setViewer($viewer);
      }
      $lists[$revision->getPHID()] = $field_list;
      $query->addFields($field_list->getFields());
    }

    $query->execute();

    return $lists;
  }

}
