<?php

namespace Tests\Support\Step\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Helper class for UI interaction test steps
 */
class UISteps extends AcceptanceTester
{
    /**
     * Select user from custom dropdown by email
     *
     * @param string $dropdownButtonId
     * @param string $searchInputId
     * @param string $userEmail
     */
    public function selectUserFromDropdown(
        string $dropdownButtonId,
        string $searchInputId,
        string $userEmail
    ): void {
        // Open dropdown
        $this->click("#{$dropdownButtonId}");
        $this->wait(1);

        // Search for user
        $this->fillField("#{$searchInputId}", $userEmail);
        $this->wait(2);

        // Select user
        $this->click('.user-option');
        $this->wait(1);
    }

    /**
     * Wait for DataTable to load
     *
     * @param int $seconds
     */
    public function waitForDataTable(int $seconds = 3): void
    {
        $this->wait($seconds);
    }

    /**
     * Scroll to top of page
     */
    public function scrollToTop(): void
    {
        $this->executeJS('window.scrollTo(0, 0)');
        $this->wait(1);
    }

    /**
     * Scroll to bottom of page
     */
    public function scrollToBottom(): void
    {
        $this->executeJS('window.scrollTo(0, document.body.scrollHeight)');
        $this->wait(1);
    }

    /**
     * Scroll to element
     *
     * @param string $selector
     */
    public function scrollToElement(string $selector): void
    {
        $this->scrollTo($selector);
        $this->wait(1);
    }

    /**
     * Accept confirmation popup (JavaScript alert)
     */
    public function acceptConfirmation(): void
    {
        $this->acceptPopup();
        $this->wait(2);
    }

    /**
     * Click confirm button in modal
     *
     * @param string $modalConfirmButtonId
     */
    public function confirmModal(string $modalConfirmButtonId = '#confirmModalConfirm'): void
    {
        $this->click($modalConfirmButtonId);
        $this->wait(2);
    }

    /**
     * Fill form and submit
     *
     * @param array $fields Array of field selectors and values
     * @param string $submitButtonText
     */
    public function fillFormAndSubmit(array $fields, string $submitButtonText): void
    {
        foreach ($fields as $selector => $value) {
            if (is_array($value)) {
                // For select/checkbox options
                $this->selectOption($selector, $value['value']);
            } else {
                $this->fillField($selector, $value);
            }
            $this->wait(1);
        }

        $this->click($submitButtonText);
        $this->wait(1);
    }

    /**
     * Verify element is visible
     *
     * @param string $text
     */
    public function verifyVisible(string $text): void
    {
        $this->see($text);
    }

    /**
     * Verify multiple elements are visible
     *
     * @param array $texts
     */
    public function verifyMultipleVisible(array $texts): void
    {
        foreach ($texts as $text) {
            $this->see($text);
        }
    }

    /**
     * Take screenshot with description in filename
     *
     * @param string $name
     */
    public function takeScreenshot(string $name): void
    {
        $this->makeScreenshot($name);
    }

    /**
     * Click remove/delete button and confirm
     *
     * @param string $buttonSelector
     * @param bool $useModal If true, clicks modal confirm button; if false, accepts popup
     */
    public function clickRemoveAndConfirm(string $buttonSelector = 'button.btn-danger-modern', bool $useModal = false): void
    {
        $this->click($buttonSelector);
        $this->wait(2);

        if ($useModal) {
            $this->confirmModal();
        } else {
            $this->acceptConfirmation();
        }

        $this->wait(2);
    }

    /**
     * Select role from dropdown and assign
     *
     * @param string $userEmail
     * @param string $roleCode
     */
    public function assignRoleToUser(string $userEmail, string $roleCode): void
    {
        // Scroll to top
        $this->scrollToTop();
        $this->wait(1);

        // Select user
        $this->selectUserFromDropdown('userDropdownButton', 'userSearchInput', $userEmail);

        // Select role
        $roleId = $this->getRoleId($roleCode);
        $this->selectOption('#role_id', $roleId);
        $this->wait(1);

        // Submit
        $this->click('Assign Role');
        $this->wait(1);
    }

    /**
     * Get role ID from database (helper for UI operations)
     *
     * @param string $roleCode
     * @return int
     */
    private function getRoleId(string $roleCode): int
    {
        return (int)$this->grabFromDatabase('roles', 'id', ['code' => $roleCode]);
    }
}

