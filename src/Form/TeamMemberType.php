<?php

namespace App\Form;

use App\Entity\TeamMember;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TeamMemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', EntityType::class, [
                'class' => User::class,
                'choice_label' => function(User $user) {
                    return $user->getFullName() . ' (' . $user->getEmail() . ')';
                },
                'query_builder' => function(EntityRepository $er) use ($options) {
                    $team = $options['team'] ?? null;
                    $qb = $er->createQueryBuilder('u')
                        ->leftJoin('u.organizationMemberships', 'om')
                        ->leftJoin('om.organization', 'o')
                        ->orderBy('u.firstName', 'ASC');
                    
                    if ($team && $team->getOrganization()) {
                        // Show users from the same organization as the team AND users without any organization
                        $qb->where('o.id = :teamOrgId OR o.id IS NULL')
                           ->setParameter('teamOrgId', $team->getOrganization()->getId());
                    } else {
                        // If team has no organization, show only users without organization
                        $qb->where('o.id IS NULL');
                    }
                    
                    return $qb;
                },
                'label' => 'Select User',
                'required' => true,
                'attr' => [
                    'class' => 'form-control'
                ]
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Role',
                'required' => false,
                'choices' => [
                    'Member' => 'Member',
                    'Facilitator' => 'Facilitator',
                ],
                'attr' => [
                    'class' => 'form-control'
                ]
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Add any notes about this member (optional)'
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TeamMember::class,
        ]);
        
        $resolver->setDefined(['team']);
    }
}
