<?php

namespace App\Form;

use App\Entity\Organization;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * OrganizationType - Form pentru entitatea Organization
 * 
 * Formulr pentru crearea și editarea organizațiilor în sistemul RetroApp.
 * Conține câmpurile esențiale pentru definirea unei organizații:
 * - Nume (required)
 * - Descriere (optional)
 * 
 * Validarea se face prin:
 * - Symfony built-in validators
 * - Constraints în entitatea Organization
 * - CSRF protection prin configurația Symfony
 * 
 * Utilizare:
 * - Creare organizație: Organization create endpoint
 * - Editare organizație: Organization edit endpoint
 * - Renderizare: orice template Twig care gestionează formulars
 */
class OrganizationType extends AbstractType
{
    /**
     * Construirea formularului pentru organizație
     * 
     * @param FormBuilderInterface $builder Builder Symfony pentru forms
     * @param array $options Opțiunile de configurare
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Organization Name',
                'label_attr' => [
                    'class' => 'form-label'
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Enter organization name (e.g., ACME Corp, Development Team)',
                    'maxlength' => 255
                ],
                'help' => 'Choose a clear, descriptive name for the organization.',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'label_attr' => [
                    'class' => 'form-label'
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Optional: Describe the purpose and goals of this organization',
                    'rows' => 4,
                    'maxlength' => 1000
                ],
                'help' => 'Optional: Provide context about what this organization does or represents.',
                'required' => false,
            ])
        ;
    }

    /**
     * Configurarea opțiunilor pentru formular
     * 
     * @param OptionsResolver $resolver Resolver pentru opțiuni
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Organization::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'organization_form',
        ]);
    }
}
