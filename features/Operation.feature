Feature: Basic and special Operation actions

  # These scenarios use /inventory/operation endpoints

  Background:
    Given a Product "X"
    And a Product "Y"

  Scenario: Operation can be executed

  Scenario: Operation can create Batches inside a Location
    Given a Location A with:
      | X | 10 |
    And an Operation "two" to A with:
      | X | 10 |
    When "two" is executed
    Then A contains:
      | X | 10 |

  Scenario: Operation can transfer Batches between two Locations
    # We also test that only wanted Batch is moved
    Given a Location A with:
      | X | 10 |
      | Y |  1 |
    And a Location B with:
      | X | 1 |
    And an Operation "three" from A to B with:
      | X | 6 |
    When "three" is executed
    # Then show Inventory:Location,Inventory:Batch,Inventory:Operation
    Then A contains:
      | X | 4 |
      | Y | 1 |
    And B contains:
      | X | 7 |

  Scenario: Operation can be rollbacked
  Scenario: Operation can be cancelled