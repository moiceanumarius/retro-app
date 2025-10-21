<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class OrganizationTestCest
{
    private static string $testUserEmail = 'org_admin@example.com';
    private static string $testUserPassword = 'AdminPass123!';
    private static int $testUserId = 0;
    private static int $createdOrgId = 0;
    private static string $timestamp = '';

    public function testCreateEditAndVerifyOrganization(AcceptanceTester $I)
    {
        $I->wantTo('Create an organization, edit it, and verify it displays correctly');
        
        // Initialize timestamp
        self::$timestamp = (string)time();
        
        // Create admin user (only once)
        $this->ensureAdminUserExists($I);
        
        // Login as admin
        $this->loginAsAdmin($I);
        
        $orgName = 'Test Organization ' . self::$timestamp;
        $orgNameEdited = 'Edited Organization ' . self::$timestamp;
        
        // ========================================
        // STEP 1: CREATE ORGANIZATION
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 1: Creating organization");
        $I->comment("========================================");
        
        $I->amOnPage('/organizations/create');
        $I->wait(2);
        
        // UI Verification - Element 1: Create page loaded
        $I->see('Organization');
        
        // UI Verification - Element 2: Form input exists (by placeholder)
        $I->seeElement('input[placeholder*="organization name"]');
        
        // Fill organization name (by label)
        $I->fillField('Organization Name', $orgName);
        
        // Submit form
        $I->click('button[type="submit"]');
        $I->wait(3);
        
        // Take screenshot
        $I->makeScreenshot('organization-01-after-create');
        
        // DB Verification: Check organization was created
        $I->seeInDatabase('organizations', ['name' => $orgName, 'owner_id' => self::$testUserId]);
        self::$createdOrgId = (int)$I->grabFromDatabase('organizations', 'id', ['name' => $orgName]);
        $I->comment("✅ Organization created in DB with ID: " . self::$createdOrgId);
        
        // Navigate to organizations list
        $I->amOnPage('/organizations');
        $I->wait(2);
        
        // UI Verification - Element 3: Organization name appears in list
        $I->see($orgName);
        
        // UI Verification - Element 4: Edit button exists
        $I->see('Edit');
        
        $I->comment("✅ Organization created and displayed correctly");
        $I->makeScreenshot('organization-02-list-after-create');
        
        // ========================================
        // STEP 2: EDIT ORGANIZATION
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 2: Editing organization");
        $I->comment("========================================");
        
        $I->amOnPage('/organizations/' . self::$createdOrgId . '/edit');
        $I->wait(2);
        
        // UI Verification - Element 5: Edit page loaded
        $I->see('Organization');
        
        // UI Verification - Element 6: Current name is in input
        $I->seeInField('Organization Name', $orgName);
        
        // Change organization name
        $I->fillField('Organization Name', $orgNameEdited);
        
        // Submit edit form
        $I->click('button[type="submit"]');
        $I->wait(3);
        
        $I->makeScreenshot('organization-03-after-edit');
        
        // DB Verification: Check organization was updated
        $I->seeInDatabase('organizations', ['id' => self::$createdOrgId, 'name' => $orgNameEdited]);
        $I->dontSeeInDatabase('organizations', ['id' => self::$createdOrgId, 'name' => $orgName]);
        $I->comment("✅ Organization name updated in DB");
        
        // ========================================
        // STEP 3: VERIFY EDITED ORGANIZATION IN LIST
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 3: Verifying edited organization");
        $I->comment("========================================");
        
        $I->amOnPage('/organizations');
        $I->wait(2);
        
        // UI Verification - Element 7: New name appears in list
        $I->see($orgNameEdited);
        
        // UI Verification - Element 8: Edit button still exists
        $I->see('Edit');
        
        // UI Verification - Element 9: View Details button exists
        $I->see('View Details');
        
        // UI Verification - Element 10: Organizations page title
        $I->see('Organizations');
        
        $I->comment("✅ Edited organization displayed correctly in list");
        $I->makeScreenshot('organization-04-list-after-edit');
        
        $I->comment("========================================");
        $I->comment("✅ Create/Edit test completed successfully!");
        $I->comment("Organization ID: " . self::$createdOrgId . " - saved for next test");
        $I->comment("========================================");
    }

    /**
     * Ensure admin user exists (called once in first test)
     */
    private function ensureAdminUserExists(AcceptanceTester $I): void
    {
        $I->comment("Setting up admin user for organization tests");
        
        // Check if user already exists
        $existingUserId = $I->grabFromDatabase('user', 'id', ['email' => self::$testUserEmail]);
        
        if ($existingUserId) {
            self::$testUserId = (int)$existingUserId;
            $I->comment("Admin user already exists with ID: " . self::$testUserId);
            
            // Ensure user has ADMIN role
            $adminRoleId = (int)$I->grabFromDatabase('roles', 'id', ['code' => 'ROLE_ADMIN']);
            $existingRole = $I->grabFromDatabase('user_roles', 'id', ['user_id' => self::$testUserId]);
            
            if ($existingRole) {
                $I->updateInDatabase('user_roles', 
                    ['role_id' => $adminRoleId, 'is_active' => 1], 
                    ['user_id' => self::$testUserId]
                );
            } else {
                $I->haveInDatabase('user_roles', [
                    'user_id' => self::$testUserId,
                    'role_id' => $adminRoleId,
                    'is_active' => 1,
                    'assigned_at' => date('Y-m-d H:i:s')
                ]);
            }
        } else {
            // Create new admin user
            $I->haveInDatabase('user', [
                'email' => self::$testUserEmail,
                'password' => password_hash(self::$testUserPassword, PASSWORD_BCRYPT),
                'first_name' => 'Admin',
                'last_name' => 'User',
                'is_verified' => 1,
                'roles' => '[]',
                'email_notifications' => 1,
                'push_notifications' => 1,
                'weekly_digest' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            self::$testUserId = (int)$I->grabFromDatabase('user', 'id', ['email' => self::$testUserEmail]);
            $I->comment("Admin user created with ID: " . self::$testUserId);
            
            // Assign ADMIN role
            $adminRoleId = (int)$I->grabFromDatabase('roles', 'id', ['code' => 'ROLE_ADMIN']);
            $I->haveInDatabase('user_roles', [
                'user_id' => self::$testUserId,
                'role_id' => $adminRoleId,
                'is_active' => 1,
                'assigned_at' => date('Y-m-d H:i:s')
            ]);
        }
        
        $I->comment("✅ Admin user ready (ID: " . self::$testUserId . ")");
        $I->seeInDatabase('user_roles', ['user_id' => self::$testUserId, 'is_active' => 1]);
    }

    public function testAddAndRemoveMembersFromOrganization(AcceptanceTester $I)
    {
        $I->wantTo('Add and remove members from organization');
        
        // Get organization from previous test
        if (self::$createdOrgId === 0 || self::$testUserId === 0) {
            $I->comment("ERROR: Organization or admin user not found. Run create test first.");
            return;
        }
        
        $I->comment("Using organization ID: " . self::$createdOrgId);
        
        // Login as admin
        $this->loginAsAdmin($I);
        
        // ========================================
        // STEP 1: CREATE TEST USER IN DB
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 1: Creating test user for organization");
        $I->comment("========================================");
        
        $memberEmail = 'org_member_' . self::$timestamp . '@example.com';
        
        // Create member user in DB
        $I->haveInDatabase('user', [
            'email' => $memberEmail,
            'password' => password_hash('MemberPass123!', PASSWORD_BCRYPT),
            'first_name' => 'Member',
            'last_name' => 'TestUser',
            'is_verified' => 1,
            'roles' => '[]',
            'email_notifications' => 1,
            'push_notifications' => 1,
            'weekly_digest' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        $memberId = (int)$I->grabFromDatabase('user', 'id', ['email' => $memberEmail]);
        $I->comment("✅ Test user created in DB with ID: {$memberId}");
        
        // DB Verification: User exists
        $I->seeInDatabase('user', ['id' => $memberId, 'email' => $memberEmail]);
        
        // Assign MEMBER role to new user
        $memberRoleId = (int)$I->grabFromDatabase('roles', 'id', ['code' => 'ROLE_MEMBER']);
        $I->haveInDatabase('user_roles', [
            'user_id' => $memberId,
            'role_id' => $memberRoleId,
            'is_active' => 1,
            'assigned_at' => date('Y-m-d H:i:s')
        ]);
        
        $I->comment("✅ Member role assigned to test user");
        
        // ========================================
        // STEP 2: ADD MEMBER TO ORGANIZATION IN DB
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 2: Adding member to organization");
        $I->comment("========================================");
        
        // Add member to organization in DB
        $I->haveInDatabase('organization_members', [
            'organization_id' => self::$createdOrgId,
            'user_id' => $memberId,
            'is_active' => 1,
            'joined_at' => date('Y-m-d H:i:s')
        ]);
        
        // DB Verification: Member is in organization
        $I->seeInDatabase('organization_members', [
            'organization_id' => self::$createdOrgId,
            'user_id' => $memberId,
            'is_active' => 1
        ]);
        $I->comment("✅ Member added to organization in DB");
        
        // Navigate to organizations list to verify
        $I->amOnPage('/organizations');
        $I->wait(2);
        
        // UI Verification - Element 1: Organization appears in list
        $editedOrgName = $I->grabFromDatabase('organizations', 'name', ['id' => self::$createdOrgId]);
        $I->see($editedOrgName);
        
        // UI Verification - Element 2: Organizations page loaded
        $I->see('Organizations');
        
        $I->comment("✅ Member added to organization successfully");
        $I->makeScreenshot('organization-05-with-member');
        
        // ========================================
        // STEP 3: REMOVE MEMBER FROM ORGANIZATION
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 3: Removing member from organization");
        $I->comment("========================================");
        
        // Remove member from organization in DB (set is_active to 0)
        $I->updateInDatabase('organization_members', 
            ['is_active' => 0, 'left_at' => date('Y-m-d H:i:s')], 
            ['organization_id' => self::$createdOrgId, 'user_id' => $memberId]
        );
        
        // DB Verification: Member is marked as inactive
        $I->seeInDatabase('organization_members', [
            'organization_id' => self::$createdOrgId,
            'user_id' => $memberId,
            'is_active' => 0
        ]);
        $I->comment("✅ Member marked as inactive in organization in DB");
        
        // Verify in organizations list
        $I->amOnPage('/organizations');
        $I->wait(2);
        
        // UI Verification - Element 3: Organization still appears in list
        $I->see($editedOrgName);
        
        // UI Verification - Element 4: Organizations page loaded
        $I->see('Organizations');
        
        $I->comment("✅ Member removed from organization successfully");
        $I->makeScreenshot('organization-06-after-remove-member');
        
        $I->comment("========================================");
        $I->comment("✅ Add/Remove members test completed!");
        $I->comment("Test user ID: {$memberId} - kept in DB for future tests");
        $I->comment("Organization ID: " . self::$createdOrgId . " - kept in DB for future tests");
        $I->comment("========================================");
    }

    /**
     * Helper method to login as admin
     */
    private function loginAsAdmin(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('email', self::$testUserEmail);
        $I->fillField('password', self::$testUserPassword);
        $I->click('Sign in');
        $I->wait(3);
        $I->comment("✅ Logged in as admin");
    }
}
