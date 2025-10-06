<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;

class RegistrationController extends AbstractController
{

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Security $security): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            // encode the plain password
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            
            // set timestamps
            $user->setCreatedAt(new \DateTimeImmutable());
            $user->setUpdatedAt(new \DateTimeImmutable());

            // Set user as verified automatically (no email verification required)
            $user->setIsVerified(true);
            
            $entityManager->persist($user);
            $entityManager->flush();

            // Auto-login the user after successful registration
            $security->login($user, 'form_login');

            // Check if there's an invitation token to redirect to
            $invitationToken = $request->query->get('invitation');
            if ($invitationToken) {
                return $this->redirectToRoute('app_team_invitation_accept', ['token' => $invitationToken]);
            }

            return $this->redirectToRoute('app_dashboard');
        }

        // Get invitation token from URL parameter
        $invitationToken = $request->query->get('invitation');

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'invitation_token' => $invitationToken,
        ]);
    }
}
