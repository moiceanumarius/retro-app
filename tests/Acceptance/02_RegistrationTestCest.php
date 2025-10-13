<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\Step\Acceptance\AuthSteps;
use Tests\Support\Step\Acceptance\UISteps;

class RegistrationTestCest
{
    public function testSuccessfulRegistration(AcceptanceTester $I, AuthSteps $auth, UISteps $ui)
    {
        $I->wantTo('Test successful user registration');
        
        $timestamp = time();
        $email = "testuser{$timestamp}@example.com";
        
        $I->amOnPage('/register');
        $ui->verifyVisible('Create your account');
        
        $auth->register('Test', 'User', $email, 'TestPassword123!');
        
        // Should be redirected away from registration page
        $I->dontSeeInCurrentUrl('/register');
    }

    public function testRegistrationWithExistingEmail(AcceptanceTester $I)
    {
        $I->wantTo('Test registration with existing email');
        
        $I->amOnPage('/register');
        $I->see('Create your account');
        
        $I->fillField('registration_form[firstName]', 'Test');
        $I->fillField('registration_form[lastName]', 'User');
        $I->fillField('registration_form[email]', 'test@example.com'); // Existing user
        $I->fillField('registration_form[plainPassword]', 'TestPassword123!');
        $I->checkOption('registration_form[agreeTerms]');
        
        $I->click('Create Account');
        
        $I->wait(1);
        
        // Should stay on registration page or show error
        $I->seeInCurrentUrl('/register');
    }

    public function testRegistrationPageElements(AcceptanceTester $I, UISteps $ui)
    {
        $I->wantTo('Test that registration page has required elements');
        
        $I->amOnPage('/register');
        
        $ui->verifyMultipleVisible(['RetroApp', 'Create your account']);
        $I->seeElement('input[name="registration_form[firstName]"]');
        $I->seeElement('input[name="registration_form[lastName]"]');
        $I->seeElement('input[name="registration_form[email]"]');
        $I->seeElement('input[name="registration_form[plainPassword]"]');
        $I->seeElement('input[name="registration_form[agreeTerms]"]');
        $I->seeElement('button[type="submit"]');
    }

    public function testRegistrationWithWeakPassword(AcceptanceTester $I)
    {
        $I->wantTo('Test registration with weak password');
        
        $timestamp = time();
        $email = "testuser{$timestamp}@example.com";
        
        $I->amOnPage('/register');
        
        $I->fillField('registration_form[firstName]', 'Test');
        $I->fillField('registration_form[lastName]', 'User');
        $I->fillField('registration_form[email]', $email);
        $I->fillField('registration_form[plainPassword]', '123'); // Weak password
        $I->checkOption('registration_form[agreeTerms]');
        
        $I->click('Create Account');
        
        $I->wait(2);
        
        // Should stay on registration page
        $I->seeInCurrentUrl('/register');
    }

    public function testRegistrationWithoutTermsAgreement(AcceptanceTester $I)
    {
        $I->wantTo('Test registration without accepting terms');
        
        $timestamp = time();
        $email = "testuser{$timestamp}@example.com";
        
        $I->amOnPage('/register');
        
        $I->fillField('registration_form[firstName]', 'Test');
        $I->fillField('registration_form[lastName]', 'User');
        $I->fillField('registration_form[email]', $email);
        $I->fillField('registration_form[plainPassword]', 'TestPassword123!');
        // Don't check terms checkbox
        
        $I->click('Create Account');
        
        $I->wait(2);
        
        // Should stay on registration page
        $I->seeInCurrentUrl('/register');
    }

    public function testNavigationToLoginFromRegistration(AcceptanceTester $I)
    {
        $I->wantTo('Test navigation from registration to login page');
        
        $I->amOnPage('/register');
        
        // Look for "Sign in" or "Already have an account" link
        $I->click('Sign in');
        
        $I->wait(2);
        
        // Should be on login page
        $I->seeInCurrentUrl('/login');
        $I->see('Sign in to your account');
    }
}
