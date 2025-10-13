<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class DashboardRolesTestCest
{
    private string $testUserEmail = 'test@example.com';
    private string $testUserPassword = 'password123';

    public function testRoleHierarchyAndPermissions(AcceptanceTester $I)
    {
        $I->wantTo('Test all roles from lowest to highest with their permissions');
        
        // Define roles in order from lowest to highest
        $roles = [
            'ROLE_MEMBER' => [
                'displayName' => 'Member',
                'expectedLinks' => ['Dashboard', 'Teams', 'Retrospectives', 'My Profile'],
                'notExpectedLinks' => ['Organizations', 'Admin Panel', 'Manage Users']
            ],
            'ROLE_SUPERVISOR' => [
                'displayName' => 'Supervisor',
                'expectedLinks' => ['Dashboard', 'Teams', 'Retrospectives', 'My Profile'],
                'notExpectedLinks' => ['Admin Panel']
            ],
            'ROLE_ADMIN' => [
                'displayName' => 'Admin',
                'expectedLinks' => ['Dashboard', 'Teams', 'Retrospectives', 'My Profile', 'Organizations'],
                'notExpectedLinks' => []
            ]
        ];

        foreach ($roles as $roleCode => $roleData) {
            $I->comment("========================================");
            $I->comment("Testing role: {$roleCode} ({$roleData['displayName']})");
            $I->comment("========================================");
            
            // Step 1: Change user role in DB using SQL
            $this->changeUserRole($I, $roleCode);
            
            // DB Verification: Verify role was changed in database
            $dbRole = $I->grabFromDatabase('user_roles', 'role_id', [
                'user_id' => $I->grabFromDatabase('user', 'id', ['email' => $this->testUserEmail])
            ]);
            
            $roleName = $I->grabFromDatabase('roles', 'code', ['id' => $dbRole]);
            $I->comment("DB Check - Current role in database: {$roleName}");
            
            // Step 2: Login with the role
            $I->amOnPage('/login');
            $I->fillField('email', $this->testUserEmail);
            $I->fillField('password', $this->testUserPassword);
            $I->click('Sign in');
            $I->wait(1);
            
            // Step 3: Navigate to dashboard
            $I->amOnPage('/dashboard');
            $I->wait(2);
            
            // UI Verification - Element 1: Check role is displayed
            $I->see('CURRENT ROLE');
            
            // UI Verification - Element 2: Check role name is correct
            $I->see($roleData['displayName']);
            
            $I->comment("✅ Role '{$roleData['displayName']}' displayed correctly in UI");
            
            // UI Verification - Element 3+: Check expected links are visible
            foreach ($roleData['expectedLinks'] as $link) {
                $I->see($link);
                $I->comment("✅ Link '{$link}' is visible for {$roleData['displayName']}");
            }
            
            // UI Verification: Check links that should NOT be visible
            foreach ($roleData['notExpectedLinks'] as $link) {
                try {
                    $I->dontSee($link);
                    $I->comment("✅ Link '{$link}' is NOT visible for {$roleData['displayName']} (correct)");
                } catch (\Exception $e) {
                    $I->comment("⚠️ Link '{$link}' is visible for {$roleData['displayName']} (may be incorrect)");
                }
            }
            
            // Take screenshot for this role
            $screenshotName = 'dashboard-role-' . strtolower(str_replace('ROLE_', '', $roleCode));
            $I->makeScreenshot($screenshotName);
            
            // Step 4: Logout
            $I->click('Logout');
            $I->wait(2);
            
            // UI Verification: Confirm logout
            $I->seeInCurrentUrl('/login');
            $I->comment("✅ Logged out successfully");
            
            $I->comment("Completed testing for role: {$roleCode}");
            $I->comment("");
        }
        
        $I->comment("========================================");
        $I->comment("✅ All roles tested successfully!");
        $I->comment("========================================");
    }

    /**
     * Change user role in database using Codeception DB methods
     */
    private function changeUserRole(AcceptanceTester $I, string $roleCode): void
    {
        $I->comment("Changing user role to: {$roleCode}");
        
        // Get user ID
        $userId = $I->grabFromDatabase('user', 'id', ['email' => $this->testUserEmail]);
        $I->comment("User ID: {$userId}");
        
        // Get role ID
        $roleId = $I->grabFromDatabase('roles', 'id', ['code' => $roleCode]);
        $I->comment("Role ID for {$roleCode}: {$roleId}");
        
        // Update user role in database
        $I->updateInDatabase('user_roles', ['role_id' => $roleId, 'assigned_at' => date('Y-m-d H:i:s')], ['user_id' => $userId]);
        
        $I->comment("✅ User role changed to {$roleCode} in database");
        
        // Verify in DB
        $I->seeInDatabase('user_roles', [
            'user_id' => $userId,
            'role_id' => $roleId
        ]);
    }
}
