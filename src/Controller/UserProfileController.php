<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserProfileType;
use App\Form\ChangePasswordType;
use App\Service\UserProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserProfileController extends AbstractController
{
    public function __construct(
        private UserProfileService $userProfileService
    ) {
    }

    #[Route('/profile', name: 'app_user_profile')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        return $this->render('user_profile/index.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/profile/edit', name: 'app_user_profile_edit')]
    public function edit(Request $request): Response
    {
        $user = $this->getUser();
        $form = $this->createForm(UserProfileType::class, $user);
        
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Use UserProfileService to update profile
                $avatarFile = $form->get('avatarFile')->getData();
                $this->userProfileService->updateProfile($user, $avatarFile);
                
                $this->addFlash('success', '✅ Profile updated successfully!');
                
                return $this->redirectToRoute('app_user_profile');
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }
        
        return $this->render('user_profile/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/profile/change-password', name: 'app_user_change_password')]
    public function changePassword(Request $request): Response
    {
        $user = $this->getUser();
        $form = $this->createForm(ChangePasswordType::class);
        
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();
            $newPassword = $form->get('newPassword')->getData();
            
            // Use UserProfileService to change password
            $success = $this->userProfileService->changePassword($user, $currentPassword, $newPassword);
            
            if (!$success) {
                $this->addFlash('error', 'Current password is incorrect.');
                return $this->render('user_profile/change_password.html.twig', [
                    'form' => $form,
                ]);
            }
            
            $this->addFlash('success', '✅ Password changed successfully!');
            
            return $this->redirectToRoute('app_user_profile');
        }
        
        return $this->render('user_profile/change_password.html.twig', [
            'form' => $form,
        ]);
    }
}
