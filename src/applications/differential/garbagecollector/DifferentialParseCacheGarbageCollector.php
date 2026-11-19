<?php

final class DifferentialParseCacheGarbageCollector
  extends PhabricatorGarbageCollector {

  const COLLECTORCONST = 'differential.parse';

  public function getCollectorName() {
    return pht('Differential Parse Cache');
  }

  public function getDefaultRetentionPolicy() {
    return phutil_units('14 days in seconds');
  }

  protected function collectGarbage() {
    $table = new DifferentialChangeset();
    $conn_w = $table->establishConnection('w');

    // Cache rows can each be up to DifferentialChangesetParser::CACHE_MAX_SIZE
    // (8MB), so a batch must be bounded by size, not just row count. 10 rows
    // keeps the worst case at 80MB, under transaction size limits such as
    // MySQL Group Replication's group_replication_transaction_size_limit
    // (default 150MB). A batch over the limit fails on every retry, and the
    // collector never gets past it.
    $batch_size = 10;

    queryfx(
      $conn_w,
      'DELETE FROM %T WHERE dateCreated < %d LIMIT %d',
      DifferentialChangeset::TABLE_CACHE,
      $this->getGarbageEpoch(),
      $batch_size);

    return ($conn_w->getAffectedRows() == $batch_size);
  }

}
