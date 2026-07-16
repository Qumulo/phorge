<?php

// One-off end-to-end smoke for the inline Conduit comment transactions.
// Drives the real differential.revision.edit / transaction.search code paths
// in-process against the local database. Run:
//   PATH=/usr/bin:/bin php scripts/inline_conduit_smoke.php

require_once dirname(__FILE__).'/__init_script__.php';

$failed = false;
function check($cond, $label) {
  global $failed;
  echo ($cond ? "  PASS  " : "  FAIL  ").$label."\n";
  if (!$cond) {
    $failed = true;
  }
}

function call($method, array $params, PhabricatorUser $user) {
  return id(new ConduitCall($method, $params))
    ->setUser($user)
    ->execute();
}

$omnipotent = PhabricatorUser::getOmnipotentUser();

// 1. Actor (create if needed, then load with settings cache attached).
$existing = id(new PhabricatorPeopleQuery())
  ->setViewer($omnipotent)
  ->execute();
if (!head($existing)) {
  $new_user = id(new PhabricatorUser())
    ->setUsername('smoke')
    ->setRealName('Inline Smoke');
  $email = id(new PhabricatorUserEmail())
    ->setAddress('smoke@example.com')
    ->setIsVerified(1);
  id(new PhabricatorUserEditor())
    ->setActor($omnipotent)
    ->createNewUser($new_user, $email);
}
$author = id(new PhabricatorPeopleQuery())
  ->setViewer($omnipotent)
  ->needUserSettings(true)
  ->setLimit(1)
  ->execute();
$author = head($author);
echo "Actor: ".$author->getUsername()."\n";

// 2. Raw diff (new file smoke.txt, 3 lines).
$raw_diff = <<<EODIFF
diff --git a/smoke.txt b/smoke.txt
new file mode 100644
index 0000000..1111111
--- /dev/null
+++ b/smoke.txt
@@ -0,0 +1,3 @@
+line one
+line two
+line three
EODIFF;

$diff_result = call(
  'differential.createrawdiff',
  array('diff' => $raw_diff),
  $author);
$diff_phid = $diff_result['phid'];
echo "Diff: ".$diff_phid."\n";

// 3. Revision.
$rev_result = call(
  'differential.revision.edit',
  array(
    'transactions' => array(
      array('type' => 'update', 'value' => $diff_phid),
      array('type' => 'title', 'value' => 'Inline smoke revision'),
      array('type' => 'testPlan', 'value' => 'Run the inline conduit smoke.'),
    ),
  ),
  $author);
$revision_id = $rev_result['object']['id'];
$monogram = 'D'.$revision_id;
echo "Revision: ".$monogram."\n";

function find_inlines($monogram, $author) {
  $txns = call('transaction.search', array('objectIdentifier' => $monogram),
    $author);
  $inlines = array();
  foreach ($txns['data'] as $txn) {
    if (idx($txn, 'type') === 'inline') {
      $inlines[] = $txn;
    }
  }
  return $inlines;
}

// 4. Create-and-publish an inline.
call(
  'differential.revision.edit',
  array(
    'objectIdentifier' => $monogram,
    'transactions' => array(
      array(
        'type' => 'inline',
        'value' => array(
          'path' => 'smoke.txt',
          'line' => 2,
          'content' => 'smoke inline note',
          'isNewFile' => true,
        ),
      ),
    ),
  ),
  $author);

$inlines = find_inlines($monogram, $author);
check(count($inlines) === 1, 'inline create: exactly one inline visible');
$inline = head($inlines);
$comment = head(idx($inline, 'comments', array()));
$fields = idx($inline, 'fields', array());
check(idx($comment, 'content')['raw'] === 'smoke inline note',
  'inline create: content round-trips');
check((int)idx($fields, 'line') === 2, 'inline create: line is 2');
check(idx($fields, 'path') === 'smoke.txt', 'inline create: path is smoke.txt');
check(idx($fields, 'isNewFile') === true, 'inline create: isNewFile is true');
check(idx($fields, 'replyToCommentPHID') === null,
  'inline create: not a reply');
$parent_phid = idx($comment, 'phid');

// 5. Reply (location inherited).
call(
  'differential.revision.edit',
  array(
    'objectIdentifier' => $monogram,
    'transactions' => array(
      array(
        'type' => 'inline',
        'value' => array(
          'replyToCommentPHID' => $parent_phid,
          'content' => 'smoke reply',
        ),
      ),
    ),
  ),
  $author);

$inlines = find_inlines($monogram, $author);
check(count($inlines) === 2, 'reply: two inlines now');
$reply = null;
foreach ($inlines as $candidate) {
  $c = head(idx($candidate, 'comments', array()));
  if (idx($c, 'content')['raw'] === 'smoke reply') {
    $reply = $candidate;
  }
}
check($reply !== null, 'reply: reply inline present');
if ($reply) {
  $reply_fields = idx($reply, 'fields', array());
  check(idx($reply_fields, 'replyToCommentPHID') === $parent_phid,
    'reply: threaded to parent');
  check((int)idx($reply_fields, 'line') === 2,
    'reply: inherited parent line');
}

// 6. Resolve (mark done).
call(
  'differential.revision.edit',
  array(
    'objectIdentifier' => $monogram,
    'transactions' => array(
      array(
        'type' => 'inline.done',
        'value' => array('commentPHID' => $parent_phid, 'done' => true),
      ),
    ),
  ),
  $author);

$inlines = find_inlines($monogram, $author);
$resolved = null;
foreach ($inlines as $candidate) {
  $c = head(idx($candidate, 'comments', array()));
  if (idx($c, 'phid') === $parent_phid) {
    $resolved = $candidate;
  }
}
check($resolved !== null, 'resolve: target inline still present');
if ($resolved) {
  check(idx(idx($resolved, 'fields', array()), 'isDone') === true,
    'resolve: isDone is true');
}

echo $failed ? "\nSMOKE FAILED\n" : "\nSMOKE PASSED\n";
exit($failed ? 1 : 0);
