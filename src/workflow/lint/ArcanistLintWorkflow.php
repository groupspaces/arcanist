<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Runs lint rules on changes.
 *
 * @group workflow
 */
class ArcanistLintWorkflow extends ArcanistBaseWorkflow {

  const RESULT_OKAY       = 0;
  const RESULT_WARNINGS   = 1;
  const RESULT_ERRORS     = 2;
  const RESULT_SKIP       = 3;

  private $unresolvedMessages;
  private $shouldAmendChanges = false;

  public function setShouldAmendChanges($should_amend) {
    $this->shouldAmendChanges = $should_amend;
    return $this;
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
      **lint** [__options__] [__paths__] (svn)
      **lint** [__options__] [__commit_range__] (git)
          Supports: git, svn
          Run static analysis on changes to check for mistakes. If no files
          are specified, lint will be run on all files which have been modified.
EOTEXT
      );
  }

  public function getArguments() {
    return array(
      'lintall' => array(
        'help' =>
          "Show all lint warnings, not just those on changed lines."
      ),
      'output' => array(
        'param' => 'format',
        'help' =>
          "With 'summary', show lint warnings in a more compact format. ".
          "With 'json', show lint warnings in machine-readable JSON format."
      ),
      'advice' => array(
        'help' =>
          "Show lint advice, not just warnings and errors."
      ),
      'engine' => array(
        'param' => 'classname',
        'help' =>
          "Override configured lint engine for this project."
      ),
      'apply-patches' => array(
        'help' =>
          'Apply patches suggested by lint to the working copy without '.
          'prompting.',
        'conflicts' => array(
          'never-apply-patches' => true,
        ),
      ),
      'never-apply-patches' => array(
        'help' => 'Never apply patches suggested by lint.',
        'conflicts' => array(
          'apply-patches' => true,
        ),
      ),
      '*' => 'paths',
    );
  }

  public function requiresWorkingCopy() {
    return true;
  }

  public function run() {
    $working_copy = $this->getWorkingCopy();

    $engine = $this->getArgument('engine');
    if (!$engine) {
      $engine = $working_copy->getConfig('lint_engine');
      if (!$engine) {
        throw new ArcanistNoEngineException(
          "No lint engine configured for this project. Edit .arcconfig to ".
          "specify a lint engine.");
      }
    }

    $should_lint_all = $this->getArgument('lintall');

    $repository_api = null;
    if (!$should_lint_all) {
      try {
        $repository_api = ArcanistRepositoryAPI::newAPIFromWorkingCopyIdentity(
          $working_copy);
        $this->setRepositoryAPI($repository_api);
      } catch (ArcanistUsageException $ex) {
        throw new ArcanistUsageException(
          $ex->getMessage()."\n\n".
          "Use '--lintall' to ignore working copy changes when running lint.");
      }

      if ($repository_api instanceof ArcanistSubversionAPI) {
        $paths = $repository_api->getWorkingCopyStatus();
        $list  = new FileList($this->getArgument('paths'));
        foreach ($paths as $path => $flags) {
          if (!$list->contains($path)) {
            unset($paths[$path]);
          }
        }
      } else if ($repository_api->supportsRelativeLocalCommits()) {
        $repository_api->parseRelativeLocalCommit(
          $this->getArgument('paths', array()));
        $paths = $repository_api->getWorkingCopyStatus();
      } else {
        throw new Exception("Unknown VCS!");
      }

      foreach ($paths as $path => $flags) {
        if ($flags & ArcanistRepositoryAPI::FLAG_UNTRACKED) {
          unset($paths[$path]);
        }
      }

      $paths = array_keys($paths);
    } else {
      $paths = $this->getArgument('paths');
      if (empty($paths)) {
        throw new ArcanistUsageException(
          "You must specify one or more files to lint when using '--lintall'.");
      }
      foreach ($paths as $key => $path) {
        $full_path = Filesystem::resolvePath($path);
        if (!Filesystem::pathExists($full_path)) {
          throw new ArcanistUsageException("Path '{$path}' does not exist!");
        }
        $relative_path = Filesystem::readablePath(
          $full_path,
          $working_copy->getProjectRoot());
        $paths[$key] = $relative_path;
      }
    }

    PhutilSymbolLoader::loadClass($engine);
    if (!is_subclass_of($engine, 'ArcanistLintEngine')) {
      throw new ArcanistUsageException(
        "Configured lint engine '{$engine}' is not a subclass of ".
        "'ArcanistLintEngine'.");
    }

    $engine = newv($engine, array());
    $engine->setWorkingCopy($working_copy);

    if ($this->getArgument('advice')) {
      $engine->setMinimumSeverity(ArcanistLintSeverity::SEVERITY_ADVICE);
    } else {
      $engine->setMinimumSeverity(ArcanistLintSeverity::SEVERITY_WARNING);
    }

    // Propagate information about which lines changed to the lint engine.
    // This is used so that the lint engine can drop warning messages
    // concerning lines that weren't in the change.
    $engine->setPaths($paths);
    if (!$should_lint_all) {
      foreach ($paths as $path) {
        // Note that getChangedLines() returns null to indicate that a file
        // is binary or a directory (i.e., changed lines are not relevant).
        $engine->setPathChangedLines(
          $path,
          $this->getChangedLines($path, 'new'));
      }
    }

    $results = $engine->run();

    if ($this->getArgument('never-apply-patches')) {
      $apply_patches = false;
    } else {
      $apply_patches = true;
    }

    if ($this->getArgument('apply-patches')) {
      $prompt_patches = false;
    } else {
      $prompt_patches = true;
    }

    $wrote_to_disk = false;

    switch ($this->getArgument('output')) {
      case 'json':
        $renderer = new ArcanistLintJSONRenderer();
        $prompt_patches = false;
        $apply_patches = false;
        if ($this->getArgument('never-apply-patches') ||
            $this->getArgument('apply-patches')) {
          throw new ArcanistUsageException(
            "Automatic patch suggestion is disabled when using JSON output. ".
            "Remove --never-apply-patches or --apply-patches."
          );
        }
        break;
      case 'summary':
        $renderer = new ArcanistLintSummaryRenderer();
        break;
      default:
        $renderer = new ArcanistLintRenderer();
        break;
    }

    foreach ($results as $result) {
      if (!$result->getMessages()) {
        continue;
      }

      echo $renderer->renderLintResult($result);

      if ($apply_patches && $result->isPatchable()) {
        $patcher = ArcanistLintPatcher::newFromArcanistLintResult($result);
        $old = $patcher->getUnmodifiedFileContent();
        $new = $patcher->getModifiedFileContent();

        if ($prompt_patches) {
          $old_file = $result->getFilePathOnDisk();
          if (!Filesystem::pathExists($old_file)) {
            $old_file = '/dev/null';
          }
          $new_file = new TempFile();
          Filesystem::writeFile($new_file, $new);

          // TODO: Improve the behavior here, make it more like
          // difference_render().
          passthru(csprintf("diff -u %s %s", $old_file, $new_file));

          $prompt = phutil_console_format(
            "Apply this patch to __%s__?",
            $result->getPath());
          if (!phutil_console_confirm($prompt, $default_no = false)) {
            continue;
          }
        }

        $patcher->writePatchToDisk();
        $wrote_to_disk = true;
      }
    }

    if ($wrote_to_disk &&
        ($repository_api instanceof ArcanistGitAPI) &&
        $this->shouldAmendChanges) {
      $amend = phutil_console_confirm("Amend HEAD with lint patches?");
      if ($amend) {
        execx(
          '(cd %s; git commit -a --amend -C HEAD)',
          $repository_api->getPath());
      } else {
        throw new ArcanistUsageException(
          "Sort out the lint changes that were applied to the working ".
          "copy and relint.");
      }
    }

    $unresolved = array();
    $has_warnings = false;
    $has_errors = false;

    foreach ($results as $result) {
      foreach ($result->getMessages() as $message) {
        if (!$message->isPatchApplied()) {
          if ($message->isError()) {
            $has_errors = true;
          } else if ($message->isWarning()) {
            $has_warnings = true;
          }
          $unresolved[] = $message;
        }
      }
    }
    $this->unresolvedMessages = $unresolved;

    // Take the most severe lint message severity and use that
    // as the result code.
    if ($has_errors) {
      $result_code = self::RESULT_ERRORS;
    } else if ($has_warnings) {
      $result_code = self::RESULT_WARNINGS;
    } else {
      $result_code = self::RESULT_OKAY;
    }

    if (!$this->getParentWorkflow()) {
      if ($result_code == self::RESULT_OKAY) {
        echo $renderer->renderOkayResult();
      }
    }

    return $result_code;
  }

  public function getUnresolvedMessages() {
    return $this->unresolvedMessages;
  }

}
