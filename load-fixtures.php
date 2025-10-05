<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\DataFixtures\RbacFixtures;
use App\DataFixtures\UserFixtures;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

// Load environment variables
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env.prod');

// Bootstrap Symfony kernel
$kernel = new \App\Kernel($_ENV['APP_ENV'] ?? 'prod', $_ENV['APP_DEBUG'] ?? false);
$kernel->boot();

// Get services from container
$container = $kernel->getContainer();
$entityManager = $container->get('doctrine.orm.entity_manager');
$passwordHasher = $container->get('security.user_password_hasher');

echo "=== Loading Production Fixtures ===\n";

try {
    // Load RBAC fixtures (roles and permissions)
    echo "Loading RBAC fixtures (roles and permissions)...\n";
    $rbacFixtures = new RbacFixtures();
    $rbacFixtures->load($entityManager);
    echo "✓ RBAC fixtures loaded successfully\n\n";

    // Load User fixtures
    echo "Loading User fixtures (test users)...\n";
    $userFixtures = new UserFixtures($passwordHasher);
    $userFixtures->load($entityManager);
    echo "✓ User fixtures loaded successfully\n\n";

    echo "=== All fixtures loaded successfully! ===\n";
    echo "Test users created:\n";
    echo "- admin@retroapp.com (password: password123)\n";
    echo "- facilitator@retroapp.com (password: password123)\n";
    echo "- member@retroapp.com (password: password123)\n";

} catch (Exception $e) {
    echo "✗ Error loading fixtures: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
