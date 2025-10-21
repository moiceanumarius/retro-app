<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class LoginTestCest
{
    public function _before(AcceptanceTester $I)
    {
        // Perform any setup before each test
    }

    public function testSuccessfulLogin(AcceptanceTester $I)
    {
        $I->wantTo('Test successful login');
        
        $I->amOnPage('/login');
        $I->see('Sign in to your account');
        
        $I->fillField('email', 'test@example.com');
        $I->fillField('password', 'password123');
        $I->click('Sign in');
        
        $I->wait(3);
        
        // Check if we're redirected away from login page
        $I->dontSeeInCurrentUrl('/login');
    }

    public function testFailedLogin(AcceptanceTester $I)
    {
        $I->wantTo('Test failed login with invalid credentials');
        
        $I->amOnPage('/login');
        $I->see('Sign in to your account');
        
        $I->fillField('email', 'invalid@example.com');
        $I->fillField('password', 'wrongpassword');
        $I->click('Sign in');
        
        $I->wait(3);
        
        // Should still be on login page
        $I->seeInCurrentUrl('/login');
    }

    public function testLoginPageElements(AcceptanceTester $I)
    {
        $I->wantTo('Test that login page has required elements');
        
        $I->amOnPage('/login');
        
        $I->see('RetroApp');
        $I->see('Sign in to your account');
        $I->seeElement('input[name="email"]');
        $I->seeElement('input[name="password"]');
        $I->seeElement('button[type="submit"]');
    }
}
