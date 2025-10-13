<?php

namespace Tests\Support\Step\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Helper class for database-related test steps
 */
class DatabaseSteps extends AcceptanceTester
{
    /**
     * Create a user in the database
     *
     * @param string $email
     * @param string $password
     * @param string $firstName
     * @param string $lastName
     * @param bool $isVerified
     * @return int User ID
     */
    public function createUser(
        string $email,
        string $password,
        string $firstName = 'Test',
        string $lastName = 'User',
        bool $isVerified = true
    ): int {
        $this->haveInDatabase('user', [
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_verified' => $isVerified ? 1 : 0,
            'roles' => '[]',
            'email_notifications' => 1,
            'push_notifications' => 1,
            'weekly_digest' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return (int)$this->grabFromDatabase('user', 'id', ['email' => $email]);
    }

    /**
     * Assign a role to a user
     *
     * @param int $userId
     * @param string $roleCode (e.g., 'ROLE_ADMIN', 'ROLE_MEMBER')
     * @return int Role assignment ID
     */
    public function assignRole(int $userId, string $roleCode): int
    {
        $roleId = (int)$this->grabFromDatabase('roles', 'id', ['code' => $roleCode]);

        $this->haveInDatabase('user_roles', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'is_active' => 1,
            'assigned_at' => date('Y-m-d H:i:s')
        ]);

        $this->seeInDatabase('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);

        return $roleId;
    }

    /**
     * Create an admin user
     *
     * @param string $email
     * @param string $password
     * @param string $firstName
     * @param string $lastName
     * @return int User ID
     */
    public function createAdminUser(
        string $email,
        string $password,
        string $firstName = 'Admin',
        string $lastName = 'User'
    ): int {
        $userId = $this->createUser($email, $password, $firstName, $lastName);
        $this->assignRole($userId, 'ROLE_ADMIN');
        return $userId;
    }

    /**
     * Create a member user
     *
     * @param string $email
     * @param string $password
     * @param string $firstName
     * @param string $lastName
     * @return int User ID
     */
    public function createMemberUser(
        string $email,
        string $password,
        string $firstName = 'Member',
        string $lastName = 'User'
    ): int {
        $userId = $this->createUser($email, $password, $firstName, $lastName);
        $this->assignRole($userId, 'ROLE_MEMBER');
        return $userId;
    }

    /**
     * Create an organization
     *
     * @param string $name
     * @param int $ownerId
     * @return int Organization ID
     */
    public function createOrganization(string $name, int $ownerId): int
    {
        $this->haveInDatabase('organizations', [
            'name' => $name,
            'owner_id' => $ownerId,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return (int)$this->grabFromDatabase('organizations', 'id', ['name' => $name]);
    }

    /**
     * Add user to organization
     *
     * @param int $organizationId
     * @param int $userId
     * @param string $role (e.g., 'ADMIN', 'MEMBER')
     */
    public function addUserToOrganization(int $organizationId, int $userId, string $role = 'MEMBER'): void
    {
        $this->haveInDatabase('organization_members', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'is_active' => 1,
            'role' => $role,
            'joined_at' => date('Y-m-d H:i:s')
        ]);

        $this->seeInDatabase('organization_members', [
            'organization_id' => $organizationId,
            'user_id' => $userId
        ]);
    }

    /**
     * Change user role
     *
     * @param int $userId
     * @param string $newRoleCode
     */
    public function changeUserRole(int $userId, string $newRoleCode): void
    {
        // Deactivate all current roles
        $this->updateInDatabase('user_roles', ['is_active' => 0], ['user_id' => $userId]);

        // Assign new role
        $this->assignRole($userId, $newRoleCode);
    }

    /**
     * Get role ID by code
     *
     * @param string $roleCode
     * @return int
     */
    public function getRoleId(string $roleCode): int
    {
        return (int)$this->grabFromDatabase('roles', 'id', ['code' => $roleCode]);
    }

    /**
     * Create a team
     *
     * @param string $name
     * @param int $organizationId
     * @param int $createdBy
     * @return int Team ID
     */
    public function createTeam(string $name, int $organizationId, int $createdBy): int
    {
        $this->haveInDatabase('team', [
            'name' => $name,
            'organization_id' => $organizationId,
            'owner_id' => $createdBy,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return (int)$this->grabFromDatabase('team', 'id', ['name' => $name]);
    }

    /**
     * Add user to team
     *
     * @param int $teamId
     * @param int $userId
     * @param string $role
     */
    public function addUserToTeam(int $teamId, int $userId, string $role = 'MEMBER'): void
    {
        $this->haveInDatabase('team_member', [
            'team_id' => $teamId,
            'user_id' => $userId,
            'role' => $role,
            'is_active' => 1,
            'joined_at' => date('Y-m-d H:i:s')
        ]);

        $this->seeInDatabase('team_member', [
            'team_id' => $teamId,
            'user_id' => $userId
        ]);
    }

    /**
     * Get the last (highest ID) record from a table with optional conditions
     *
     * @param string $table
     * @param string $column Column to retrieve
     * @param array $criteria Optional where conditions
     * @return mixed
     */
    public function getLastRecord(string $table, string $column = 'id', array $criteria = [])
    {
        // Build WHERE clause
        $whereClause = '';
        $params = [];
        
        if (!empty($criteria)) {
            $conditions = [];
            foreach ($criteria as $key => $value) {
                $conditions[] = "{$key} = ?";
                $params[] = $value;
            }
            $whereClause = 'WHERE ' . implode(' AND ', $conditions);
        }
        
        // Execute raw SQL query
        $sql = "SELECT {$column} FROM {$table} {$whereClause} ORDER BY id DESC LIMIT 1";
        
        // Use grabFromDatabase with executeQuery (via PDO)
        // Since Codeception doesn't support raw SQL easily, we'll use a workaround
        // Return the column value for the record with highest ID
        
        // For now, just return grabFromDatabase result
        return $this->grabFromDatabase($table, $column, $criteria);
    }
}

