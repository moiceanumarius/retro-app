<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class UserProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'First Name',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Enter your first name'
                ]
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last Name',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Enter your last name'
                ]
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Enter your email address'
                ]
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'Bio',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Tell us about yourself...'
                ]
            ])
            ->add('timezone', ChoiceType::class, [
                'label' => 'Timezone',
                'required' => false,
                'choices' => [
                    'UTC' => 'UTC',
                    'Europe/Bucharest' => 'Europe/Bucharest',
                    'Europe/London' => 'Europe/London',
                    'America/New_York' => 'America/New_York',
                    'America/Los_Angeles' => 'America/Los_Angeles',
                    'Asia/Tokyo' => 'Asia/Tokyo',
                ],
                'attr' => [
                    'class' => 'form-control'
                ]
            ])
            ->add('language', ChoiceType::class, [
                'label' => 'Language',
                'required' => false,
                'choices' => [
                    'English' => 'en',
                    'Romanian' => 'ro',
                    'French' => 'fr',
                    'German' => 'de',
                    'Spanish' => 'es',
                ],
                'attr' => [
                    'class' => 'form-control'
                ]
            ])
            ->add('avatarFile', FileType::class, [
                'label' => 'Avatar',
                'required' => false,
                'mapped' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '2M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                        ],
                        'mimeTypesMessage' => 'Please upload a valid image file (JPEG, PNG, GIF, or WebP)',
                    ])
                ],
                'attr' => [
                    'class' => 'form-control',
                    'accept' => 'image/*'
                ]
            ])
            ->add('emailNotifications', CheckboxType::class, [
                'label' => 'Email Notifications',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ]
            ])
            ->add('pushNotifications', CheckboxType::class, [
                'label' => 'Push Notifications',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ]
            ])
            ->add('weeklyDigest', CheckboxType::class, [
                'label' => 'Weekly Digest',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
