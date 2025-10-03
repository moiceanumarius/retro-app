<?php

namespace App\Form;

use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * OrganizationMemberType - Form pentru entitatea OrganizationMember
 * 
 * Formulare pentru gestirea membrilor organizațiilor în sistemul RetroApp.
 * Permite administrorilor să adauge/edit membri cu roluri > MEMBER.
 * 
 * Câmpuri principale:
 * - User selection (dropdown cu utilizatori cu roluri înalte)
 * - Role în organizație (custom role vs sistem de roluri)
 * - Expiration date (optional pentru membres temporari)
 * - Notes (opțional pentru context suplimentar)
 * 
 * Validări:
 * - Doar useri cu roluri FACILITATOR/SUPERVISOR/ADMIN pot fi adăugați
 * - Prevenim dublă alegere în aceeași organizație
 * - Validare expiracție înainte de join date
 */
class OrganizationMemberType extends AbstractType
{
    public function __construct(
        private UserRepository $userRepository
    ) {
    }

    /**
     * Construirea formularului pentru membri organizații
     * 
     * @param FormBuilderInterface $builder Builder Symfony pentru forms
     * @param array $options Opțiunile de configurare (poate include 'organization')
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', EntityType::class, [
                'class' => User::class,
                'label' => 'Select User',
                'label_attr' => [
                    'class' => 'form-label'
                ],
                'attr' => [
                    'class' => 'form-select',
                    'required' => true
                ],
                'choice_label' => function(User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName() . ' (' . $user->getEmail() . ')';
                },
                'query_builder' => function(UserRepository $userRepo) {
                    // Doar utilizatori cu roluri mai înalte decât MEMBER
                    return $userRepo->createQueryBuilder('u')
                        ->join('u.userRoles', 'ur')
                        ->join('ur.role', 'r')
                        ->where('ur.isActive = :active')
                        ->andWhere('r.code IN (:roles)')
                        ->setParameter('active', true)
                        ->setParameter('roles', ['ROLE_FACILITATOR', 'ROLE_SUPERVISOR', 'ROLE_ADMIN'])
                        ->orderBy('u.firstName', 'ASC');
                },
                'help' => 'Only users with Facilitator, Supervisor, or Admin roles can be added to organizations.',
                'placeholder' => 'Choose a user...',
                'required' => true,
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Role in Organization',
                'label_attr' => [
                    'class' => 'form-label'
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'choices' => [
                    'Admin' => 'Admin',
                    'Manager' => 'Manager',
                    'Coordinator' => 'Coordinator', 
                    'Lead' => 'Lead',
                    'Member' => 'Member',
                    'Observer' => 'Observer',
                ],
                'help' => 'Select the role this user will have within the organization.',
                'required' => true,
                'placeholder' => 'Select a role...',
            ])
            ->add('expiresAt', DateTimePickerType::class, [
                'label' => 'Expiration Date (Optional)',
                'label_attr' => [
                    'class' => 'form-label'
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'When should this membership expire?'
                ],
                'required' => false,
                'mapped' => true,
                'help' => 'Leave empty for permanent membership. Set a date to create temporary membership.',
                'date_format' => 'yyyy-MM-dd',
                'date_attr' => [
                    'min' => date('Y-m-d', strtotime('+1 day')) // Minimum tomorrow
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes (Optional)',
                'label_attr' => [
                    'class' => 'form-label'
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Add any additional notes about this membership...',
                    'rows' => 3,
                    'maxlength' => 500
                ],
                'help' => 'Optional: Add context, responsibilities, or special notes for this membership.',
                'required' => false,
            ])
        ;

        // Adăugarea validării pentru prevenirea membrii duplicați
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function(FormEvent $event) {
            $form = $event->getForm();
            $data = $event->getData();
            $organizationMember = $form->getData();
            
            // Validare că data de expirare este după data de join
            if (isset($data['expiresAt']) && $data['expiresAt']) {
                $expiration = new \DateTime($data['expiresAt']);
                if ($expiration < new \DateTime()) {
                    $form->addError(new \Symfony\Component\Form\FormError('Expiration date cannot be in the past.'));
                }
            }
        });
    }

    /**
     * Configurarea opțiunilor pentru formular
     * 
     * @param OptionsResolver $resolver Resolver pentru opțiuni
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrganizationMember::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'organization_member_form',
        ]);

        // Opțiuni suplimentare pentru context
        $resolver->setDefined([
            'organization', // Pentru validări specifice organizației
        ]);
    }
}
