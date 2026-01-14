<?php

final class DifferentialRevisionGraph
  extends PhabricatorObjectGraph {

  private $compact = false;

  public function setCompact($compact) {
    $this->compact = $compact;
    return $this;
  }

  public function getCompact() {
    return $this->compact;
  }

  protected function getEdgeTypes() {
    return array(
      DifferentialRevisionDependsOnRevisionEdgeType::EDGECONST,
      DifferentialRevisionDependedOnByRevisionEdgeType::EDGECONST,
    );
  }

  protected function getParentEdgeType() {
    return DifferentialRevisionDependsOnRevisionEdgeType::EDGECONST;
  }

  protected function newQuery() {
    return new DifferentialRevisionQuery();
  }

  protected function isClosed($object) {
    return $object->isClosed();
  }

  protected function newTableRow($phid, $object, $trace) {
    $viewer = $this->getViewer();
    $is_compact = $this->getCompact();

    if ($object) {
      $status_icon = $object->getStatusIcon();
      $status_color = $object->getStatusIconColor();

      if ($is_compact) {
        // Compact: icon only, no status text
        $status = id(new PHUIIconView())
          ->setIcon($status_icon, $status_color);

        // Compact: title only, no monogram
        $link = phutil_tag(
          'a',
          array(
            'href' => $object->getURI(),
          ),
          $object->getTitle());
      } else {
        // Full: icon + status name
        $status_name = $object->getStatusDisplayName();
        $status = array(
          id(new PHUIIconView())
            ->setIcon($status_icon, $status_color),
          ' ',
          $status_name,
        );

        // Full: monogram + title
        $link = phutil_tag(
          'a',
          array(
            'href' => $object->getURI(),
          ),
          $object->getTitle());

        $link = array(
          $object->getMonogram(),
          ' ',
          $link,
        );
      }

      $author = $viewer->renderHandle($object->getAuthorPHID());
    } else {
      $status = null;
      $author = null;
      $link = $viewer->renderHandle($phid);
    }

    $link = AphrontTableView::renderSingleDisplayLine($link);

    if ($is_compact) {
      // Compact: icon, link only (no trace, no author)
      return array(
        $status,
        $link,
      );
    }

    return array(
      $trace,
      $status,
      $author,
      $link,
    );
  }

  protected function newTable(AphrontTableView $table) {
    if ($this->getCompact()) {
      return $table
        ->setHeaders(
          array(
            null,
            pht('Stack'),
          ))
        ->setColumnClasses(
          array(
            'graph-status',
            'wide pri object-link',
          ));
    }

    return $table
      ->setHeaders(
        array(
          null,
          pht('Status'),
          pht('Author'),
          pht('Revision'),
        ))
      ->setColumnClasses(
        array(
          'threads',
          'graph-status',
          null,
          'wide pri object-link',
        ));
  }

}
