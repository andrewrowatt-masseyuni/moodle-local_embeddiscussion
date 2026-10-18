@local @local_embeddiscussion
Feature: Embedded discussions in book chapters
  In order to host a separate conversation alongside each chapter
  As a teacher
  Each chapter's embedded discussion keeps its own posts

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | course | name | idnumber |
      | book     | C1     | b1   | book1    |
    And the following "mod_book > chapters" exist:
      | book | title | content                                                                            | contentformat | pagenum |
      | b1   | c1    | <div class="embeddiscussion-embed" data-embeddiscussion="0123456789abcde5"></div> | 1             | 1       |
      | b1   | c2    | <div class="embeddiscussion-embed" data-embeddiscussion="0123456789abcde6"></div> | 1             | 2       |
    And the following "local_embeddiscussion > threads" exist:
      | activity | embedkey         |
      | book1    | 0123456789abcde5 |
      | book1    | 0123456789abcde6 |

  @javascript
  Scenario: A comment posted in one chapter is not visible in the other chapter
    Given I am on the "b1" "book activity" page logged in as "teacher1"
    And I change the window size to "large"
    And the embedded discussion is loaded
    # The book opens on chapter c1.
    When I post "c1-discussion" to the embedded discussion
    Then I should see "c1-discussion"

    # Move to chapter c2; its discussion is a separate thread.
    When I click on "Next" "link"
    And the embedded discussion is loaded
    Then I should not see "c1-discussion"
    When I post "c2-discussion" to the embedded discussion
    Then I should see "c2-discussion"

    # Back to chapter c1; only its own comment is shown.
    When I click on "Previous" "link"
    And the embedded discussion is loaded
    Then I should see "c1-discussion"
    And I should not see "c2-discussion"

    # Renaming a chapter changes nothing: the discussion is the one the token names.
    When I turn editing mode on
    And I follow "Edit chapter \"2. c2\""
    And I set the field "Chapter title" to "c3"
    And I press "Save changes"
    And the embedded discussion is loaded
    Then I should see "c2-discussion"
