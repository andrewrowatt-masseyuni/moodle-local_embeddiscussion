@local @local_embeddiscussion
Feature: A discussion copied in from elsewhere
  In order to know when a discussion will not show
  As a teacher
  I see a notice where a discussion copied in from another activity was embedded, and students see nothing

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | idnumber | content                                                                                          |
      | page     | C1     | First page | page1    | <p>First</p><div class="embeddiscussion-embed" data-embeddiscussion="0123456789abcde7"></div>  |
      | page     | C1     | Copy page  | page2    | <p>Copied</p><div class="embeddiscussion-embed" data-embeddiscussion="0123456789abcde7"></div> |
    And the following "local_embeddiscussion > threads" exist:
      | activity | embedkey         |
      | page1    | 0123456789abcde7 |

  Scenario: A teacher is told the discussion belongs elsewhere
    When I am on the "Copy page" "page activity" page logged in as teacher1
    Then I should see "Copied"
    And I should see "A discussion was embedded here, but it belongs to another activity or section"
    And "[data-region='filter-embeddiscussion']" "css_element" should not exist

  Scenario: A student sees nothing of it
    When I am on the "Copy page" "page activity" page logged in as student1
    Then I should see "Copied"
    And I should not see "A discussion was embedded here"
    And "[data-region='filter-embeddiscussion']" "css_element" should not exist
    And I am on the "First page" "page activity" page
    And "[data-region='filter-embeddiscussion']" "css_element" should exist
