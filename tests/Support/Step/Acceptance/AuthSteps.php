<?php

namespace Tests\Support\Step\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Helper class for authentication-related test steps
 */
class AuthSteps extends AcceptanceTester
{
    /**
     * Login with email and password
     *
     * @param string $email
     * @param string $password
     */
    public function login(string $email, string $password): void
    {
        $this->amOnPage('/login');
        $this->fillField('email', $email);
        $this->fillField('password', $password);
        $this->click('Sign in');
        $this->wait(1);
    }

    /**
     * Login as admin user
     *
     * @param string $email
     * @param string $password
     */
    public function loginAsAdmin(string $email, string $password): void
    {
        $this->login($email, $password);
        $this->see('Dashboard'); // Verify we're logged in
    }

    /**
     * Logout from application
     */
    public function logout(): void
    {
        $this->click('Logout');
        $this->wait(2);
        $this->see('Login');
    }

    /**
     * Register new user
     *
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param string $password
     */
    public function register(string $firstName, string $lastName, string $email, string $password): void
    {
        $this->amOnPage('/register');
        $this->fillField('registration_form[firstName]', $firstName);
        $this->fillField('registration_form[lastName]', $lastName);
        $this->fillField('registration_form[email]', $email);
        $this->fillField('registration_form[plainPassword]', $password);
        $this->checkOption('registration_form[agreeTerms]');
        $this->click('Create Account');
        $this->wait(1);
    }
}

