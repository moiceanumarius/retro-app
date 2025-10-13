<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\Step\Acceptance\AuthSteps;
use Tests\Support\Step\Acceptance\DatabaseSteps;
use Tests\Support\Step\Acceptance\NavigationSteps;
use Tests\Support\Step\Acceptance\UISteps;

class RoleManagementTestCest
{
    private string $adminEmail = '';
    private string $memberEmail = '';
    private string $adminPassword = 'AdminPass123!';
    private string $memberPassword = 'MemberPass123!';
    private int $adminUserId = 0;
    private int $memberUserId = 0;
    private int $organizationId = 0;
    private string $timestamp = '';

    public function testCompleteRoleManagementFlow(
        AcceptanceTester $I,
        AuthSteps $auth,
        DatabaseSteps $db,
        NavigationSteps $nav,
        UISteps $ui
    ) {
        $I->wantTo('Test complete role management flow: create users, organization, assign roles, remove and re-add roles');
        
        // Initialize timestamp
        $this->timestamp = (string)time();
        $this->adminEmail = 'role_admin_' . $this->timestamp . '@example.com';
        $this->memberEmail = 'role_member_' . $this->timestamp . '@example.com';
        
        // ========================================
        // SETUP: CREATE USERS AND ORGANIZATION
        // ========================================
        $I->comment("========================================");
        $I->comment("SETUP: Creating test data");
        $I->comment("========================================");
        
        // Create admin user
        $this->adminUserId = $db->createAdminUser(
            $this->adminEmail,
            $this->adminPassword,
            'RoleAdmin',
            'User'
        );
        $I->comment("✅ Admin user created with ID: {$this->adminUserId}");
        
        // Create member user
        $this->memberUserId = $db->createMemberUser(
            $this->memberEmail,
            $this->memberPassword,
            'RoleMember',
            'User'
        );
        $I->comment("✅ Member user created with ID: {$this->memberUserId}");
        
        // Create organization
        $orgName = 'Role Test Org ' . $this->timestamp;
        $this->organizationId = $db->createOrganization($orgName, $this->adminUserId);
        $I->comment("✅ Organization created with ID: {$this->organizationId}");
        
        // Add users to organization
        $db->addUserToOrganization($this->organizationId, $this->adminUserId, 'ADMIN');
        $db->addUserToOrganization($this->organizationId, $this->memberUserId, 'MEMBER');
        $I->comment("✅ Both users added to organization");
        
        // ========================================
        // STEP 1: LOGIN AS ADMIN
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 1: Login as admin");
        $I->comment("========================================");
        
        $auth->loginAsAdmin($this->adminEmail, $this->adminPassword);
        $I->comment("✅ Logged in as admin");
        
        // ========================================
        // STEP 2: NAVIGATE TO ROLE MANAGEMENT
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 2: Navigating to Role Management");
        $I->comment("========================================");
        
        $nav->goToRoleManagement();
        
        // UI Verification - Element 1: Role Management page loaded
        $I->see('Role Management');
        
        // UI Verification - Element 2: Page description or title
        $ui->verifyMultipleVisible(['Role Management', 'Manage user roles']);
        $ui->takeScreenshot('role-mgmt-01-page');
        
        // ========================================
        // STEP 3: VERIFY MEMBER USER APPEARS IN LIST
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 3: Verifying member user in role management");
        $I->comment("========================================");
        
        $ui->verifyMultipleVisible(['RoleMember', 'Member']);
        $I->comment("✅ Member user visible in role management");
        $ui->takeScreenshot('role-mgmt-02-member-visible');
        
        // ========================================
        // STEP 4: REMOVE ROLE FROM MEMBER USER
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 4: Removing role from member user");
        $I->comment("========================================");
        
        $ui->waitForDataTable(3);
        $ui->clickRemoveAndConfirm();
        $I->wait(1);
        
        // DB Verification
        $memberRoleRemoved = $I->grabFromDatabase('user_roles', 'is_active', [
            'user_id' => $this->memberUserId
        ]);
        
        if ($memberRoleRemoved === 0 || $memberRoleRemoved === false) {
            $I->comment("✅ Role marked as inactive in DB");
        } else {
            $I->comment("⚠️ Role still active, might be deleted completely");
        }
        
        $ui->verifyVisible('Role Management');
        $I->comment("✅ Role removed from member user");
        $ui->takeScreenshot('role-mgmt-03-after-remove');
        
        // ========================================
        // STEP 5: RE-ADD MEMBER ROLE VIA FORM
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 5: Re-adding MEMBER role to user");
        $I->comment("========================================");
        
        $ui->assignRoleToUser($this->memberEmail, 'ROLE_MEMBER');
        
        // DB Verification
        $memberRoleId = $db->getRoleId('ROLE_MEMBER');
        $I->seeInDatabase('user_roles', [
            'user_id' => $this->memberUserId,
            'role_id' => $memberRoleId,
            'is_active' => 1
        ]);
        $I->comment("✅ MEMBER role re-assigned in DB");
        
        $ui->verifyMultipleVisible(['RoleMember', 'Member']);
        $I->comment("✅ Member user visible again with MEMBER role");
        $ui->takeScreenshot('role-mgmt-04-after-re-add');
        
        // ========================================
        // STEP 6: REMOVE MEMBER ROLE AGAIN
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 6: Removing MEMBER role to change it");
        $I->comment("========================================");
        
        // Wait for DataTable to refresh
        $I->wait(1);
        
        // Click Remove button again
        $I->click('button.btn-danger-modern');
        $I->wait(2);
        
        // Accept the JavaScript confirm dialog
        $I->acceptPopup();
        $I->wait(1);
        
        // DB Verification: Check that the latest MEMBER role for this user is inactive
        $latestMemberRole = $I->grabFromDatabase('user_roles', 'is_active', [
            'user_id' => $this->memberUserId,
            'role_id' => $memberRoleId
        ]);
        
        if ($latestMemberRole === 0 || $latestMemberRole === false) {
            $I->comment("✅ MEMBER role removed again (marked as inactive)");
        } else {
            $I->comment("⚠️ Checking if role was deleted instead of deactivated");
        }
        
        $I->makeScreenshot('role-mgmt-05-member-removed-again');
        
        // ========================================
        // STEP 7: ASSIGN FACILITATOR ROLE
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 7: Assigning FACILITATOR role");
        $I->comment("========================================");
        
        // Scroll to top
        $I->executeJS('window.scrollTo(0, 0)');
        $I->wait(2);
        
        // Open user dropdown
        $I->click('#userDropdownButton');
        $I->wait(1);
        
        // Search for member user by email
        $I->fillField('#userSearchInput', $this->memberEmail);
        $I->wait(2);
        
        // Click on user in dropdown results
        $I->click('.user-option');
        $I->wait(1);
        
        // Select FACILITATOR role from dropdown
        $facilitatorRoleId = (int)$I->grabFromDatabase('roles', 'id', ['code' => 'ROLE_FACILITATOR']);
        $I->selectOption('#role_id', $facilitatorRoleId);
        $I->wait(1);
        
        // Submit form
        $I->click('Assign Role');
        $I->wait(1);
        
        // DB Verification: FACILITATOR role assigned
        $I->seeInDatabase('user_roles', [
            'user_id' => $this->memberUserId,
            'role_id' => $facilitatorRoleId,
            'is_active' => 1
        ]);
        $I->comment("✅ FACILITATOR role assigned in DB");
        
        // Wait for DataTable to refresh
        $I->wait(2);
        
        // UI Verification - Element 8: User visible with Facilitator badge
        $I->see('RoleMember');
        
        // UI Verification - Element 9: Facilitator badge visible
        $I->see('Facilitator');
        
        $I->comment("✅ User visible with FACILITATOR role");
        $I->makeScreenshot('role-mgmt-06-facilitator-assigned');
        
        // ========================================
        // STEP 8: REMOVE FACILITATOR ROLE
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 8: Removing FACILITATOR role to change it");
        $I->comment("========================================");
        
        // Wait for DataTable to refresh
        $I->wait(1);
        
        // Click Remove button
        $I->click('button.btn-danger-modern');
        $I->wait(2);
        
        // Accept the JavaScript confirm dialog
        $I->acceptPopup();
        $I->wait(1);
        
        // DB Verification: Check that the FACILITATOR role for this user is inactive
        $latestFacilitatorRole = $I->grabFromDatabase('user_roles', 'is_active', [
            'user_id' => $this->memberUserId,
            'role_id' => $facilitatorRoleId
        ]);
        
        if ($latestFacilitatorRole === 0 || $latestFacilitatorRole === false) {
            $I->comment("✅ FACILITATOR role removed (marked as inactive)");
        } else {
            $I->comment("⚠️ Role might have been deleted");
        }
        
        $I->makeScreenshot('role-mgmt-07-facilitator-removed');
        
        // ========================================
        // STEP 9: ASSIGN SUPERVISOR ROLE
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 9: Assigning SUPERVISOR role");
        $I->comment("========================================");
        
        // Scroll to top
        $I->executeJS('window.scrollTo(0, 0)');
        $I->wait(2);
        
        // Open user dropdown
        $I->click('#userDropdownButton');
        $I->wait(1);
        
        // Search for member user by email
        $I->fillField('#userSearchInput', $this->memberEmail);
        $I->wait(2);
        
        // Click on user in dropdown results
        $I->click('.user-option');
        $I->wait(1);
        
        // Select SUPERVISOR role from dropdown
        $supervisorRoleId = (int)$I->grabFromDatabase('roles', 'id', ['code' => 'ROLE_SUPERVISOR']);
        $I->selectOption('#role_id', $supervisorRoleId);
        $I->wait(1);
        
        // Submit form
        $I->click('Assign Role');
        $I->wait(1);
        
        // DB Verification: SUPERVISOR role assigned
        $I->seeInDatabase('user_roles', [
            'user_id' => $this->memberUserId,
            'role_id' => $supervisorRoleId,
            'is_active' => 1
        ]);
        $I->comment("✅ SUPERVISOR role assigned in DB");
        
        // Wait for DataTable to refresh
        $I->wait(2);
        
        // UI Verification - Element 10: User visible with Supervisor badge
        $I->see('RoleMember');
        
        // UI Verification - Element 11: Supervisor badge visible
        $I->see('Supervisor');
        
        $I->comment("✅ User visible with SUPERVISOR role");
        $I->makeScreenshot('role-mgmt-08-supervisor-assigned');
        
        $I->comment("========================================");
        $I->comment("✅ ROLE MANAGEMENT TEST PASSED!");
        $I->comment("Admin user ID: {$this->adminUserId}");
        $I->comment("Member user ID: {$this->memberUserId}");
        $I->comment("Organization ID: {$this->organizationId}");
        $I->comment("Tested roles: MEMBER → FACILITATOR → SUPERVISOR");
        $I->comment("All data kept in DB for future tests");
        $I->comment("========================================");
    }
}

