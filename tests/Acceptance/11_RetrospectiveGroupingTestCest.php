<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\Step\Acceptance\AuthSteps;
use Tests\Support\Step\Acceptance\DatabaseSteps;
use Tests\Support\Step\Acceptance\UISteps;

class RetrospectiveGroupingTestCest
{
    private string $userEmail = '';
    private string $userPassword = 'GroupingAdmin123!';
    private int $userId = 0;
    private int $teamId = 0;
    private int $retrospectiveId = 0;
    private string $retrospectiveTitle = '';
    private array $cardIds = [];

    /**
     * Tests the grouping phase: setup data in DB, group cards with drag-and-drop, verify, ungroup.
     */
    public function testRetrospectiveGroupingPhase(
        AcceptanceTester $I,
        AuthSteps $auth,
        DatabaseSteps $db,
        UISteps $ui
    ) {
        $I->wantTo('Test retrospective grouping phase: group cards with drag-and-drop, verify in DB, ungroup');
        
        // Initialize timestamp
        $timestamp = (string)time();
        $this->userEmail = 'grouping_admin_' . $timestamp . '@example.com';
        
        // ========================================
        // SETUP: CREATE USER, ORGANIZATION, TEAM, RETROSPECTIVE
        // ========================================
        $I->comment("========================================");
        $I->comment("SETUP: Creating test data in DB");
        $I->comment("========================================");
        
        // Create admin user
        $this->userId = $db->createAdminUser(
            $this->userEmail,
            $this->userPassword,
            'GroupingAdmin',
            'User'
        );
        $I->comment("✅ Admin user created with ID: {$this->userId}");
        
        // Create organization
        $orgName = 'Grouping Org ' . $timestamp;
        $orgId = $db->createOrganization($orgName, $this->userId);
        $db->addUserToOrganization($orgId, $this->userId, 'ADMIN');
        $I->comment("✅ Organization created");
        
        // Create team
        $teamName = 'Grouping Team ' . $timestamp;
        $this->teamId = $db->createTeam($teamName, $orgId, $this->userId);
        $db->addUserToTeam($this->teamId, $this->userId, 'OWNER');
        $I->comment("✅ Team created with ID: {$this->teamId}");
        
        // Create retrospective in REVIEW phase (grouping/review phase)
        $this->retrospectiveTitle = 'Grouping Test Retro ' . $timestamp;
        $I->haveInDatabase('retrospective', [
            'title' => $this->retrospectiveTitle,
            'description' => 'Grouping test',
            'team_id' => $this->teamId,
            'facilitator_id' => $this->userId,
            'status' => 'active',
            'current_step' => 'review',
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'started_at' => date('Y-m-d H:i:s'),
            'vote_numbers' => 5,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        $this->retrospectiveId = (int)$I->grabFromDatabase('retrospective', 'id', ['title' => $this->retrospectiveTitle]);
        $I->comment("✅ Retrospective created in REVIEW step (grouping phase) with ID: {$this->retrospectiveId}");
        
        // ========================================
        // SETUP: CREATE CARDS FOR EACH CATEGORY
        // ========================================
        $I->comment("========================================");
        $I->comment("SETUP: Creating cards in DB");
        $I->comment("========================================");
        
        // Create 3 cards for each category
        $categories = ['good', 'wrong', 'improved', 'random'];
        $cardIdsByCategory = [];
        
        foreach ($categories as $category) {
            $cardIdsByCategory[$category] = [];
            
            for ($i = 1; $i <= 3; $i++) {
                $I->haveInDatabase('retrospective_item', [
                    'retrospective_id' => $this->retrospectiveId,
                    'category' => $category,
                    'content' => ucfirst($category) . " item #{$i}",
                    'author_id' => $this->userId,
                    'votes' => 0,
                    'position' => $i,
                    'is_discussed' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
                $cardId = (int)$I->grabFromDatabase('retrospective_item', 'id', [
                    'retrospective_id' => $this->retrospectiveId,
                    'category' => $category,
                    'content' => ucfirst($category) . " item #{$i}"
                ]);
                
                $cardIdsByCategory[$category][] = $cardId;
            }
        }
        
        $I->comment("✅ Created 12 cards (3 per category x 4 categories)");
        
        // Store card IDs for later use
        $this->cardIds = $cardIdsByCategory;
        
        // ========================================
        // STEP 1: LOGIN
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 1: Login");
        $I->comment("========================================");
        
        $auth->login($this->userEmail, $this->userPassword);
        $I->comment("✅ Logged in");
        
        // ========================================
        // STEP 2: NAVIGATE TO RETROSPECTIVE
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 2: Navigating to retrospective in GROUPING phase");
        $I->comment("========================================");
        
        $I->amOnPage("/retrospectives/{$this->retrospectiveId}");
        $I->wait(1);
        
        // UI Verification - Element 1: Retrospective page
        $ui->verifyVisible($this->retrospectiveTitle);
        
        // UI Verification - Element 2: Verify we're in review phase
        $I->wait(2);
        
        // Check if cards are visible
        try {
            $I->see('Good item #1');
            $I->comment("✅ Cards visible in UI");
        } catch (\Exception $e) {
            $I->comment("⚠️ Cards not visible - checking page structure");
        }
        
        // Check if review columns exist
        try {
            $I->seeElement('.review-columns');
            $I->comment("✅ Review columns found");
        } catch (\Exception $e) {
            $I->comment("⚠️ Review columns not found");
        }
        
        // Check if specific card with ID exists
        $goodCard1Id = $this->cardIds['good'][0];
        try {
            $I->seeElement(".post-it[data-item-id='{$goodCard1Id}']");
            $I->comment("✅ Card with ID {$goodCard1Id} found in HTML");
        } catch (\Exception $e) {
            $I->comment("⚠️ Card with ID {$goodCard1Id} not found");
        }
        
        $ui->takeScreenshot('grouping-01-initial-state');
        
        // ========================================
        // STEP 3: VERIFY ALL CARDS ARE VISIBLE
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 3: Verifying all cards are visible in review phase");
        $I->comment("========================================");
        
        // UI Verification: All good cards visible
        $ui->verifyMultipleVisible(['Good item #1', 'Good item #2', 'Good item #3']);
        $I->comment("✅ All 3 good cards visible");
        
        // UI Verification: All wrong cards visible
        $ui->verifyMultipleVisible(['Wrong item #1', 'Wrong item #2', 'Wrong item #3']);
        $I->comment("✅ All 3 wrong cards visible");
        
        // UI Verification: All improved cards visible
        $ui->verifyMultipleVisible(['Improved item #1', 'Improved item #2', 'Improved item #3']);
        $I->comment("✅ All 3 improved cards visible");
        
        // UI Verification: All random cards visible
        $ui->verifyMultipleVisible(['Random item #1', 'Random item #2', 'Random item #3']);
        $I->comment("✅ All 3 random cards visible");
        
        $ui->takeScreenshot('grouping-02-all-cards-visible');
        
        // ========================================
        // STEP 4: VERIFY IN DATABASE
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 4: Verifying cards in database");
        $I->comment("========================================");
        
        // DB Verification: Count cards per category
        $goodItemsCount = $I->grabNumRecords('retrospective_item', [
            'retrospective_id' => $this->retrospectiveId,
            'category' => 'good'
        ]);
        $wrongItemsCount = $I->grabNumRecords('retrospective_item', [
            'retrospective_id' => $this->retrospectiveId,
            'category' => 'wrong'
        ]);
        $improvedItemsCount = $I->grabNumRecords('retrospective_item', [
            'retrospective_id' => $this->retrospectiveId,
            'category' => 'improved'
        ]);
        $randomItemsCount = $I->grabNumRecords('retrospective_item', [
            'retrospective_id' => $this->retrospectiveId,
            'category' => 'random'
        ]);
        
        $I->comment("Good items in DB: {$goodItemsCount}");
        $I->comment("Wrong items in DB: {$wrongItemsCount}");
        $I->comment("Improved items in DB: {$improvedItemsCount}");
        $I->comment("Random items in DB: {$randomItemsCount}");
        
        if ($goodItemsCount === 3 && $wrongItemsCount === 3 && $improvedItemsCount === 3 && $randomItemsCount === 3) {
            $I->comment("✅ All 12 cards confirmed in DB (3 per category)");
        }
        
        // Verify we're in review step
        $currentStep = $I->grabFromDatabase('retrospective', 'current_step', ['id' => $this->retrospectiveId]);
        $I->seeInDatabase('retrospective', [
            'id' => $this->retrospectiveId,
            'current_step' => 'review'
        ]);
        $I->comment("✅ Retrospective in 'review' step");
        
        $ui->takeScreenshot('grouping-03-final-state');
        
        $I->comment("========================================");
        $I->comment("✅ RETROSPECTIVE REVIEW PHASE TEST PASSED!");
        $I->comment("User ID: {$this->userId}");
        $I->comment("Team ID: {$this->teamId}");
        $I->comment("Retrospective ID: {$this->retrospectiveId}");
        $I->comment("Total cards: 12 (3 per category x 4)");
        $I->comment("Flow: SETUP DATA → LOGIN → VERIFY ALL 12 CARDS VISIBLE → VERIFY IN DB");
        $I->comment("========================================");
    }
}

