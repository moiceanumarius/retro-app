<?php

namespace App\Form;

use App\Entity\RetrospectiveAction;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RetrospectiveActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextareaType::class, [
                'label' => 'Action Description',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Describe the action item...'
                ]
            ])
            ->add('assignedTo', EntityType::class, [
                'class' => User::class,
                'choice_label' => function(User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName() . ' (' . $user->getEmail() . ')';
                },
                'label' => 'Assigned To',
                'attr' => [
                    'class' => 'form-control'
                ]
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Pending' => 'pending',
                    'In Progress' => 'in_progress',
                    'Completed' => 'completed',
                    'Cancelled' => 'cancelled',
                ],
                'attr' => [
                    'class' => 'form-control'
                ]
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'Due Date',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'class' => 'form-control'
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RetrospectiveAction::class,
        ]);
    }
}