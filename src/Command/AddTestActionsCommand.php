<?php

namespace App\Command;

use App\Entity\RetrospectiveAction;
use App\Entity\Retrospective;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:add-test-actions',
    description: 'Add 100 test actions to database',
)]
class AddTestActionsCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Sample action descriptions
        $testActions = [
            "Implementar mejoras en la experiencia de usuario del panel principal",
            "Optimizar consultas de base de datos para reducir latencia", 
            "Actualizar documentación técnica del API",
            "Revisar y corregir errores en el sistema de autenticación",
            "Mejorar el rendimiento de carga de imágenes",
            "Implementar nueva funcionalidad de notificaciones push",
            "Actualizar dependencias de seguridad",
            "Revisar diseño responsive en dispositivos móviles",
            "Optimizar algoritmo de búsqueda",
            "Implementar sistema de backup automático",
            "Mejorar logs de auditoría del sistema",
            "Revisar y actualizar políticas de privacidad",
            "Implementar nuevo dashboard de métricas",
            "Optimizar endpoint de sincronización",
            "Revisar código legacy y refactorizar",
            "Implementar pruebas automatizadas para módulo crítico",
            "Mejorar sistema de caché distribuido",
            "Actualizar interfaz de administración",
            "Revisar vulnerabilidades de seguridad",
            "Implementar sistema de alertas en tiempo real",
            "Optimizar proceso de migración de datos",
            "Mejorar sistema de monitoreo y alertas",
            "Revisar y actualizar configuración de servidores",
            "Implementar backup incremental automático",
            "Mejorar sistema de gestión de permisos",
            "Actualizar biblioteca de componentes UI",
            "Optimizar consultas complejas de reportes",
            "Implementar sistema de auditoría granular",
            "Mejorar experiencia de onboarding de usuarios",
            "Revisar y optimizar estructura de base de datos",
            "Implementar nuevas métricas de negocio",
            "Mejorar sistema de notificaciones por email",
            "Actualizar proceso de despliegue automatizado",
            "Optimizar uso de memoria en aplicaciones",
            "Implementar sistema de versionado de datos",
            "Mejorar proceso de recuperación ante fallos",
            "Revisar y actualizar políticas de cache",
            "Implementar sistema de análisis de rendimiento",
            "Mejorar funcional inlidad de exportación de datos",
            "Actualizar sistema de gestión de sesiones",
            "Optimizar algoritmos de ordenamiento",
            "Implementar sistema de validación de entrada",
            "Mejorar interfaz de gestión de usuarios",
            "Revisar y actualizar políticas de backup",
            "Implementar nuevas funcionalidades de búsqueda",
            "Optimizar procesamiento de archivos grandes",
            "Mejorar sistema de gestión de errores",
            "Actualizar configuración de logs centralizados",
            "Implementar sistema de métricas en tiempo real",
            "Mejorar proceso de sincronización de datos"
        ];

        // Get existing retrospective and users
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->findOneBy([]);
        
        if (!$retrospective) {
            $io->error('No retrospective found! Please ensure at least one retrospective exists.');
            return Command::FAILURE;
        }

        // Get team members
        $teamMembers = [];
        $owner = $retrospective->getTeam()->getOwner();
        
        // Add team owner
        $teamMembers[] = $owner;
        
        // Add team members  
        foreach ($retrospective->getTeam()->getTeamMembers() as $member) {
            if ($member->getUser() !== $owner) {
                $teamMembers[] = $member->getUser();
            }
        }

        // If no members, use owner as assignee
        if (empty($teamMembers)) {
            $teamMembers[] = $owner;
        }

        $statuses = ['pending', 'in-progress', 'completed', 'cancelled'];
        $creator = $owner;

        $io->info('Creating 100 test actions...');

        for ($i = 0; $i < 100; $i++) {
            $action = new RetrospectiveAction();
            
            // Random description
            $baseDescription = $testActions[array_rand($testActions)];
            $variation = $i + 1;
            $action->setDescription("{$baseDescription} (#{$variation})");
            
            // Random status
            $status = $statuses[array_rand($statuses)];
            $action->setStatus($status);
            
            // Always assign someone (since DB doesn't allow null)
            $assignedUser = $teamMembers[array_rand($teamMembers)];
            $action->setAssignedTo($assignedUser);
            
            // Random due date (60% chance for pending/in-progress)
            if (in_array($status, ['pending', 'in-progress']) && rand(1, 10) <= 6) {
                $daysOffset = rand(-30, 90);
                $dueDate = new \DateTime();
                $dueDate->modify("+{$daysOffset} days");
                $action->setDueDate($dueDate);
            }
            
            // Set completion date if completed
            if ($status === 'completed') {
                $action->setCompletedAt(new \DateTime());
            }
            
            $action->setRetrospective($retrospective);
            $action->setCreatedBy($creator);
            $action->setCreatedAt(new \DateTime());
            $action->setUpdatedAt(new \DateTime());
            
            $this->entityManager->persist($action);
            
            if (($i + 1) % 20 === 0) {
                $io->info("Created " . ($i + 1) . " actions...");
            }
        }

        $this->entityManager->flush();

        $io->success('Successfully created 100 test actions!');

        return Command::SUCCESS;
    }

    protected function configure(): void
    {
        $this->setDescription('Add 100 test actions to the database');
    }
}
