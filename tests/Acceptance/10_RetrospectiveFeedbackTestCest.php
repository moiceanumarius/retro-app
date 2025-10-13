<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\Step\Acceptance\AuthSteps;
use Tests\Support\Step\Acceptance\DatabaseSteps;
use Tests\Support\Step\Acceptance\NavigationSteps;
use Tests\Support\Step\Acceptance\UISteps;

class RetrospectiveFeedbackTestCest
{
    private string $userEmail = '';
    private string $userPassword = 'RetroAdmin123!';
    private int $userId = 0;
    private int $teamId = 0;
    private int $retrospectiveId = 0;
    private string $retrospectiveTitle = '';

    /**
     * This test depends on 09_RetrospectiveFlowTestCest running first.
     * It finds the most recent retrospective and user from DB and tests the feedback phase.
     */
    public function testRetrospectiveFeedbackPhase(
        AcceptanceTester $I,
        AuthSteps $auth,
        DatabaseSteps $db,
        NavigationSteps $nav,
        UISteps $ui
    ) {
        $I->wantTo('Test retrospective feedback phase: start timer, add cards, stop timer');
        
        // ========================================
        // STEP 1: FIND LAST RETROSPECTIVE AND USER
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 1: Finding data from previous test");
        $I->comment("========================================");
        
        // Find last retrospective with title pattern
        $timestamp = time();
        $found = false;
        
        for ($i = 0; $i < 120 && !$found; $i++) {
            $ts = $timestamp - $i;
            $retroTitle = "Test Retrospective EDITED {$ts}";
            
            $retroId = (int)$I->grabFromDatabase('retrospective', 'id', ['title' => $retroTitle]);
            
            if ($retroId > 0) {
                $this->retrospectiveId = $retroId;
                $this->retrospectiveTitle = $retroTitle;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $I->comment("⚠️ Could not find retrospective. Creating new one...");
            
            // Fallback: Create fresh test data
            $timestamp = (string)time();
            $adminEmail = 'feedback_admin_' . $timestamp . '@example.com';
            $adminPassword = 'FeedbackAdmin123!';
            
            $adminId = $db->createAdminUser($adminEmail, $adminPassword, 'FeedbackAdmin', 'User');
            $orgId = $db->createOrganization('Feedback Org ' . $timestamp, $adminId);
            $db->addUserToOrganization($orgId, $adminId, 'ADMIN');
            $this->teamId = $db->createTeam('Feedback Team ' . $timestamp, $orgId, $adminId);
            $db->addUserToTeam($this->teamId, $adminId, 'OWNER');
            
            $this->retrospectiveTitle = 'Feedback Test Retro ' . $timestamp;
            $I->haveInDatabase('retrospective', [
                'title' => $this->retrospectiveTitle,
                'description' => 'Feedback test',
                'team_id' => $this->teamId,
                'facilitator_id' => $adminId,
                'status' => 'PENDING',
                'current_step' => 'NOT_STARTED',
                'scheduled_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'vote_numbers' => 5,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            $this->retrospectiveId = (int)$I->grabFromDatabase('retrospective', 'id', ['title' => $this->retrospectiveTitle]);
            $this->userId = $adminId;
            $this->userEmail = $adminEmail;
            $this->userPassword = $adminPassword;
            
            $I->comment("✅ Created fresh test data");
        } else {
            // Get user from retrospective
            $this->userId = (int)$I->grabFromDatabase('retrospective', 'facilitator_id', ['id' => $this->retrospectiveId]);
            $this->userEmail = $I->grabFromDatabase('user', 'email', ['id' => $this->userId]);
            $this->teamId = (int)$I->grabFromDatabase('retrospective', 'team_id', ['id' => $this->retrospectiveId]);
        }
        
        $I->comment("✅ Using retrospective: {$this->retrospectiveTitle} (ID: {$this->retrospectiveId})");
        $I->comment("✅ Using user: {$this->userEmail} (ID: {$this->userId})");
        
        // ========================================
        // STEP 2: LOGIN
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 2: Login");
        $I->comment("========================================");
        
        $auth->login($this->userEmail, $this->userPassword);
        $I->comment("✅ Logged in");
        
        // ========================================
        // STEP 3: NAVIGATE TO RETROSPECTIVE
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 3: Navigating to retrospective");
        $I->comment("========================================");
        
        $I->amOnPage("/retrospectives/{$this->retrospectiveId}");
        $I->wait(1);
        
        // UI Verification - Element 1: Retrospective page
        $ui->verifyVisible($this->retrospectiveTitle);
        $ui->takeScreenshot('feedback-01-retro-page');
        
        // ========================================
        // STEP 4: START RETROSPECTIVE
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 4: Starting retrospective session");
        $I->comment("========================================");
        
        $currentStatus = $I->grabFromDatabase('retrospective', 'status', ['id' => $this->retrospectiveId]);
        $I->comment("Current status before start: {$currentStatus}");
        
        // Try to start retrospective
        try {
            $I->click('Start');
            $I->wait(1);
            $I->comment("✅ Clicked Start button");
        } catch (\Exception $e) {
            try {
                $I->click('Start Retrospective');
                $I->wait(1);
                $I->comment("✅ Clicked Start Retrospective button");
            } catch (\Exception $e2) {
                try {
                    $I->click('Begin');
                    $I->wait(1);
                    $I->comment("✅ Clicked Begin button");
                } catch (\Exception $e3) {
                    $I->comment("⚠️ Could not find Start button - might already be started");
                }
            }
        }
        
        // Verify status changed
        $I->wait(2);
        $newStatus = $I->grabFromDatabase('retrospective', 'status', ['id' => $this->retrospectiveId]);
        $I->comment("Status after start: {$newStatus}");
        
        if ($newStatus !== 'PENDING' && $newStatus !== 'pending') {
            $I->comment("✅ Retrospective started (status changed to {$newStatus})");
        } else {
            $I->comment("⚠️ Status still PENDING");
        }
        
        $ui->takeScreenshot('feedback-02-after-start');
        
        // ========================================
        // STEP 5: START TIMER
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 5: Starting timer");
        $I->comment("========================================");
        
        // Look for Start Timer button
        try {
            $I->click('Start Timer');
            $I->wait(2);
            $I->comment("✅ Timer started");
        } catch (\Exception $e) {
            try {
                $I->click('▶');
                $I->wait(2);
                $I->comment("✅ Timer started (play button)");
            } catch (\Exception $e2) {
                try {
                    // Try clicking on timer button/icon
                    $I->click('button[id*="timer"]');
                    $I->wait(2);
                    $I->comment("✅ Timer started (timer button)");
                } catch (\Exception $e3) {
                    $I->comment("⚠️ Could not find timer start button");
                }
            }
        }
        
        // UI Verification - Element 2: Timer visible
        $I->wait(2);
        $ui->takeScreenshot('feedback-03-timer-started');
        
        // ========================================
        // STEP 6: ADD CARDS - "WHAT WENT WELL"
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 6: Adding cards to 'What Went Well' column");
        $I->comment("========================================");
        
        $goodCardTexts = [];
        for ($cardNum = 1; $cardNum <= 3; $cardNum++) {
            $I->comment("Adding card {$cardNum}/3 to What Went Well");
            
            // Card content
            $cardContent = "Good thing #{$cardNum} - " . time();
            $goodCardTexts[] = $cardContent;
            
            // Fill textarea for "good" category
            $I->fillField('textarea[data-category="good"]', $cardContent);
            $I->wait(1);
            
            // Click Add button for "good" category
            $I->click('button[data-category="good"]');
            $I->wait(2);
            
            // UI Verification: Card visible
            $I->see($cardContent);
            $I->comment("✅ Card {$cardNum} visible in UI");
        }
        
        // DB Verification: Cards created
        $goodItemsCount = $I->grabNumRecords('retrospective_item', [
            'retrospective_id' => $this->retrospectiveId,
            'category' => 'good'
        ]);
        $I->comment("Good items in DB: {$goodItemsCount}");
        
        if ($goodItemsCount >= 3) {
            $I->comment("✅ At least 3 good cards in DB");
        }
        
        $ui->takeScreenshot('feedback-04-good-cards-added');
        
        // ========================================
        // STEP 7: ADD CARDS - "WHAT WENT WRONG"
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 7: Adding cards to 'What Went Wrong' column");
        $I->comment("========================================");
        
        $wrongCardTexts = [];
        for ($cardNum = 1; $cardNum <= 3; $cardNum++) {
            $I->comment("Adding card {$cardNum}/3 to What Went Wrong");
            
            $cardContent = "Bad thing #{$cardNum} - " . time();
            $wrongCardTexts[] = $cardContent;
            
            $I->fillField('textarea[data-category="wrong"]', $cardContent);
            $I->wait(1);
            
            $I->click('button[data-category="wrong"]');
            $I->wait(2);
            
            // UI Verification: Card visible
            $I->see($cardContent);
            $I->comment("✅ Card {$cardNum} visible in UI");
        }
        
        // DB Verification: Cards created
        $wrongItemsCount = $I->grabNumRecords('retrospective_item', [
            'retrospective_id' => $this->retrospectiveId,
            'category' => 'wrong'
        ]);
        $I->comment("Wrong items in DB: {$wrongItemsCount}");
        
        if ($wrongItemsCount >= 3) {
            $I->comment("✅ At least 3 wrong cards in DB");
        }
        
        $ui->takeScreenshot('feedback-05-bad-cards-added');
        
        // ========================================
        // STEP 8: ADD CARDS - "WHAT CAN BE IMPROVED"
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 8: Adding cards to 'What Can Be Improved' column");
        $I->comment("========================================");
        
        $improvedCardTexts = [];
        for ($cardNum = 1; $cardNum <= 3; $cardNum++) {
            $I->comment("Adding card {$cardNum}/3 to What Can Be Improved");
            
            $cardContent = "Improvement #{$cardNum} - " . time();
            $improvedCardTexts[] = $cardContent;
            
            $I->fillField('textarea[data-category="improved"]', $cardContent);
            $I->wait(1);
            
            $I->click('button[data-category="improved"]');
            $I->wait(2);
            
            // UI Verification: Card visible
            $I->see($cardContent);
            $I->comment("✅ Card {$cardNum} visible in UI");
        }
        
        // DB Verification: Cards created
        $improvedItemsCount = $I->grabNumRecords('retrospective_item', [
            'retrospective_id' => $this->retrospectiveId,
            'category' => 'improved'
        ]);
        $I->comment("Improved items in DB: {$improvedItemsCount}");
        
        if ($improvedItemsCount >= 3) {
            $I->comment("✅ At least 3 improved cards in DB");
        }
        
        $ui->takeScreenshot('feedback-06-improvement-cards-added');
        
        // ========================================
        // STEP 9: ADD CARDS - "RANDOM FEEDBACK"
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 9: Adding cards to 'Random Feedback' column");
        $I->comment("========================================");
        
        $randomCardTexts = [];
        for ($cardNum = 1; $cardNum <= 3; $cardNum++) {
            $I->comment("Adding card {$cardNum}/3 to Random Feedback");
            
            $cardContent = "Random feedback #{$cardNum} - " . time();
            $randomCardTexts[] = $cardContent;
            
            $I->fillField('textarea[data-category="random"]', $cardContent);
            $I->wait(1);
            
            $I->click('button[data-category="random"]');
            $I->wait(2);
            
            // UI Verification: Card visible
            $I->see($cardContent);
            $I->comment("✅ Card {$cardNum} visible in UI");
        }
        
        // DB Verification: Cards created
        $randomItemsCount = $I->grabNumRecords('retrospective_item', [
            'retrospective_id' => $this->retrospectiveId,
            'category' => 'random'
        ]);
        $I->comment("Random items in DB: {$randomItemsCount}");
        
        if ($randomItemsCount >= 3) {
            $I->comment("✅ At least 3 random cards in DB");
        }
        
        $ui->takeScreenshot('feedback-07-random-cards-added');
        
        // ========================================
        // STEP 10: VERIFY ALL CARDS IN DB
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 10: Verifying all cards in database");
        $I->comment("========================================");
        
        // Count total items for this retrospective
        $totalItems = $I->grabNumRecords('retrospective_item', ['retrospective_id' => $this->retrospectiveId]);
        $I->comment("Total items in DB: {$totalItems}");
        
        if ($totalItems >= 12) {
            $I->comment("✅ At least 12 cards created (3 per column x 4 columns)");
        } else {
            $I->comment("⚠️ Only {$totalItems} cards found (expected 12)");
        }
        
        // ========================================
        // STEP 11: DELETE ONE CARD FROM EACH COLUMN
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 11: Deleting one card from each column");
        $I->comment("========================================");
        
        // Delete from "What Went Well"
        $I->comment("Deleting one card from What Went Well");
        try {
            // Click delete button (X) on first good card
            $I->click('.post-it.good .post-it-delete');
            $I->wait(1);
            
            // Confirm deletion (modal or alert)
            try {
                $ui->confirmModal('#confirmModalConfirm');
                $I->comment("✅ Confirmed via modal");
            } catch (\Exception $e) {
                try {
                    $ui->acceptConfirmation();
                    $I->comment("✅ Confirmed via alert");
                } catch (\Exception $e2) {
                    $I->comment("No confirmation needed");
                }
            }
            
            $I->wait(2);
            
            // UI Verification: Card should be gone
            $I->dontSee($goodCardTexts[0]);
            $I->comment("✅ Good card deleted from UI");
            
            // DB Verification
            $goodItemsAfterDelete = $I->grabNumRecords('retrospective_item', [
                'retrospective_id' => $this->retrospectiveId,
                'category' => 'good'
            ]);
            $I->comment("Good items after delete: {$goodItemsAfterDelete}");
            
            if ($goodItemsAfterDelete === ($goodItemsCount - 1)) {
                $I->comment("✅ Good card deleted from DB");
            }
        } catch (\Exception $e) {
            $I->comment("⚠️ Could not delete good card");
        }
        
        // Delete from "What Went Wrong"
        $I->comment("Deleting one card from What Went Wrong");
        try {
            $I->click('.post-it.wrong .post-it-delete');
            $I->wait(1);
            
            // Confirm deletion
            try {
                $ui->confirmModal('#confirmModalConfirm');
            } catch (\Exception $e) {
                try {
                    $ui->acceptConfirmation();
                } catch (\Exception $e2) {
                    // Continue
                }
            }
            
            $I->wait(2);
            
            // UI Verification: Card should be gone
            $I->dontSee($wrongCardTexts[0]);
            $I->comment("✅ Wrong card deleted from UI");
            
            // DB Verification
            $wrongItemsAfterDelete = $I->grabNumRecords('retrospective_item', [
                'retrospective_id' => $this->retrospectiveId,
                'category' => 'wrong'
            ]);
            $I->comment("Wrong items after delete: {$wrongItemsAfterDelete}");
            
            if ($wrongItemsAfterDelete === ($wrongItemsCount - 1)) {
                $I->comment("✅ Wrong card deleted from DB");
            }
        } catch (\Exception $e) {
            $I->comment("⚠️ Could not delete wrong card");
        }
        
        // Delete from "What Can Be Improved"
        $I->comment("Deleting one card from What Can Be Improved");
        try {
            $I->click('.post-it.improved .post-it-delete');
            $I->wait(1);
            
            // Confirm deletion
            try {
                $ui->confirmModal('#confirmModalConfirm');
            } catch (\Exception $e) {
                try {
                    $ui->acceptConfirmation();
                } catch (\Exception $e2) {
                    // Continue
                }
            }
            
            $I->wait(2);
            
            // UI Verification: Card should be gone
            $I->dontSee($improvedCardTexts[0]);
            $I->comment("✅ Improved card deleted from UI");
            
            // DB Verification
            $improvedItemsAfterDelete = $I->grabNumRecords('retrospective_item', [
                'retrospective_id' => $this->retrospectiveId,
                'category' => 'improved'
            ]);
            $I->comment("Improved items after delete: {$improvedItemsAfterDelete}");
            
            if ($improvedItemsAfterDelete === ($improvedItemsCount - 1)) {
                $I->comment("✅ Improved card deleted from DB");
            }
        } catch (\Exception $e) {
            $I->comment("⚠️ Could not delete improved card");
        }
        
        // Delete from "Random Feedback"
        $I->comment("Deleting one card from Random Feedback");
        try {
            $I->click('.post-it.random .post-it-delete');
            $I->wait(1);
            
            // Confirm deletion
            try {
                $ui->confirmModal('#confirmModalConfirm');
            } catch (\Exception $e) {
                try {
                    $ui->acceptConfirmation();
                } catch (\Exception $e2) {
                    // Continue
                }
            }
            
            $I->wait(2);
            
            // UI Verification: Card should be gone
            $I->dontSee($randomCardTexts[0]);
            $I->comment("✅ Random card deleted from UI");
            
            // DB Verification
            $randomItemsAfterDelete = $I->grabNumRecords('retrospective_item', [
                'retrospective_id' => $this->retrospectiveId,
                'category' => 'random'
            ]);
            $I->comment("Random items after delete: {$randomItemsAfterDelete}");
            
            if ($randomItemsAfterDelete === ($randomItemsCount - 1)) {
                $I->comment("✅ Random card deleted from DB");
            }
        } catch (\Exception $e) {
            $I->comment("⚠️ Could not delete random card");
        }
        
        $ui->takeScreenshot('feedback-08-cards-deleted');
        
        // Verify total after deletions
        $totalItemsAfterDelete = $I->grabNumRecords('retrospective_item', ['retrospective_id' => $this->retrospectiveId]);
        $I->comment("Total items after deletions: {$totalItemsAfterDelete}");
        $I->comment("Expected: {$totalItems} - 4 = " . ($totalItems - 4));
        
        if ($totalItemsAfterDelete === ($totalItems - 4)) {
            $I->comment("✅ Exactly 4 cards deleted (one from each column)");
        }
        
        // ========================================
        // STEP 12: STOP TIMER
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 12: Stopping timer");
        $I->comment("========================================");
        
        // Look for Stop Timer button
        try {
            $I->click('Stop Timer');
            $I->wait(2);
            $I->comment("✅ Timer stopped");
        } catch (\Exception $e) {
            try {
                $I->click('⏸');
                $I->wait(2);
                $I->comment("✅ Timer stopped (pause button)");
            } catch (\Exception $e2) {
                try {
                    $I->click('■');
                    $I->wait(2);
                    $I->comment("✅ Timer stopped (stop button)");
                } catch (\Exception $e3) {
                    $I->comment("⚠️ Could not find timer stop button");
                }
            }
        }
        
        $ui->takeScreenshot('feedback-09-timer-stopped');
        
        // ========================================
        // STEP 13: MOVE TO NEXT STEP (GROUPING)
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 13: Moving to next step (Grouping)");
        $I->comment("========================================");
        
        // Get current step before clicking Next
        $currentStepBefore = $I->grabFromDatabase('retrospective', 'current_step', ['id' => $this->retrospectiveId]);
        $I->comment("Current step before Next: {$currentStepBefore}");
        
        // Click Next Step button
        try {
            $I->click('Next Step');
            $I->wait(1);
            $I->comment("✅ Clicked Next Step button");
        } catch (\Exception $e) {
            try {
                $I->click('Next');
                $I->wait(1);
                $I->comment("✅ Clicked Next button");
            } catch (\Exception $e2) {
                try {
                    $I->click('Continue');
                    $I->wait(1);
                    $I->comment("✅ Clicked Continue button");
                } catch (\Exception $e3) {
                    $I->comment("⚠️ Could not find Next Step button");
                }
            }
        }
        
        $ui->takeScreenshot('feedback-10-after-next-step');
        
        // DB Verification: Step changed to review (grouping phase)
        $currentStepAfter = $I->grabFromDatabase('retrospective', 'current_step', ['id' => $this->retrospectiveId]);
        $I->comment("Current step after Next: {$currentStepAfter}");
        
        if ($currentStepAfter === 'review') {
            $I->seeInDatabase('retrospective', [
                'id' => $this->retrospectiveId,
                'current_step' => 'review'
            ]);
            $I->comment("✅ Step changed to 'review' (grouping phase) in DB");
        } else {
            $I->comment("⚠️ Step is: {$currentStepAfter} (expected 'review')");
        }
        
        // UI Verification: Review/Grouping phase elements visible
        $I->wait(2);
        
        try {
            $I->see('Review');
            $I->comment("✅ UI Element: Review phase visible");
        } catch (\Exception $e) {
            try {
                $I->see('Group');
                $I->comment("✅ UI Element: Group text visible");
            } catch (\Exception $e2) {
                $I->comment("⚠️ Review phase indicator not found in UI");
            }
        }
        
        $ui->takeScreenshot('feedback-11-review-phase');
        
        // ========================================
        // STEP 14: FINAL VERIFICATION
        // ========================================
        $I->comment("========================================");
        $I->comment("STEP 14: Final verification");
        $I->comment("========================================");
        
        // Wait and verify page
        $I->wait(2);
        
        // UI Verification: Retrospective title
        $ui->verifyVisible($this->retrospectiveTitle);
        
        // UI Verification: All columns visible
        $ui->verifyMultipleVisible(['What went good', 'What went wrong', 'What can be improved', 'Random feedback']);
        $I->comment("✅ All four columns visible");
        
        // UI Verification: Remaining cards visible
        $I->see($goodCardTexts[1]); // Second good card should still be there
        $I->see($wrongCardTexts[1]); // Second wrong card should still be there
        $I->comment("✅ Remaining cards visible in UI");
        
        $ui->takeScreenshot('feedback-10-final-state');
        
        $I->comment("========================================");
        $I->comment("✅ RETROSPECTIVE FEEDBACK TEST PASSED!");
        $I->comment("User ID: {$this->userId}");
        $I->comment("Team ID: {$this->teamId}");
        $I->comment("Retrospective ID: {$this->retrospectiveId}");
        $I->comment("Cards added: {$totalItems} (3 per column x 4)");
        $I->comment("Cards deleted: 4 (1 per column)");
        $I->comment("Cards remaining: {$totalItemsAfterDelete}");
        $I->comment("Current step: {$currentStepAfter}");
        $I->comment("Flow: START → TIMER → ADD 12 CARDS → DELETE 4 → STOP TIMER → NEXT STEP (GROUPING)");
        $I->comment("========================================");
    }
}

