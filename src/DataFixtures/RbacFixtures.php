<?php

namespace App\DataFixtures;

use App\Entity\Permission;
use App\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class RbacFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Create Permissions
        $permissions = [
            // Team permissions
            ['name' => 'Create Team', 'code' => 'team.create', 'description' => 'Can create new teams', 'resource' => 'team', 'action' => 'create'],
            ['name' => 'Edit Team', 'code' => 'team.edit', 'description' => 'Can edit team details', 'resource' => 'team', 'action' => 'edit'],
            ['name' => 'Delete Team', 'code' => 'team.delete', 'description' => 'Can delete teams', 'resource' => 'team', 'action' => 'delete'],
            ['name' => 'View Team', 'code' => 'team.view', 'description' => 'Can view team details', 'resource' => 'team', 'action' => 'view'],
            ['name' => 'Manage Team Members', 'code' => 'team.members', 'description' => 'Can add/remove team members', 'resource' => 'team', 'action' => 'members'],

            // Retrospective permissions
            ['name' => 'Create Retrospective', 'code' => 'retro.create', 'description' => 'Can create new retrospectives', 'resource' => 'retrospective', 'action' => 'create'],
            ['name' => 'Edit Retrospective', 'code' => 'retro.edit', 'description' => 'Can edit retrospective details', 'resource' => 'retrospective', 'action' => 'edit'],
            ['name' => 'Delete Retrospective', 'code' => 'retro.delete', 'description' => 'Can delete retrospectives', 'resource' => 'retrospective', 'action' => 'delete'],
            ['name' => 'View Retrospective', 'code' => 'retro.view', 'description' => 'Can view retrospectives', 'resource' => 'retrospective', 'action' => 'view'],
            ['name' => 'Facilitate Retrospective', 'code' => 'retro.facilitate', 'description' => 'Can facilitate retrospectives', 'resource' => 'retrospective', 'action' => 'facilitate'],
            ['name' => 'Participate in Retrospective', 'code' => 'retro.participate', 'description' => 'Can participate in retrospectives', 'resource' => 'retrospective', 'action' => 'participate'],

            // User permissions
            ['name' => 'Invite Users', 'code' => 'user.invite', 'description' => 'Can invite new users', 'resource' => 'user', 'action' => 'invite'],
            ['name' => 'Manage Users', 'code' => 'user.manage', 'description' => 'Can manage user accounts', 'resource' => 'user', 'action' => 'manage'],
            ['name' => 'View Users', 'code' => 'user.view', 'description' => 'Can view user profiles', 'resource' => 'user', 'action' => 'view'],

            // Organization permissions
            ['name' => 'Manage Organization', 'code' => 'org.manage', 'description' => 'Can manage organization settings', 'resource' => 'organization', 'action' => 'manage'],
            ['name' => 'View Organization', 'code' => 'org.view', 'description' => 'Can view organization details', 'resource' => 'organization', 'action' => 'view'],

            // Admin permissions
            ['name' => 'System Administration', 'code' => 'admin.system', 'description' => 'Full system administration access', 'resource' => 'system', 'action' => 'admin'],
            ['name' => 'Manage Roles', 'code' => 'admin.roles', 'description' => 'Can manage roles and permissions', 'resource' => 'admin', 'action' => 'roles'],
        ];

        $permissionEntities = [];
        foreach ($permissions as $permissionData) {
            $permission = new Permission();
            $permission->setName($permissionData['name']);
            $permission->setCode($permissionData['code']);
            $permission->setDescription($permissionData['description']);
            $permission->setResource($permissionData['resource']);
            $permission->setAction($permissionData['action']);
            $permission->setCreatedAt(new \DateTimeImmutable());
            $permission->setUpdatedAt(new \DateTimeImmutable());

            $manager->persist($permission);
            $permissionEntities[$permissionData['code']] = $permission;
        }

        // Create Roles
        $roles = [
            [
                'name' => 'Administrator',
                'code' => 'ROLE_ADMIN',
                'description' => 'Full system access with all permissions',
                'permissions' => array_keys($permissionEntities) // All permissions
            ],
            [
                'name' => 'Facilitator',
                'code' => 'ROLE_FACILITATOR',
                'description' => 'Can facilitate retrospectives and manage teams',
                'permissions' => [
                    'team.create', 'team.edit', 'team.view', 'team.members',
                    'retro.create', 'retro.edit', 'retro.view', 'retro.facilitate', 'retro.participate',
                    'user.invite', 'user.view',
                    'org.view'
                ]
            ],
            [
                'name' => 'Member',
                'code' => 'ROLE_MEMBER',
                'description' => 'Can participate in retrospectives',
                'permissions' => [
                    'team.view',
                    'retro.view', 'retro.participate',
                    'user.view',
                    'org.view'
                ]
            ],
            [
                'name' => 'Supervisor',
                'code' => 'ROLE_SUPERVISOR',
                'description' => 'Can manage team and facilitate retrospectives',
                'permissions' => [
                    'team.create', 'team.edit', 'team.view', 'team.members',
                    'retro.create', 'retro.edit', 'retro.view', 'retro.facilitate', 'retro.participate',
                    'user.invite', 'user.view',
                    'org.view'
                ]
            ]
        ];

        $roleEntities = [];
        foreach ($roles as $roleData) {
            $role = new Role();
            $role->setName($roleData['name']);
            $role->setCode($roleData['code']);
            $role->setDescription($roleData['description']);
            $role->setCreatedAt(new \DateTimeImmutable());
            $role->setUpdatedAt(new \DateTimeImmutable());

            // Add permissions to role
            foreach ($roleData['permissions'] as $permissionCode) {
                if (isset($permissionEntities[$permissionCode])) {
                    $role->addPermission($permissionEntities[$permissionCode]);
                }
            }

            $manager->persist($role);
            $roleEntities[$roleData['code']] = $role;
        }

        $manager->flush();

        // Add references for use in other fixtures
        foreach ($roleEntities as $code => $role) {
            $this->addReference('role_' . strtolower(str_replace('ROLE_', '', $code)), $role);
        }
    }
}
