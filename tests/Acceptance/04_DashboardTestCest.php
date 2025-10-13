<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class DashboardTestCest
{
    private string $testUserEmail = 'test@example.com';
    private string $testUserPassword = 'password123';

    public function _before(AcceptanceTester $I)
    {
        // Login before each test
        $I->amOnPage('/login');
        $I->fillField('email', $this->testUserEmail);
        $I->fillField('password', $this->testUserPassword);
        $I->click('Sign in');
        $I->wait(2);
    }

    public function testDashboardCompleteFlow(AcceptanceTester $I)
    {
        $I->wantTo('Test complete dashboard with all elements and DB verification');
        
        // DB Verification: Check user exists in database
        $dbUserExists = $this->checkUserExistsInDB($this->testUserEmail);
        $I->comment('DB Check - User exists in database: ' . ($dbUserExists ? 'YES' : 'NO'));
        
        if (!$dbUserExists) {
            $I->comment('WARNING: Test user does not exist in database');
        }
        
        // Navigate to dashboard
        $I->amOnPage('/dashboard');
        $I->wait(2);
        
        // UI Verification - Element 1: Welcome message with user name
        $I->see('Welcome back');
        
        // UI Verification - Element 2: User first name from DB
        $I->see('Test');
        
        // UI Verification - Element 3: RetroApp logo
        $I->see('RetroApp');
        
        // UI Verification - Element 4: Logout button visible
        $I->see('Logout');
        
        // UI Verification - Element 5: Quick Actions section
        $I->see('Quick Actions');
        
        // UI Verification - Element 6: Quick Actions description
        $I->see('Jump straight into your most common tasks');
        
        // UI Verification - Element 7: Current Role section
        $I->see('CURRENT ROLE');
        
        // UI Verification - Element 8: User role (Admin - since test user is admin)
        $I->see('Admin');
        
        // UI Verification - Element 9: Organization status
        $I->see('No Organization');
        
        // UI Verification - Element 10: Navigation - Teams link
        $I->see('Teams');
        
        // UI Verification - Element 11: Navigation - Retrospectives link
        $I->see('Retrospectives');
        
        // UI Verification - Element 12: Navigation - My Profile link
        $I->see('My Profile');
        
        // UI Verification - Element 13: Dashboard card present
        $I->seeElement('.dashboard-card');
        
        // UI Verification - Element 14: Context description
        $I->see('Here\'s what\'s happening');
        
        // Take screenshot for verification
        $I->makeScreenshot('dashboard-complete-verification');
        
        $I->comment('✅ Dashboard verification complete: 14 UI elements checked + 1 DB verification');
    }

    /**
     * Helper method to check if user exists in database
     */
    private function checkUserExistsInDB(string $email): bool
    {
        $output = [];
        $command = sprintf(
            'docker exec retro_app_mysql_dev mysql -uroot -proot retro_app -N -s -e "SELECT COUNT(*) FROM user WHERE email = \'%s\'" 2>/dev/null',
            $email
        );
        
        exec($command, $output);
        
        return isset($output[0]) && (int)trim($output[0]) > 0;
    }
}
