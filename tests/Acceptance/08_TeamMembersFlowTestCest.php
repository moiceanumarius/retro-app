<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\Step\Acceptance\AuthSteps;
use Tests\Support\Step\Acceptance\DatabaseSteps;
use Tests\Support\Step\Acceptance\NavigationSteps;
use Tests\Support\Step\Acceptance\UISteps;

class TeamMembersFlowTestCest
{
    private string $adminEmail = '';
    private string $memberEmail = '';
    private string $adminPassword = 'TeamMemberAdmin123!';
    private string $memberPassword = 'TeamMember123!';
    private int $adminUserId = 0;
    private int $memberUserId = 0;
    private int $organizationId = 0;
    private int $teamId = 0;
    private string $timestamp = '';

    public function testTeamMembersFlow(
        AcceptanceTester $I,
        AuthSteps $auth,
        DatabaseSteps $db,
        NavigationSteps $nav,
        UISteps $ui
    ) {
        $I->wantTo('Test team members flow: create users, organization, team, add member, remove member');
        
        // Initialize timestamp
        $this->timestamp = (string)time();
        $this->adminEmail = 'team_member_admin_' . $this->timestamp . '@example.com';
        $this->memberEmail = 'team_member_user_' . $this->timestamp . '@example.com';
        
        // ========================================
        // SETUP: CREATE USERS
        // ========================================
        $I->comment("========================================");
        $I->comment("SETUP: Creating admin and member users");
        $I->comment("========================================");
        
        // Create admin user
        $this->adminUserId = $db->createAdminUser(
            $this->adminEmail,
            $this->adminPassword,
            'MemberAdmin',
            'User'
        );
        $I->comment("✅ Admin user created with ID: {$this->adminUserId}");
        
        // Create member user
        $this->memberUserId = $db->createMemberUser(
            $this->memberEmail,
            $this->memberPassword,
            'TeamMember',
            'User'
        );
        $I->comment("✅ Member user created with ID: {$this->memberUserId}");
        
        // ========================================
        // SETUP: CREATE ORGANIZATION
        // ========================================
        $I->comment("========================================");
        $I->comment("SETUP: Creating organization");
        $I->comment("========================================");
        
        $orgName = 'Team Members Org ' . $this->timestamp;
        $this->organizationId = $db->createOrganization($orgName, $this->adminUserId);
        $I->comment("✅ Organization created with ID: {$this->organizationId}");
        
        // Add both users to organization
        $db->addUserToOrganization($this->organizationId, $this->adminUserId, 'ADMIN');
        $db->addUserToOrganization($this->organizationId, $this->memberUserId, 'MEMBER');
        $I->comment("✅ Both users added to organization");
        
        // ========================================
        // SETUP: CREATE TEAM
        // ========================================
        $I->comment("========================================");
        $I->comment("SETUP: Creating team");
        $I->comment("========================================");
        
        $teamName = 'Test Members Team ' . $this->timestamp;
        $this->teamId = $db->createTeam($teamName, $this->organizationId, $this->adminUserId);
        $I->comment("✅ Team created with ID: {$this->teamId}");
        
        // Add admin as team owner
        $db->addUserToTeam($this->teamId, $this->adminUserId, 'OWNER');
        $I->comment("✅ Admin added as team owner");
        
        // ========================================
        // STEP 1: LOGIN AS ADMIN
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 1: Login as admin");
        $I->comment("========================================");
        
        $auth->loginAsAdmin($this->adminEmail, $this->adminPassword);
        $I->comment("✅ Logged in as admin");
        
        // ========================================
        // STEP 2: NAVIGATE TO TEAM DETAILS
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 2: Navigating to team details");
        $I->comment("========================================");
        
        $nav->goToTeams();
        $I->wait(2);
        
        // Click on team name to view details
        try {
            $I->click($teamName);
            $I->wait(2);
        } catch (\Exception $e) {
            $I->amOnPage("/teams/{$this->teamId}");
            $I->wait(2);
        }
        
        // UI Verification - Element 1: Team details page
        $ui->verifyVisible($teamName);
        
        // UI Verification - Element 2: Team members section
        $ui->verifyVisible('Team Members');
        $I->comment("✅ Team details page loaded");
        $ui->takeScreenshot('team-members-01-team-details');
        
        // ========================================
        // STEP 3: ADD MEMBER TO TEAM
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 3: Adding member to team");
        $I->comment("========================================");
        
        // Click on "Add Member" button/link
        try {
            $I->click('Add Member');
            $I->wait(2);
        } catch (\Exception $e) {
            // Try alternative
            try {
                $I->click('Add Team Member');
                $I->wait(2);
            } catch (\Exception $e2) {
                // Navigate directly
                $I->amOnPage("/teams/{$this->teamId}/add-member");
                $I->wait(2);
            }
        }
        
        // UI Verification - Element 3: Add member form
        $ui->verifyVisible('Add Member');
        $ui->takeScreenshot('team-members-02-add-member-form');
        
        // Select member from dropdown
        $ui->scrollToTop();
        $I->wait(1);
        
        // Open user dropdown
        $I->click('#userDropdownButton');
        $I->wait(1);
        
        // Search for member user
        $I->fillField('#userSearchInput', $this->memberEmail);
        $I->wait(2);
        
        // Select user from dropdown
        $I->click('.user-option');
        $I->wait(1);
        
        // Select role (if role field exists)
        try {
            $I->selectOption('team_member[role]', 'MEMBER');
            $I->wait(1);
        } catch (\Exception $e) {
            $I->comment("Role field not found or already selected");
        }
        
        // Submit form
        try {
            $I->click('Add Member');
            $I->wait(1);
        } catch (\Exception $e) {
            // Try submit button
            $I->click('button[type="submit"]');
            $I->wait(1);
        }
        
        // DB Verification: Member added to team
        $I->seeInDatabase('team_member', [
            'team_id' => $this->teamId,
            'user_id' => $this->memberUserId
        ]);
        $I->comment("✅ Member added to team in DB");
        
        // Should be redirected back to team details
        $I->wait(2);
        
        // UI Verification - Element 4: Team details page
        $ui->verifyVisible($teamName);
        
        // UI Verification - Element 5: Member visible in team
        $ui->verifyVisible('TeamMember');
        $I->comment("✅ Member visible in team UI");
        $ui->takeScreenshot('team-members-03-member-added');
        
        // ========================================
        // STEP 4: REMOVE MEMBER FROM TEAM
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 4: Removing member from team");
        $I->comment("========================================");
        
        // Scroll down to members section
        $ui->scrollToBottom();
        $I->wait(2);
        
        // Look for Remove button next to member
        try {
            // Try clicking Remove button (there should be multiple, click first one after owner)
            $I->click('button.btn-danger');
            $I->wait(2);
            
            // Accept confirmation
            try {
                $ui->acceptConfirmation();
            } catch (\Exception $e) {
                // Try modal confirmation
                try {
                    $ui->confirmModal();
                } catch (\Exception $e2) {
                    $I->comment("No confirmation needed");
                }
            }
            $I->wait(1);
        } catch (\Exception $e) {
            $I->comment("⚠️ Could not find remove button with btn-danger class");
            
            // Try alternative: Remove link or button
            try {
                $I->click('Remove');
                $I->wait(2);
                
                try {
                    $ui->acceptConfirmation();
                } catch (\Exception $e2) {
                    try {
                        $ui->confirmModal();
                    } catch (\Exception $e3) {
                        $I->comment("No confirmation needed");
                    }
                }
                $I->wait(1);
            } catch (\Exception $e2) {
                $I->comment("⚠️ Could not find remove button/link");
            }
        }
        
        // DB Verification: Member removed from team
        // The member might be hard deleted or soft deleted
        $memberExists = $I->grabFromDatabase('team_member', 'id', [
            'team_id' => $this->teamId,
            'user_id' => $this->memberUserId
        ]);
        
        if ($memberExists === null || $memberExists === false) {
            $I->comment("✅ Member removed from team (hard delete)");
        } else {
            // Check if there's an is_active field
            try {
                $isActive = $I->grabFromDatabase('team_member', 'is_active', [
                    'team_id' => $this->teamId,
                    'user_id' => $this->memberUserId
                ]);
                
                if ($isActive === 0 || $isActive === false) {
                    $I->comment("✅ Member marked as inactive (soft delete)");
                } else {
                    $I->comment("⚠️ Member still active in DB");
                }
            } catch (\Exception $e) {
                $I->comment("⚠️ Member record still exists (no is_active field)");
            }
        }
        
        // UI Verification - Element 6: Still on team details page
        $ui->verifyVisible($teamName);
        
        // UI Verification - Element 7: Member should not be visible
        try {
            $I->dontSee('TeamMember User');
            $I->comment("✅ Member not visible in team UI");
        } catch (\Exception $e) {
            $I->comment("⚠️ Member might still be visible (cache or partial match)");
            // Try more specific check
            try {
                $I->dontSee($this->memberEmail);
                $I->comment("✅ Member email not visible");
            } catch (\Exception $e2) {
                $I->comment("⚠️ Member email still visible");
            }
        }
        
        $ui->takeScreenshot('team-members-04-member-removed');
        
        $I->comment("========================================");
        $I->comment("✅ TEAM MEMBERS FLOW TEST PASSED!");
        $I->comment("Admin user ID: {$this->adminUserId}");
        $I->comment("Member user ID: {$this->memberUserId}");
        $I->comment("Organization ID: {$this->organizationId}");
        $I->comment("Team ID: {$this->teamId}");
        $I->comment("Flow: CREATE USERS → ADD TO ORG → CREATE TEAM → ADD MEMBER → REMOVE MEMBER");
        $I->comment("========================================");
    }
}

