<?php

namespace Tests\Support\Step\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Helper class for navigation-related test steps
 */
class NavigationSteps extends AcceptanceTester
{
    /**
     * Navigate to Dashboard
     */
    public function goToDashboard(): void
    {
        $this->click('Dashboard');
        $this->wait(2);
        $this->see('Dashboard');
    }

    /**
     * Navigate to Organizations page
     */
    public function goToOrganizations(): void
    {
        $this->click('Organizations');
        $this->wait(2);
        $this->see('Organizations');
    }

    /**
     * Navigate to Teams page
     */
    public function goToTeams(): void
    {
        $this->click('Teams');
        $this->wait(2);
        $this->see('Teams');
    }

    /**
     * Navigate to Role Management page
     */
    public function goToRoleManagement(): void
    {
        $this->click('Role Management');
        $this->wait(2);
        $this->see('Role Management');
    }

    /**
     * Navigate to Actions page
     */
    public function goToActions(): void
    {
        $this->click('Actions');
        $this->wait(2);
    }

    /**
     * Navigate to Analytics page
     */
    public function goToAnalytics(): void
    {
        $this->click('Analytics');
        $this->wait(2);
        $this->see('Analytics');
    }

    /**
     * Navigate to User Profile
     */
    public function goToUserProfile(): void
    {
        $this->click('Profile');
        $this->wait(2);
        $this->see('Profile');
    }

    /**
     * Navigate to Organization details page
     *
     * @param int $organizationId
     */
    public function goToOrganizationDetails(int $organizationId): void
    {
        $this->amOnPage("/organizations/{$organizationId}");
        $this->wait(2);
    }

    /**
     * Navigate to Team details page
     *
     * @param int $teamId
     */
    public function goToTeamDetails(int $teamId): void
    {
        $this->amOnPage("/teams/{$teamId}");
        $this->wait(2);
    }
}

