@local @local_embeddiscussion
Feature: Anonymous embedded discussions
  In order to let students speak freely
  As a teacher
  I can make an embedded discussion anonymous, so that students see each other under handles

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
      | student2 | Student   | Two      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                                                                                   | contentformat |
      | Discuss A | Chapter 1 | <div class="embeddiscussion-embed" data-embeddiscussion="0123456789abcde4"></div>        | 1             |
    And the following "local_embeddiscussion > threads" exist:
      | activity | embedkey         | anonymous |
      | book1    | 0123456789abcde4 | 1         |
    And the following "local_embeddiscussion > posts" exist:
      | thread           | user     | content              |
      | 0123456789abcde4 | student1 | Hello from a student |
    And I change the window size to "large"

  @javascript
  Scenario: A student is told their posts are anonymous, and sees others' under handles
    When I log in as "student2"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then I should see "Your posts will be anonymous to other students"
    And I should see "Hello from a student"
    And I should not see "Student One"

  @javascript
  Scenario: A teacher sees who wrote what, and is told students are anonymous to each other
    When I log in as "teacher1"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then I should see "Student posts will appear as anonymous to other students"
    And I should see "Student One"
