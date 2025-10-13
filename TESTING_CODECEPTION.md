# Codeception E2E Testing - Documentation

## Overview

This project uses Codeception with Selenium for End-to-End (E2E) testing. Tests are organized using **Step Objects** with **Dependency Injection** for better code reusability and maintainability.

## Test Architecture

### Step Classes (Helper Classes)

Located in `/tests/Support/Step/Acceptance/`, these classes provide reusable methods for common operations:

1. **AuthSteps** - Authentication operations (login, logout, register)
2. **DatabaseSteps** - Database setup and manipulation
3. **NavigationSteps** - Page navigation
4. **UISteps** - UI interactions and common patterns

### Dependency Injection

All step classes are injected via method parameters for clean, testable code:

```php
public function testExample(
    AcceptanceTester $I,
    AuthSteps $auth,
    DatabaseSteps $db,
    NavigationSteps $nav,
    UISteps $ui
) {
    // Use helper methods
    $userId = $db->createAdminUser('admin@test.com', 'Pass123!');
    $auth->loginAsAdmin('admin@test.com', 'Pass123!');
    $nav->goToRoleManagement();
}
```

## Available Helper Methods

### AuthSteps
- `login(string $email, string $password)` - Login with credentials
- `loginAsAdmin(string $email, string $password)` - Login as admin
- `logout()` - Logout
- `register(string $firstName, string $lastName, string $email, string $password)` - Register new user

### DatabaseSteps
- `createUser(...)` - Create user
- `createAdminUser(...)` - Create admin user
- `createMemberUser(...)` - Create member user
- `assignRole(int $userId, string $roleCode)` - Assign role
- `changeUserRole(int $userId, string $newRoleCode)` - Change role
- `createOrganization(string $name, int $ownerId)` - Create organization
- `addUserToOrganization(...)` - Add user to organization
- `createTeam(...)` - Create team
- `addUserToTeam(...)` - Add user to team
- `getRoleId(string $roleCode)` - Get role ID

### NavigationSteps
- `goToDashboard()` - Navigate to Dashboard
- `goToOrganizations()` - Navigate to Organizations
- `goToTeams()` - Navigate to Teams
- `goToRoleManagement()` - Navigate to Role Management
- `goToActions()` - Navigate to Actions
- `goToAnalytics()` - Navigate to Analytics
- `goToUserProfile()` - Navigate to User Profile
- `goToOrganizationDetails(int $id)` - Navigate to specific organization
- `goToTeamDetails(int $id)` - Navigate to specific team

### UISteps
- `selectUserFromDropdown(...)` - Select user from custom dropdown
- `waitForDataTable(int $seconds)` - Wait for DataTable
- `scrollToTop()` - Scroll to top
- `scrollToBottom()` - Scroll to bottom
- `acceptConfirmation()` - Accept JavaScript alert
- `confirmModal(string $buttonId)` - Click modal confirm
- `verifyVisible(string $text)` - Verify element visible
- `verifyMultipleVisible(array $texts)` - Verify multiple elements
- `takeScreenshot(string $name)` - Take screenshot
- `clickRemoveAndConfirm(...)` - Click remove and confirm
- `assignRoleToUser(string $email, string $roleCode)` - Assign role via UI

## Running Tests

### All Acceptance Tests
```bash
make test-acceptance
```

### Fast Mode (without steps output)
```bash
make test-acceptance-fast
```

### Main Test Suite (Login → Registration → Organization)
```bash
make test-acceptance-main
```

### Specific Test File
```bash
docker exec retro_app_php_dev vendor/bin/codecept run tests/Acceptance/06_RoleManagementTestCest.php
```

### With Steps Output
```bash
docker exec retro_app_php_dev vendor/bin/codecept run tests/Acceptance/06_RoleManagementTestCest.php --steps
```

## Viewing Tests with Selenium

1. Connect to Selenium via VNC: `http://localhost:7900`
2. Password: *(no password required - `SE_VNC_NO_PASSWORD=1`)*
3. Resolution: Full HD (1920x1080)

## Test Structure Example

```php
<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\Step\Acceptance\AuthSteps;
use Tests\Support\Step\Acceptance\DatabaseSteps;
use Tests\Support\Step\Acceptance\NavigationSteps;
use Tests\Support\Step\Acceptance\UISteps;

class MyTestCest
{
    public function testMyFeature(
        AcceptanceTester $I,
        AuthSteps $auth,
        DatabaseSteps $db,
        NavigationSteps $nav,
        UISteps $ui
    ) {
        $I->wantTo('Test my feature');
        
        // Setup test data
        $userId = $db->createAdminUser('admin@test.com', 'Pass123!');
        $orgId = $db->createOrganization('Test Org', $userId);
        
        // Login
        $auth->loginAsAdmin('admin@test.com', 'Pass123!');
        
        // Navigate
        $nav->goToOrganizations();
        
        // UI interactions
        $ui->verifyVisible('Test Org');
        $ui->takeScreenshot('org-visible');
        
        // Assertions
        $I->seeInDatabase('organizations', ['id' => $orgId]);
    }
}
```

## Best Practices

1. **Use Dependency Injection** - Inject step classes via method parameters
2. **Reuse Helper Methods** - Don't duplicate code, use step classes
3. **Create Unique Test Data** - Use timestamps for unique emails/names
4. **Take Screenshots** - Use `$ui->takeScreenshot()` at important steps
5. **Verify in DB and UI** - Check both database and user interface
6. **Clean Test Data** - Tests should be idempotent
7. **Use Meaningful Names** - Test and method names should be descriptive

## Current Test Coverage

- ✅ Login (3 tests)
- ✅ Registration (6 tests)
- ✅ Organizations (1 comprehensive test)
- ✅ Dashboard (1 test)
- ✅ Dashboard Roles (1 comprehensive test)
- ✅ Role Management (1 comprehensive test)

**Total: 13 tests, 114 assertions**

## Adding New Tests

1. Create test file in `/tests/Acceptance/`
2. Inject required step classes via method parameters
3. Use helper methods for common operations
4. Add DB and UI verifications
5. Take screenshots at key points
6. Run tests to verify

## Troubleshooting

### Test Fails with "Element not found"
- Add `$I->wait()` before interaction
- Use `$ui->waitForDataTable()` for AJAX content
- Check element selector in screenshot

### Database Issues
- Ensure test database is set up
- Check `tests/Acceptance.suite.yml` for DB config
- Verify test data is unique

### Selenium Connection Issues
- Ensure Selenium is running: `make dev-up`
- Check port 4444 is accessible
- View browser via VNC at `localhost:7900`

## Documentation

- Step Classes: `/tests/Support/Step/README.md`
- Codeception Docs: https://codeception.com/docs
- WebDriver Module: https://codeception.com/docs/modules/WebDriver
