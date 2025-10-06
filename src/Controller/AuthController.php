<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class AuthController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        // if ($this->getUser()) {
        //     return $this->redirectToRoute('target_path');
        // }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();
        
        // Get email from URL parameter for pre-filling
        $prefillEmail = $request->query->get('email');
        if ($prefillEmail && !$lastUsername) {
            $lastUsername = $prefillEmail;
        }
        
        // Get invitation token from URL parameter
        $invitationToken = $request->query->get('invitation');
        
        // Get the target path from session (where user was redirected from)
        $targetPath = $this->getTargetPath($request->getSession(), 'main');
        
        // If there's an invitation token, set target path to invitation accept route
        if ($invitationToken) {
            $targetPath = $this->generateUrl('app_team_invitation_accept', ['token' => $invitationToken]);
        }
        
        // Ensure target_path is always set (fallback to dashboard)
        if (!$targetPath) {
            $targetPath = $this->generateUrl('app_dashboard');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'invitation_token' => $invitationToken,
            'target_path' => $targetPath,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}