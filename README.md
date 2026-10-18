# Embedded discussions (local_embeddiscussion)

Inline, threaded discussions embedded in course content: a book chapter, a page, a label, an
activity description, a lesson page or a section summary. Participants post, reply, edit, delete and
react to comments without leaving the page.

* Optional **anonymous** mode, where students see each other under stable two-word handles (for
  example "Bright Ibis") while teachers still see real names.
* Emoji reactions on posts, configured site-wide.

A teacher adds a discussion from the editor's toolbar, and changes it there later.

## The three plugins

Embedded discussions take three plugins, the same split as teacher guidance (`local_edguidance`),
because Moodle has three extension points they need and each belongs to a different plugin type:

| Plugin | Why it has to be its own plugin |
| --- | --- |
| `local_embeddiscussion` (this one) | Everything else - storage, posts, reactions, anonymity, rendering, backup. It is a *local* plugin because core only lets `format`, `report`, `plagiarism`, `local` and `tool` plugins add data to another module's backup (`backup/moodle2/backup_stepslib.php`), and a discussion has to follow its activity through backup, restore, duplicate, course copy and the recycle bin. |
| `filter_embeddiscussion` | Turns an embedded token into the discussion. Only a filter sees every piece of formatted text. |
| `tiny_embeddiscussion` | The TinyMCE button teachers use to embed discussions and change them. |

Both of the others declare a dependency on this one.

## Requirements

| | |
| --- | --- |
| Moodle | 4.5 (build 2024100700) or later. |
| PHP | 8.1 or later. |
| Editor | TinyMCE, for embedding discussions. A discussion already in the text is shown whichever editor is in use. |

## Installation

1. Install all three plugins:

   ```
   local/embeddiscussion
   filter/embeddiscussion
   lib/editor/tiny/plugins/embeddiscussion
   ```

2. Visit **Site administration > Notifications**, or run `php admin/cli/upgrade.php`.

The filter switches itself on site-wide when it is installed, at the end of the filter order. Without
it nobody sees any discussion, so check it is still **On** under *Site administration > Plugins >
Filters > Manage filters* if discussions ever disappear everywhere.

Settings: *Site administration > Plugins > Local plugins > Embedded discussion*.

## Usage

### Embedding a discussion

In a book chapter, a page, a label, an activity description, a lesson page or a section summary
(**Edit section**), use the **Embedded discussion** button on the editor toolbar (also under
**Insert**) and choose **Embed a discussion**. It opens a short form: whether the discussion is
**Anonymous** (No or Yes, with what that means written under it). Saving puts the discussion where
the cursor is.

In the editor a discussion shows as a header (with an *Anonymous* badge when it is) over a still
skeleton of the discussion's header and composer, without posts - never the live discussion, for
anyone. Click it (or choose **Change
this discussion** with the cursor on it) to switch anonymity on or off; the same form offers
**Remove**, which asks first. To move it, hover over it and use the up and down arrows at its bottom
right, which move it past one paragraph (or other block) at a time. Beside them, **Insert space above
discussion** and **Insert space after discussion** each add an empty paragraph there, with the cursor
in it - the only way to write above or after a discussion at the start or end of the text, or next
to another one, since the cursor cannot enter a discussion.

Removing a discussion from the text - from its form, or by deleting it as you would any other block
- keeps its posts, but they are no longer shown there. Undo brings it straight back until the text is
saved.

### Where it works

Wherever text is edited in an activity's own context (a page's content, a book chapter, a lesson
page, any description), on the *add an activity* form, and in a section's summary. Not in the course
summary, a block, a user's profile or on the front page.

### Who can do what

| Capability | Default | Allows |
| --- | --- | --- |
| `local/embeddiscussion:embed` | Editing teacher, manager | Embedding, changing and moving discussions in the editor. |
| `local/embeddiscussion:createpost` | Student and up | Posting, replying and reacting. |
| `local/embeddiscussion:editownpost` | Student and up | Editing one's own posts. |
| `local/embeddiscussion:deleteownpost` | Student and up | Deleting one's own posts. |
| `local/embeddiscussion:manageposts` | Teacher and up | Editing anyone's posts. |
| `local/embeddiscussion:deleteanypost` | Editing teacher, manager | Deleting anyone's posts. |
| `local/embeddiscussion:viewallauthorsinanonymousthreads` | Teacher and up | Seeing real names in anonymous discussions, and being shown by one's own. |

A teacher who may edit an activity but not embed discussions still gets the editor button, disabled,
so that discussions already in the text stay whole.

## Technical details

### The token

What goes into the text is an empty element:

```html
<div class="embeddiscussion-embed" data-embeddiscussion="0123456789abcdef"></div>
```

The key is 16 random hex characters, naming a row in `local_embeddiscussion_thread`. **A token
carries nothing else** - not even whether the discussion is anonymous. That is the thread's, set from
the editor's form, so it is changed in one place, and copying or editing the text can never quietly
change it.

A thread is **made from the editor, never by viewing a page**. The filter only looks threads up; a
token with no thread where it is shows a notice to someone who may embed discussions and nothing to
anyone else. So there is no "first visitor creates the thread" step, no capability to create one by
viewing, and no notice for a student who arrives first.

Where the filter does not run the token renders as nothing: TinyMCE pads an empty div with `&nbsp;`,
so `styles.css` hides unreplaced tokens. HTMLPurifier strips `data-*` attributes, so text that *is*
cleaned before filtering loses the token and shows no discussion; every place this is for displays
with `noclean`.

A key belongs to one activity, or a section's to its course. Lookups are always by (activity, key),
so a token copied into another activity does not resolve there.

The filter's output never contains the token attribute, so filtering the same text twice - as
`mod_lesson` does to page contents shown as feedback - is a no-op.

### Data

| Table | Holds |
| --- | --- |
| `local_embeddiscussion_thread` | One row per embedded discussion: where it belongs (`courseid`, `cmid`, `sectionid`, and the matching `contextid`), its `embedkey`, `anonymous` and `handleoffset`. `cmid = 0` with `sectionid > 0` is a section's thread; both 0 is a *draft* (see below). Keys are unique per activity, and per course among sections and drafts. |
| `local_embeddiscussion_post` | Posts and replies (`parentid`), sanitised HTML, soft-deleted. |
| `local_embeddiscussion_reaction` | One row per user, post and emoji. |
| `local_embeddiscussion_handle` | Each anonymous poster's handle index in a thread, so a student keeps their handle. |

Thread rows are read a whole course at a time (`threads::for_cm()`, `threads::for_sections()`) and
held for the request: a course page can run the filter over many descriptions, and one query per
course keeps that flat.

### Drafts

Text on the *add an activity* form is written before the activity exists, so a discussion made there
cannot belong to it yet. The editor's context there is the course, and `api::embed_target()` makes a
**draft**: `cmid = 0`, `sectionid = 0`. When the activity is saved,
`local_embeddiscussion_coursemodule_edit_post_actions()` calls `api::adopt_module()`, which reads
every text field of the activity's own record - a page's content as well as its description - and
gives it the drafts whose tokens are there. A daily task (`purge_drafts`) deletes drafts more than a
day old, which is what a cancelled form leaves behind. A draft is on no page, so the filter never
shows one and the web services refuse to read or post to one.

The editor button is offered in a course context only on `/course/modedit.php` and, for a section's
discussion, on `/course/editsection.php`, so there is no other way to make a draft. Every query for
drafts also says `sectionid = 0`; without that, the daily purge would delete every section's
discussion.

### Discussions in section summaries

A section summary is edited on `/course/editsection.php` in the course context - the same context as
the *add an activity* form. Only the page tells them apart, so `tiny_embeddiscussion` reads the
section id from the page URL and hands it to the editor, which sends it back with every call;
`api::embed_target()` checks it belongs to the course.

A section summary is also *formatted* in the course context, and a filter is told the context and
nothing else, so a token in a course context resolves against any section's thread in the course. That
would let two summaries share one discussion - and core makes that happen: duplicating a section
copies its summary verbatim, and a teacher can paste one section's token into another. On
`course_section_updated`, `api::claim_section_summary()` gives the section its own thread - same
anonymity, no posts, as duplicating an activity does - under a new key, and rewrites the summary to
match. Restore holds claims off while each section step runs (see *Backup and restore*).

### The preview in the editor

`tiny_embeddiscussion` shows each token as a header over the skeleton the page shows while a
discussion loads, less its posts (the preview empties the skeleton's `posts` block), rendered by `output\embed::render_preview()` and fetched for
every token in the text at once from `local_embeddiscussion_get_previews`. A preview says no more than
the discussion itself tells anyone who can see it; a draft's needs `embed`.

The preview is never put inside the token: the filter matches a token only up to its first `</div>`,
so anything inside would be left showing on the page. It goes in a **shadow root** attached to the
token, which `innerHTML` and `cloneNode()` leave out, and with them everything TinyMCE builds from
those: the saved text, autosave, copying and undo. As a second line, a serializer attribute filter
empties every token and drops its `contenteditable` however the text is read. TinyMCE rebuilds
tokens freely, and undo can rewrite the body without an event, so a `MutationObserver` attaches
previews as tokens arrive. Each shadow root links the page's own theme stylesheets
(`theme_config::css_urls()`), since the editor's iframe carries only the editor stylesheet. The
header's icon is the editor button's own (`tiny_embeddiscussion`'s `pix/icon.svg`).

Nothing inside the preview acts on its own (`pointer-events: none`): a click lands on the token and
opens the form for a teacher who may embed. The move buttons live in the same shadow root, so they
are never saved either.

### Backup and restore

`backup_local_embeddiscussion_plugin` adds an activity's threads to its `module.xml`, and a section's
to its `section.xml`. A thread's settings are always backed up, so the token in the restored text has
a discussion to show. Its posts, reactions and handles are user data, backed up only with user data:
a course copied for its next run starts with empty discussions, and the recycle bin brings an
activity back with its conversation. `embedkey` is carried verbatim.

On restore, posts arrive in id order, so a reply's parent is always mapped before it. A post whose
author is not in the restore is left out, and a reply to it becomes a post of its own. A section
merged into an existing one is decided by its restored summary: a key the summary does not hold is
skipped, a key the section already uses is skipped, and a key used anywhere else in the course gets a
new key, with the summary following it. A skipped thread or post is mapped to nothing, so its posts
or reactions are skipped too rather than attaching to the previous one.

### Deleting

* `local_embeddiscussion_pre_course_module_delete()` deletes an activity's threads with their posts.
  `tool_recyclebin`'s own callback runs first, so a recycled activity is backed up with them.
* `\core_course\hook\before_course_deleted` does the same for a whole course.
* `course_section_deleted` deletes a section's threads.
* `course_content_deleted` deletes every thread in the course, activities' too:
  `remove_course_contents()` (a restore that deletes the existing content first) skips the
  per-module callbacks, and posts left behind would sit in contexts the privacy API cannot find.

### Privacy

Posts, reactions and anonymous handles are personal data, exported and deleted per context by
`privacy\provider`. Deleting a user's data blanks their posts (keeping replies' shape) and removes
their reactions and handle. Deleting all users' data in a context empties its discussions but keeps
the threads, which are course content and name nobody.

## Testing

```
vendor/bin/phpunit --testsuite local_embeddiscussion_testsuite
vendor/bin/phpunit --testsuite filter_embeddiscussion_testsuite
vendor/bin/phpunit --testsuite tiny_embeddiscussion_testsuite
php admin/tool/behat/cli/run.php --tags=@local_embeddiscussion
```

The Behat generator's `local_embeddiscussion > threads` (and `section threads`) make a thread with
a given `embedkey`; put the matching token into the activity's text for it to show. Its `posts` and
`reactions` name their thread by that `embedkey`. The editor's
preview is in a shadow root, which XPath cannot see into: use `tiny_embeddiscussion`'s steps (*the
"Content" TinyMCE editor should preview discussion "..."*, *... should save / should not save "..."*,
*I move the embedded discussion "up" in the "Content" TinyMCE editor*, *I insert space "above" the
embedded discussion in the "Content" TinyMCE editor*). To type after inserting space, use *I type
"..." in the "Content" TinyMCE editor*: core's *I type "..."* sends keys to the page, and once the
editor has taken focus from script Chrome delivers them without inserting any text.

## Third-party libraries

* [Quill](https://github.com/slab/quill) 2.0.3 (BSD 3-Clause) - the post editor.

## License

GNU GPL v3 or later - see [LICENSE](LICENSE).

## Author

Andrew Rowatt &lt;A.J.Rowatt@massey.ac.nz&gt;, Massey University.
