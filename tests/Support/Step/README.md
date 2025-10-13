# Test Step Classes

This directory contains helper classes (Step Objects) for Codeception acceptance tests. These classes provide reusable methods for common test operations.

## Available Step Classes

### 1. AuthSteps
**Purpose:** Authentication-related operations

**Methods:**
- `login(string $email, string $password)` - Login with credentials
- `loginAsAdmin(string $email, string $password)` - Login as admin and verify
- `logout()` - Logout from application
- `register(string $firstName, string $lastName, string $email, string $password)` - Register new user

**Example:**
```php
$I = new AuthSteps($scenario);
$I->loginAsAdmin('admin@example.com', 'password123');
```

---

### 2. DatabaseSteps
**Purpose:** Database operations for test data setup

**Methods:**
- `createUser(string $email, string $password, string $firstName, string $lastName, bool $isVerified)` - Create user in DB
- `createAdminUser(string $email, string $password, string $firstName, string $lastName)` - Create admin user
- `createMemberUser(string $email, string $password, string $firstName, string $lastName)` - Create member user
- `assignRole(int $userId, string $roleCode)` - Assign role to user
- `changeUserRole(int $userId, string $newRoleCode)` - Change user's role
- `createOrganization(string $name, int $ownerId)` - Create organization
- `addUserToOrganization(int $organizationId, int $userId, string $role)` - Add user to organization
- `createTeam(string $name, int $organizationId, int $createdBy)` - Create team
- `addUserToTeam(int $teamId, int $userId, string $role)` - Add user to team
- `getRoleId(string $roleCode)` - Get role ID by code

**Example:**
```php
$I = new DatabaseSteps($scenario);
$userId = $I->createAdminUser('admin@test.com', 'Pass123!', 'John', 'Doe');
$orgId = $I->createOrganization('Test Org', $userId);
$I->addUserToOrganization($orgId, $userId, 'ADMIN');
```

---

### 3. NavigationSteps
**Purpose:** Navigation between pages

**Methods:**
- `goToDashboard()` - Navigate to Dashboard
- `goToOrganizations()` - Navigate to Organizations
- `goToTeams()` - Navigate to Teams
- `goToRoleManagement()` - Navigate to Role Management
- `goToActions()` - Navigate to Actions
- `goToAnalytics()` - Navigate to Analytics
- `goToUserProfile()` - Navigate to User Profile
- `goToOrganizationDetails(int $organizationId)` - Navigate to specific organization
- `goToTeamDetails(int $teamId)` - Navigate to specific team

**Example:**
```php
$I = new NavigationSteps($scenario);
$I->goToRoleManagement();
$I->goToOrganizationDetails(5);
```

---

### 4. UISteps
**Purpose:** UI interactions and common patterns

**Methods:**
- `selectUserFromDropdown(string $dropdownButtonId, string $searchInputId, string $userEmail)` - Select user from custom dropdown
- `waitForDataTable(int $seconds)` - Wait for DataTable to load
- `scrollToTop()` - Scroll to top of page
- `scrollToBottom()` - Scroll to bottom of page
- `scrollToElement(string $selector)` - Scroll to specific element
- `acceptConfirmation()` - Accept JavaScript alert
- `confirmModal(string $modalConfirmButtonId)` - Click confirm in modal
- `fillFormAndSubmit(array $fields, string $submitButtonText)` - Fill form and submit
- `verifyVisible(string $text)` - Verify element is visible
- `verifyMultipleVisible(array $texts)` - Verify multiple elements are visible
- `takeScreenshot(string $name)` - Take screenshot
- `clickRemoveAndConfirm(string $buttonSelector, bool $useModal)` - Click remove button and confirm
- `assignRoleToUser(string $userEmail, string $roleCode)` - Assign role via UI

**Example:**
```php
$I = new UISteps($scenario);
$I->scrollToTop();
$I->selectUserFromDropdown('userDropdownButton', 'userSearchInput', 'user@test.com');
$I->clickRemoveAndConfirm('button.btn-danger-modern');
```

---

## Usage in Tests

### Method 1: Use Step Objects directly in tests

```php
<?php

namespace Tests\Acceptance;

use Tests\Support\Step\Acceptance\AuthSteps;
use Tests\Support\Step\Acceptance\DatabaseSteps;
use Tests\Support\Step\Acceptance\NavigationSteps;

class MyTestCest
{
    public function testSomething(AuthSteps $I, DatabaseSteps $db, NavigationSteps $nav)
    {
        // Setup data
        $userId = $db->createAdminUser('admin@test.com', 'Pass123!');
        
        // Login
        $I->loginAsAdmin('admin@test.com', 'Pass123!');
        
        // Navigate
        $nav->goToRoleManagement();
        
        // Assertions
        $I->see('Role Management');
    }
}
```

### Method 2: Combine multiple step objects

```php
<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\Step\Acceptance\DatabaseSteps;
use Tests\Support\Step\Acceptance\UISteps;

class RoleTestCest
{
    public function testRoleManagement(AcceptanceTester $I)
    {
        $db = new DatabaseSteps($I->getScenario());
        $ui = new UISteps($I->getScenario());
        
        // Create test data
        $adminId = $db->createAdminUser('admin@test.com', 'Pass123!');
        $memberId = $db->createMemberUser('member@test.com', 'Pass123!');
        
        // Login
        $I->amOnPage('/login');
        $I->fillField('email', 'admin@test.com');
        $I->fillField('password', 'Pass123!');
        $I->click('Sign in');
        $I->wait(3);
        
        // Navigate
        $ui->scrollToTop();
        $I->click('Role Management');
        $I->wait(2);
        
        // Assign role
        $ui->assignRoleToUser('member@test.com', 'ROLE_FACILITATOR');
        
        // Verify
        $I->see('Facilitator');
        $ui->takeScreenshot('role-assigned');
    }
}
```

---

## Best Practices

1. **Separation of Concerns:** Use appropriate step classes for different operations:
   - `DatabaseSteps` for test data setup
   - `AuthSteps` for login/logout
   - `NavigationSteps` for page navigation
   - `UISteps` for UI interactions

2. **Reusability:** Create methods in step classes for operations used in multiple tests

3. **Readability:** Step classes make tests more readable and maintainable

4. **DRY Principle:** Avoid duplicating code across tests by using step classes

---

## Adding New Step Methods

To add new helper methods:

1. Identify the category (Auth, Database, Navigation, or UI)
2. Add the method to the appropriate Step class
3. Document the method with PHPDoc
4. Update this README with the new method

Example:
```php
/**
 * Create a retrospective
 *
 * @param string $title
 * @param int $teamId
 * @param int $createdBy
 * @return int Retrospective ID
 */
public function createRetrospective(string $title, int $teamId, int $createdBy): int
{
    $this->haveInDatabase('retrospectives', [
        'title' => $title,
        'team_id' => $teamId,
        'created_by' => $createdBy,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    return (int)$this->grabFromDatabase('retrospectives', 'id', ['title' => $title]);
}
```

---

## Running Tests with Step Classes

```bash
# Run all acceptance tests
make test-acceptance

# Run specific test
docker exec retro_app_php_dev vendor/bin/codecept run tests/Acceptance/MyTestCest.php

# Run with steps output
docker exec retro_app_php_dev vendor/bin/codecept run tests/Acceptance/MyTestCest.php --steps
```

