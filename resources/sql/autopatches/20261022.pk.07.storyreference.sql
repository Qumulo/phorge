ALTER TABLE {$NAMESPACE}_feed.feed_storyreference
  DROP KEY objectPHID,
  ADD PRIMARY KEY (objectPHID, chronologicalKey);
