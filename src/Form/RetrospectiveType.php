<?php

namespace App\Form;

use App\Entity\Retrospective;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\OrganizationMember;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Bundle\SecurityBundle\Security;

class RetrospectiveType extends AbstractType
{
    public function __construct(private Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Enter retrospective title'
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Enter retrospective description (optional)'
                ]
            ])
            ->add('team', EntityType::class, [
                'class' => Team::class,
                'choice_label' => 'name',
                'label' => 'Team',
                'query_builder' => function(EntityRepository $er) {
                    $user = $this->security->getUser();
                    if (!$user) {
                        return $er->createQueryBuilder('t')->where('1 = 0'); // No teams if no user
                    }

                    // Get user's organization memberships
                    $userOrganizations = $this->security->getUser()->getActiveOrganizationMemberships();
                    
                    if (empty($userOrganizations)) {
                        // If user has no organization, show teams where user is owner or member
                        return $er->createQueryBuilder('t')
                            ->leftJoin('t.teamMembers', 'tm')
                            ->where('t.owner = :user OR tm.user = :user')
                            ->andWhere('t.isActive = :active')
                            ->andWhere('tm.isActive = :active OR tm.isActive IS NULL')
                            ->setParameter('user', $user)
                            ->setParameter('active', true)
                            ->orderBy('t.name', 'ASC');
                    }

                    // Get organization IDs
                    $organizationIds = [];
                    foreach ($userOrganizations as $membership) {
                        if ($membership->getOrganization()) {
                            $organizationIds[] = $membership->getOrganization()->getId();
                        }
                    }

                    if (empty($organizationIds)) {
                        return $er->createQueryBuilder('t')->where('1 = 0'); // No teams if no organizations
                    }

                    // Show teams from user's organizations where user is owner or member
                    return $er->createQueryBuilder('t')
                        ->leftJoin('t.teamMembers', 'tm')
                        ->where('t.organization IN (:organizationIds)')
                        ->andWhere('(t.owner = :user OR tm.user = :user)')
                        ->andWhere('t.isActive = :active')
                        ->andWhere('tm.isActive = :active OR tm.isActive IS NULL')
                        ->setParameter('organizationIds', $organizationIds)
                        ->setParameter('user', $user)
                        ->setParameter('active', true)
                        ->orderBy('t.name', 'ASC');
                },
                'attr' => [
                    'class' => 'form-control'
                ]
            ])
            ->add('scheduledAt', DateType::class, [
                'label' => 'Scheduled Date',
                'widget' => 'single_text',
                'html5' => true,
                'attr' => [
                    'class' => 'form-control',
                    'type' => 'date'
                ]
            ])
            ->add('voteNumbers', IntegerType::class, [
                'label' => 'Allowed Vote Numbers',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'max' => 20,
                    'placeholder' => 'Enter number of votes allowed per user'
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Retrospective::class,
        ]);
    }
}
