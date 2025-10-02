<?php

namespace App\DataFixtures;

use App\Entity\RetrospectiveAction;
use App\Entity\Retrospective;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class TestActionsFixtures extends Fixture
{
    private $testActions = [
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
        "Mejorar funcionalidad de exportación de datos",
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
        "Mejorar proceso de sincronización de datos",
        "Revisar código para vulnerabilidades de seguridad",
        "Implementar sistema de backup cifrado",
        "Optimizar consumo de recursos de sistema",
        "Mejorar funcIONALIDAD DE REPORTES Y ANALÍTICAS",
        "Actualizar sistema de autenticación multifactor",
        "Implementar caching inteligente para mejorar rendimiento",
        "Mejorar experiencia de usuario en formularios complejos",
        "Optimizar proceso de migración entre versiones",
        "Revisar y documentar APIs críticas del sistema",
        "Implementar sistema de notificaciones multicanal",
        "Mejorar gestión de archivos multimedia en aplicación web",
        "Actualizar configuración de seguridad en microservicios",
        "Optimizar algoritmos de machine learning para recomendaciones",
        "Implementar sistema de backup automático distribuido",
        "Mejorar sistema de monitoreo de salud de aplicaciones",
        "Revisar arquitectura de microservicios para escalabilidad",
        "Implementar nueva funcionalidad de integración con terceros",
        "Optimizar consumo de memoria en aplicaciones Java",
        "Mejorar interfaz de administración con nuevas capacidades",
        "Actualizar bibliotecas de JavaScript para seguridad",
        "Implementar sistema de análisis de comportamiento de usuarios",
        "Mejorar proceso de despliegue blue-green en producción",
        "Revisar configuración de base de datos para alta disponibilidad",
        "Implementar sistema de auditoría de cambios en tiempo real",
        "Optimizar consultas SQL complejas para mejor rendimiento",
        "Mejorar funcionalidad de filtros avanzados en interfaces",
        "Actualizar sistema de gestión de versiones de código fuente",
        "Implementar pruebas de integración automatizadas",
        "Mejorar sistema de gestión de configuraciones dinámicas",
        "Revisar arquitectura de seguridad en sistema distribuido",
        "Implementar sistema de almacenamiento de caché distribuido",
        "Optimizar procesamiento de imágenes en tiempo real",
        "Mejorar funcionalidad de búsqueda semántica",
        "Actualizar políticas de backup y recuperación ante desastres",
        "Implementar sistema de métricas avanzadas de negocio",
        "Mejorar proceso de onboarding y training de usuarios nuevos",
        "Revisar y documentar arquitectura de sistema completa",
        "Implementar nuevas funcionalidades de exportación avanzada",
        "Optimizar usO DE RECURSOS DE SERVIDORES EN CLOUD",
        "Mejorar funcionalidad de análisis predictivo con IA",
        "Actualizar sistema de gestión de dependencias externas",
        "Implementar sistema de backup automático incremental",
        "Mejorar experiencia de usuario en dispositivos móviles",
        "Revisar configuración de load balancers para alta disponibilidad",
        "Implementar sistema de alertas inteligentes y automatizadas",
        "Optimizar procesamiento de streams de datos en tiempo real",
        "Mejorar funcionalidad de reportes personalizables",
        "Actualizar bibliotecas de desarrollo frontend",
        "Implementar sistema de monitoreo de rendimiento avanzado",
        "Mejorar funcionalidad de integración con sistemas legacy",
        "Revisar arquitectura de microfrontends para escalabilidad",
        "Implementar nuevas funcionalidades de colaboración tipo",
        "Optimizar algoritmo de recomendaciones para mejor precisión",
        "Mejorar sistema de gestión de cache multicapa jerárquica",
        "Actualizar políticas de seguridad y compliance normativo",
        "Implementar sistema avanzado de backup diferencial automatizado"
    ];

    public function load(ObjectManager $manager): void
    {
        // Get existing retrospective (assuming there's at least one)
        $retrospective = $manager->getRepository(Retrospective::class)->findOneBy([]);
        
        if (!$retrospective) {
            echo "No retrospective found! Please ensure at least one retrospective exists.\n";
            return;
        }

        // Get team members for assignment
        $teamMembers = [];
        $owner = $retrospective->getTeam()->getOwner();
        
        // Add team owner as potential assignee
        $teamMembers[] = $owner;
        
        // Add team members as potential assignees  
        foreach ($retrospective->getTeam()->getTeamMembers() as $member) {
            if ($member->getUser() !== $owner) {
                $teamMembers[] = $member->getUser();
            }
        }

        // If no team members, create a test user for assignee
        if (empty($teamMembers)) {
            $testUser = new User();
            $testUser->setEmail('test@example.com');
            $testUser->setFirstName('Test');
            $testUser->setLastName('User');
            $testUser->setPassword('$2y$13$ENCODED_PASSWORD'); // Default password
            $manager->persist($testUser);
            $teamMembers[] = $testUser;
        }

        $statuses = ['pending', 'in-progress', 'completed', 'cancelled'];
        $creator = $owner; // Use team owner as creator
        
        for ($i = 0; $i < 100; $i++) {
            $action = new RetrospectiveAction();
            
            // Random action description (pick random from list and add variation)
            $baseDescription = $this->testActions[array_rand($this->testActions)];
            $variation = $i + 1;
            $action->setDescription("{$baseDescription} (#{$variation})");
            
            // Random status
            $status = $statuses[array_rand($statuses)];
            $action->setStatus($status);
            
            // Random assignee (with chance of being unassigned)
            if (rand(1, 10) <= 8) { // 80% chance of assignment
                $assignedUser = $teamMembers[array_rand($teamMembers)];
                $action->setAssignedTo($assignedUser);
            }
            
            // Random due date (only for pending/in-progress)
            if (in_array($status, ['pending', 'in-progress']) && rand(1, 10) <= 6) { // 60% chance
                $daysOffset = rand(-30, 90); // From 30 days ago to 90 days future
                $dueDate = new \DateTime();
                $dueDate->modify("+{$daysOffset} days");
                $action->setDueDate($dueDate);
            }
            
            // Set completion date if status is completed
            if ($status === 'completed') {
                $action->setCompletedAt(new \DateTime());
            }
            
            $action->setRetrospective($retrospective);
            $action->setCreatedBy($creator);
            $action->setCreatedAt(new \DateTime());
            $action->setUpdatedAt(new \DateTime());
            
            $manager->persist($action);
            
            // Show progress
            if (($i + 1) % 20 === 0) {
                echo "Created " . ($i + 1) . " actions...\n";
            }
        }
        
        $manager->flush();
        echo "Successfully created 100 test actions!\n";
    }
}
