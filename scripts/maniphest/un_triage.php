#!/usr/bin/env php
<?php

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';

$args = new PhutilArgumentParser($argv);
$args->parseStandardArguments();

$args->setTagline('Maniphest Un-Triage\'er');
$args->setSynopsis(<<<EOHELP
**untriage** [__options__]
Changes status of old tasks to Needs Triage
EOHELP
);

$untriage = id(new PhutilArgumentWorkflow())
  ->setName("untriage")
  ->setExamples(
    "--user taskbot --last-modified 30 --cc 'someone' --cc 'someone.else'"
  )
  ->setSynopsis("Changes status of tasks over a certain age to Needs Triage.")
  ->setArguments(
    array(
      array(
        "name"    => "user",
        "param"   => "user",
        "help"    => "The username of the user to take action",
      ),
      array(
        "name"    => "last-modified",
        "param"   => "days",
        "default" => 30,
        "help"    => "Process tasks older than days __days__.",
      ),
      array(
        "name"   => "cc",
        "param"  => "name",
        "help"   => "Add __name__ as CC to updated tasks.",
        "repeat" => true,
      ),
      array(
        "name"   => "comment",
        "param"  => "comment",
        "help"   => "Add __comment__ to updated tasks.",
      )
    ));

$flow = $args->parseWorkflows(
  array(
    $untriage,
    new PhutilHelpArgumentWorkflow(),
  ));

if ($flow->getName() === "untriage") {

  $username     = $args->getArg("user");
  $lastModified = $args->getArg("last-modified");
  $ccArr        = $args->getArg("cc");
  $comment      = $args->getArg("comment");

  $console = PhutilConsole::getConsole();

  if (!$username) {
    $console->writeOut(
      "--user needs to be set so that we can take action."
    );
    exit(1);
  }

  $user = id(new PhabricatorUser())->loadOneWhere('username = %s', $username);

  $console->writeOut(
    " - Processing tasks with no modifications in %d days\n",
    $lastModified
  );

  if ($comment)
  {
    $console->writeOut(" - Adding comment `%s`\n", $comment);
  }

  // Find any user aliases' PHIDs
  $ccPhids = array();
  if ($ccArr) {
    $users = id(new ConduitCall("user.find", array("aliases" => $ccArr)))
      ->setUser($user)
      ->execute();

    if (count($users) !== count($ccArr)) {
      $response = $console->confirm(
        "Couldn't find all cc users, continue?",
        false
      );

      if(!$response) {
        $console->writeOut("Exiting due to unknown cc user\n");
        exit(1);
      }
    }

    if ($users) {
      $ccPhids = array_fuse($users);
    }
  }

  // Get all open tasks
  $tasksArgs = array(
    "status" => "status-open",
    "order" => "order-modified"
  );
  $tasks     = id(new ConduitCall("maniphest.query", $tasksArgs))
    ->setUser($user)
    ->execute();

  $taskKeys          = array();
  $thePast           = strtotime("-$lastModified days");
  $ignoredPriorities = array("Needs Triage", "Wishlist");

  if (is_array($tasks)) {
    foreach ($tasks as $taskKey => $task) {
      if ($task["dateModified"] < $thePast) {

        // There are some priorities we want to ignore
        if (in_array($task["priority"], $ignoredPriorities)) {
          continue;
        }

        // If we have some dependencies check that they're open, if one is open
        // we can skip this task
        if ($task["dependsOnTaskPHIDs"]) {
          $dependencyTasks = id(
            new ConduitCall("maniphest.query",
              array("phids" => $task["dependsOnTaskPHIDs"])))
            ->setUser($user)
            ->execute();
          if ($dependencyTasks) {
            foreach ($dependencyTasks as $dependencyTask) {
              if($dependencyTask["status"]
                == ManiphestTaskStatus::STATUS_OPEN) {
                continue 2;
              }
            }
          }
        }

        $taskKeys[$taskKey] = $taskKey;
      }

    }
  }

  if ($taskKeys) {
    foreach ($taskKeys as $taskKey) {
      $console->writeOut(
        " - Updating %s: %s\n",
        $tasks[$taskKey]["objectName"],
        $tasks[$taskKey]["title"]
      );

      $methodArgs = array(
        "phid" => $tasks[$taskKey]["phid"],
        "priority" => 90,
      );

      if ($comment) {
        $methodArgs["comments"] = $comment;
      }

      if ($ccPhids) {
        $taskCCArr             = array_fuse($tasks[$taskKey]["ccPHIDs"]);
        $newCCArr              = array_diff_key($ccPhids, $taskCCArr);
        $methodArgs["ccPHIDs"] = $taskCCArr + $newCCArr;
      }

      id(new ConduitCall("maniphest.update", $methodArgs))
        ->setUser($user)
        ->execute();

    }
  }
}

