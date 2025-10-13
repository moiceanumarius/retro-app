<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\Role;
use App\Entity\UserRole;
use App\Service\RoleHierarchyService;
use PHPUnit\Framework\TestCase;

class RoleHierarchyServiceTest extends TestCase
{
    private RoleHierarchyService $service;

    protected function setUp(): void
    {
        $this->service = new RoleHierarchyService();
    }

    public function testGetRoleHierarchy(): void
    {
        $hierarchy = $this->service->getRoleHierarchy();

        $this->assertIsArray($hierarchy);
        $this->assertArrayHasKey('ROLE_MEMBER', $hierarchy);
        $this->assertArrayHasKey('ROLE_FACILITATOR', $hierarchy);
        $this->assertArrayHasKey('ROLE_SUPERVISOR', $hierarchy);
        $this->assertArrayHasKey('ROLE_ADMIN', $hierarchy);
        
        // Verify hierarchy levels
        $this->assertEquals(1, $hierarchy['ROLE_MEMBER']);
        $this->assertEquals(2, $hierarchy['ROLE_FACILITATOR']);
        $this->assertEquals(3, $hierarchy['ROLE_SUPERVISOR']);
        $this->assertEquals(4, $hierarchy['ROLE_ADMIN']);
    }

    public function testGetRoleLevel(): void
    {
        $this->assertEquals(1, $this->service->getRoleLevel('ROLE_MEMBER'));
        $this->assertEquals(2, $this->service->getRoleLevel('ROLE_FACILITATOR'));
        $this->assertEquals(3, $this->service->getRoleLevel('ROLE_SUPERVISOR'));
        $this->assertEquals(4, $this->service->getRoleLevel('ROLE_ADMIN'));
        $this->assertEquals(0, $this->service->getRoleLevel('ROLE_UNKNOWN'));
    }

    public function testGetUserRoleLevel(): void
    {
        // Create mock user with ROLE_ADMIN
        $user = $this->createMockUser(['ROLE_ADMIN']);
        $this->assertEquals(4, $this->service->getUserRoleLevel($user));

        // Create mock user with ROLE_MEMBER
        $user = $this->createMockUser(['ROLE_MEMBER']);
        $this->assertEquals(1, $this->service->getUserRoleLevel($user));

        // Create mock user with multiple roles (should return highest)
        $user = $this->createMockUser(['ROLE_MEMBER', 'ROLE_SUPERVISOR']);
        $this->assertEquals(3, $this->service->getUserRoleLevel($user));
    }

    public function testCanManageUser(): void
    {
        $admin = $this->createMockUser(['ROLE_ADMIN']);
        $supervisor = $this->createMockUser(['ROLE_SUPERVISOR']);
        $member = $this->createMockUser(['ROLE_MEMBER']);

        // Admin can manage supervisor and member
        $this->assertTrue($this->service->canManageUser($admin, $supervisor));
        $this->assertTrue($this->service->canManageUser($admin, $member));

        // Supervisor can manage member but not admin
        $this->assertTrue($this->service->canManageUser($supervisor, $member));
        $this->assertFalse($this->service->canManageUser($supervisor, $admin));

        // Member cannot manage anyone
        $this->assertFalse($this->service->canManageUser($member, $admin));
        $this->assertFalse($this->service->canManageUser($member, $supervisor));
    }

    public function testGetManageableRoleCodes(): void
    {
        // Admin can manage all roles below ADMIN (SUPERVISOR, FACILITATOR, MEMBER)
        $admin = $this->createMockUser(['ROLE_ADMIN']);
        $manageable = $this->service->getManageableRoleCodes($admin);
        
        $this->assertContains('ROLE_SUPERVISOR', $manageable);
        $this->assertContains('ROLE_FACILITATOR', $manageable);
        $this->assertContains('ROLE_MEMBER', $manageable);
        $this->assertNotContains('ROLE_ADMIN', $manageable);

        // Supervisor can manage FACILITATOR and MEMBER
        $supervisor = $this->createMockUser(['ROLE_SUPERVISOR']);
        $manageable = $this->service->getManageableRoleCodes($supervisor);
        
        $this->assertContains('ROLE_FACILITATOR', $manageable);
        $this->assertContains('ROLE_MEMBER', $manageable);
        $this->assertNotContains('ROLE_SUPERVISOR', $manageable);
        $this->assertNotContains('ROLE_ADMIN', $manageable);

        // Member cannot manage any roles
        $member = $this->createMockUser(['ROLE_MEMBER']);
        $manageable = $this->service->getManageableRoleCodes($member);
        
        $this->assertEmpty($manageable);
    }

    public function testGetAssignableRoleCodes(): void
    {
        // Admin can assign all roles including their own level
        $admin = $this->createMockUser(['ROLE_ADMIN']);
        $assignable = $this->service->getAssignableRoleCodes($admin);
        
        $this->assertContains('ROLE_ADMIN', $assignable);
        $this->assertContains('ROLE_SUPERVISOR', $assignable);
        $this->assertContains('ROLE_FACILITATOR', $assignable);
        $this->assertContains('ROLE_MEMBER', $assignable);

        // Member can only see their own level
        $member = $this->createMockUser(['ROLE_MEMBER']);
        $assignable = $this->service->getAssignableRoleCodes($member);
        
        $this->assertContains('ROLE_MEMBER', $assignable);
        $this->assertCount(1, $assignable);
    }

    public function testFilterRolesByHierarchy(): void
    {
        $admin = $this->createMockUser(['ROLE_ADMIN']);
        
        // Create mock roles
        $roles = [
            $this->createMockRole('ROLE_ADMIN', 'Administrator'),
            $this->createMockRole('ROLE_SUPERVISOR', 'Supervisor'),
            $this->createMockRole('ROLE_FACILITATOR', 'Facilitator'),
            $this->createMockRole('ROLE_MEMBER', 'Member'),
        ];

        $filtered = $this->service->filterRolesByHierarchy($roles, $admin);

        // Admin should see all roles except ADMIN (can't assign their own level or higher)
        $this->assertCount(3, $filtered);
        
        $codes = array_map(fn($role) => $role->getCode(), $filtered);
        $this->assertNotContains('ROLE_ADMIN', $codes);
        $this->assertContains('ROLE_SUPERVISOR', $codes);
        $this->assertContains('ROLE_FACILITATOR', $codes);
        $this->assertContains('ROLE_MEMBER', $codes);
    }

    public function testFilterUsersByRoleHierarchy(): void
    {
        $admin = $this->createMockUser(['ROLE_ADMIN'], 1);
        $supervisor = $this->createMockUser(['ROLE_SUPERVISOR'], 2);
        $member = $this->createMockUser(['ROLE_MEMBER'], 3);
        
        $users = [$admin, $supervisor, $member];

        // Admin filtering users - should see all except themselves
        $filtered = $this->service->filterUsersByRoleHierarchy($users, $admin);
        $this->assertCount(2, $filtered); // supervisor and member
        $this->assertNotContains($admin, $filtered);
        $this->assertContains($supervisor, $filtered);
        $this->assertContains($member, $filtered);

        // Supervisor filtering users - should see member only
        $filtered = $this->service->filterUsersByRoleHierarchy($users, $supervisor);
        $this->assertCount(1, $filtered); // only member
        $this->assertContains($member, $filtered);
        $this->assertNotContains($admin, $filtered);
        $this->assertNotContains($supervisor, $filtered);
    }

    /**
     * Create mock user with roles
     */
    private function createMockUser(array $roles, ?int $id = null): User
    {
        $user = $this->createMock(User::class);
        
        if ($id !== null) {
            $user->method('getId')->willReturn($id);
        }
        
        $user->method('getAllRolesIncludingInherited')->willReturn($roles);
        
        return $user;
    }

    /**
     * Create mock role
     */
    private function createMockRole(string $code, string $name): Role
    {
        $role = $this->createMock(Role::class);
        $role->method('getCode')->willReturn($code);
        $role->method('getName')->willReturn($name);
        
        return $role;
    }
}

