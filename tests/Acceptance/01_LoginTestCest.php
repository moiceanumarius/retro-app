<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\Step\Acceptance\AuthSteps;
use Tests\Support\Step\Acceptance\UISteps;

class LoginTestCest
{
    public function testSuccessfulLogin(AcceptanceTester $I, AuthSteps $auth, UISteps $ui)
    {
        $I->wantTo('Test successful login');
        
        $I->amOnPage('/login');
        $ui->verifyVisible('Sign in to your account');
        
        $auth->login('test@example.com', 'password123');
        
        // Check if we're redirected away from login page
        $I->dontSeeInCurrentUrl('/login');
    }

    public function testFailedLogin(AcceptanceTester $I, UISteps $ui)
    {
        $I->wantTo('Test failed login with invalid credentials');
        
        $I->amOnPage('/login');
        $ui->verifyVisible('Sign in to your account');
        
        $I->fillField('email', 'invalid@example.com');
        $I->fillField('password', 'wrongpassword');
        $I->click('Sign in');
        
        $I->wait(1);
        
        // Should still be on login page
        $I->seeInCurrentUrl('/login');
    }

    public function testLoginPageElements(AcceptanceTester $I, UISteps $ui)
    {
        $I->wantTo('Test that login page has required elements');
        
        $I->amOnPage('/login');
        
        $ui->verifyMultipleVisible(['RetroApp', 'Sign in to your account']);
        $I->seeElement('input[name="email"]');
        $I->seeElement('input[name="password"]');
        $I->seeElement('button[type="submit"]');
    }
}
