<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\RegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;

class RegistrationController extends AbstractController
{
    public function __construct(
        private RegistrationService $registrationService
    ) {}

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, Security $security): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            // Use RegistrationService to register user
            $this->registrationService->registerUser($user, $plainPassword);

            // Auto-login the user after successful registration
            $security->login($user, 'App\Security\FormLoginAuthenticator');

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
