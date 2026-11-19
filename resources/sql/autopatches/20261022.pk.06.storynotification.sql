ALTER TABLE {$NAMESPACE}_feed.feed_storynotification
  DROP KEY userPHID,
  ADD PRIMARY KEY (userPHID, chronologicalKey);
