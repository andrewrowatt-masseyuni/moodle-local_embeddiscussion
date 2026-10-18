# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this plugin is part of

`local_embeddiscussion` is one of three plugins that together make **embedded discussions**. They are
developed side by side in a Moodle 4.5 (`MOODLE_405_STABLE`) checkout. In this file, paths outside
this plugin are relative to the Moodle root (`../..` from here), and paths inside it are relative to
this plugin.

| Directory (from the Moodle root) | Component | Git remote (own repo, branch `main`) |
| --- | --- | --- |
| `local/embeddiscussion` (this one) | `local_embeddiscussion` | `andrewrowatt-masseyuni/moodle-local_embeddiscussion` |
| `filter/embeddiscussion` | `filter_embeddiscussion` | `andrewrowatt-masseyuni/moodle-filter_embeddiscussion` |
| `lib/editor/tiny/plugins/embeddiscussion` | `tiny_embeddiscussion` | `andrewrowatt-masseyuni/moodle-tiny_embeddiscussion` |

Each plugin directory is its own git repository, so commit with `git -C <plugin dir> ...`. The Moodle
root is Moodle core's repo, but it also tracks an early snapshot of `local/embeddiscussion`, so
`git status` at the root shows this plugin's changes too.

**This plugin's `README.md` is the design document for all three plugins.** The other two READMEs
point to it. Read it before changing behaviour: it explains the token, drafts, section summaries,
backup/restore, deletion and privacy in detail.

Targets: Moodle 4.5+ (`requires = 2024100700`, `supported = [405, 501]`), PHP 8.1. The filter and the
editor button each declare a dependency on `local_embeddiscussion` at a minimum version.

## Commands

The dev checkout has its own moodle-docker containers
(`moodle405_filter_embeddiscussion-{webserver,db,selenium,exttests,mailpit}-1`). If they are stopped,
start them with `docker start <names>`. Commands inside the container run in `/var/www/html`, the
Moodle root.

```bash
W=moodle405_filter_embeddiscussion-webserver-1

# Re-initialise test environments (after install.xml/version.php/db/*.php changes). Must run as root.
docker exec $W php admin/tool/phpunit/cli/init.php
docker exec $W php admin/tool/behat/cli/init.php

# PHPUnit (as www-data)
docker exec -u www-data $W vendor/bin/phpunit --testsuite local_embeddiscussion_testsuite   # or filter_/tiny_
docker exec -u www-data $W vendor/bin/phpunit local/embeddiscussion/tests/api_test.php
docker exec -u www-data $W vendor/bin/phpunit --filter test_name local/embeddiscussion/tests/api_test.php

# Behat (as www-data). The tiny features are also tagged @local_embeddiscussion.
docker exec -u www-data $W php admin/tool/behat/cli/run.php --tags=@local_embeddiscussion
docker exec -u www-data $W php admin/tool/behat/cli/run.php --feature=/var/www/html/local/embeddiscussion/tests/behat/posts.feature

# PHPDoc checker (needs a bootstrapped Moodle, so run it in the container)
docker exec -u www-data $W php local/moodlecheck/cli/moodlecheck.php --path=local/embeddiscussion --format=text
```

These run on the host, from the Moodle root:

```bash
# AMD build and ESLint. Run it from inside the plugin directory, and commit amd/build/ together with amd/src/.
(cd local/embeddiscussion && grunt --max-lint-warnings=0 amd)

# Code checker, the same as CI (it fails on any warning)
../moodle-plugin-ci/bin/moodle-plugin-ci phpcs --max-warnings 0 local/embeddiscussion
```

CI (`.github/workflows/moodle-ci.yml`, the same in each plugin) runs phplint, phpcs, phpdoc
(both with `--max-warnings 0`), validate, savepoints, mustache, grunt, `phpunit --fail-on-warning` and
Behat on Moodle 4.5 / PHP 8.1 / Postgres. It installs the companion plugins from **their `main`
branches on GitHub**, so a change that spans plugins only passes CI after every affected repo is pushed.

## Architecture

### Why it is split into three plugins, and what each one owns

- **`local_embeddiscussion`** (this plugin) owns everything stateful: tables, the web services,
  rendering, the post editor (Quill, vendored in `thirdparty/quill`), backup/restore, events and
  privacy. It has to be a *local* plugin because only certain plugin types can add data to another
  module's backup.
- **`filter_embeddiscussion`** (`filter/embeddiscussion/classes/text_filter.php`) only looks threads
  up and swaps tokens for placeholders. It never creates anything. Its install switches the filter on
  site-wide.
- **`tiny_embeddiscussion`** is the TinyMCE button: it inserts and changes tokens and previews them in
  the editor. Its `plugininfo::is_enabled()` decides which editors get it (module contexts, plus
  course contexts only on `/course/modedit.php` and `/course/editsection.php`).

The filter and the button call into this plugin's classes directly. Both check
`get_config('local_embeddiscussion', 'version')` first, so they degrade safely if it is missing.

### The token and how a discussion is made and shown

The text holds only `<div class="embeddiscussion-embed" data-embeddiscussion="<16 hex>"></div>`. The
key names a row in `local_embeddiscussion_thread`. The thread's settings (only whether it is
anonymous) live on that row, never in the text. The token format is defined in two places, which must stay in step:
`classes/token.php` here (PHP `PATTERN`, `html()`, `replace()`, `rekey()`) and the tiny plugin's
`amd/src/common.js` (JS `tokenSelector`, `keyPattern`).

1. **Embed (editor):** `tiny_embeddiscussion/ui` opens `local_embeddiscussion\form\embed_form` (a
   `core_form\dynamic_form`) through `core_form/modalform`, passing `contextid`, `sectionid` and
   optionally `key`. On save, `api::save_embed()` creates or updates the thread, and the JS inserts or
   re-keys the token. `api::embed_target()` maps the editor's context to one of three owners: an
   activity (cm), a section (course context plus a `sectionid` read from the editsection URL), or a
   **draft** (`cmid = 0` and `sectionid = 0`, made on the add-activity form).
2. **Display (filter):** `text_filter` → `threads::for_cm()` / `threads::for_sections()`. These read a
   whole course's threads once per request into a static cache →
   `output\embed::render_thread()`, which emits `templates/placeholder.mustache` and calls
   `js_call_amd('local_embeddiscussion/discussion', 'init')` once.
3. **Interact (page JS):** `amd/src/discussion.js` lazy-loads each placeholder with
   `local_embeddiscussion_get_thread` and writes through `create_post`, `edit_post`, `delete_post` and
   `react_post` (`classes/external/`, registered in `db/services.php`). `external\helper` holds the
   shared return structures and the thread lookup.
4. **Preview (editor JS):** `tiny_embeddiscussion/previews` fetches every token's preview at once from
   `local_embeddiscussion_get_previews` (`output\embed::render_preview()`). It puts each preview in a
   **shadow root** on the token, adds a serializer filter, and uses a `MutationObserver`.
   `tiny_embeddiscussion/move` provides the buttons beside it: move up/down, and insert an empty
   paragraph above/after (the only way to write next to a discussion at either end of the text).

### Class responsibilities in this plugin

- `api`: the thread lifecycle. It embeds, adopts drafts (`adopt_module()`, called from
  `lib.php`'s `coursemodule_edit_post_actions`), claims section summaries, deletes, and purges drafts.
- `manager`: posts, reactions, sanitising, and the per-viewer thread view model (permissions,
  anonymisation, role labels) that the JS consumes.
- `threads`: the per-request, course-wide cache of thread rows. `reset_cache()` must follow any write
  to the thread table. `api` and restore already call it, and tests call it in `setUp()`.
- `handles` / `identicon`: stable anonymous handles ("Bright Ibis") and avatars.
- Lifecycle wiring: `lib.php` (module edit/delete callbacks), `hook_callbacks`
  (`before_course_deleted`), `observer` (section updated/deleted, course content deleted), and
  `backup/moodle2/`.

### Invariants that are easy to break

- **Never put markup inside a token.** The filter's regex matches a token only up to its first
  `</div>`, so editor-only UI goes in the shadow root, never in the light DOM.
- **Filter output must never contain `data-embeddiscussion`.** That keeps a second filter pass a
  no-op, and `mod_lesson` does make one.
- **Every query for drafts must also say `sectionid = 0`.** Otherwise `purge_drafts` deletes section
  threads.
- **Lookups are always by owner plus key.** A token copied into another activity must not resolve
  there. Section keys are scoped to the course, because a filter in a course context does not know
  which section it is formatting. `api::claim_section_summary()` (on `course_section_updated`) gives
  each duplicated or pasted section summary its own thread. Restore suspends this with
  `api::hold_claims()`.
- **Text holding tokens must be displayed with `noclean`.** HTMLPurifier strips `data-*`
  attributes. Post content itself is rendered with `'filter' => false`.
- **Backup element names start with `embeddiscussion_`.** Every local plugin's backup elements share
  one optigroup, which throws `multiple_optigroup_duplicate_element` on a name any other local
  plugin also uses (`local_reactions` has `reactions`), and the whole backup fails. The tests here
  only catch it on a site that has the other plugin.
- **Web services take a `threadid` from the client.** Each one loads the thread with
  `external\helper::get_thread()`, which refuses drafts. It then derives the context from the thread's
  `contextid`, never from the client.

### Tests

The PHPUnit generator is `tests/generator/lib.php`, and the Behat generator
(`local_embeddiscussion > threads` / `section threads`) makes threads with a given `embedkey`. Put the
matching token into the activity's text yourself. XPath cannot see into the editor's shadow-root
preview, so use the tiny plugin's `behat_tiny_embeddiscussion` steps (`... TinyMCE editor should
preview discussion "..."`, `... should save / should not save "..."`, `I move the embedded discussion
"up" ...`, `I insert space "above" the embedded discussion ...`). Those buttons are clicked with the
pointer, over the discussion first, because they take no clicks until it is hovered. **To type after
inserting space, use `I type "..." in the "..." TinyMCE editor`**: core's `I type "..."` sends keys to
the page, and once the editor has taken focus from script Chrome delivers them without inserting any
text.

## Conventions

Docblocks and comments here are full sentences that explain *why* (see `classes/token.php`,
`classes/api.php`, and the filter's `classes/text_filter.php`). Match that style. User-facing strings
go in each plugin's `lang/en/` file.
