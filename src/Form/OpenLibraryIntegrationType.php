<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Integration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class OpenLibraryIntegrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Enable Open Library',
                'required' => false,
                'help' => 'Pulls Trending Books from openlibrary.org. No account or API key needed.',
            ])
            ->add('syncIntervalMinutes', IntegerType::class, [
                'label' => 'Refresh interval (minutes)',
                'required' => true,
                'attr' => ['min' => 15, 'max' => 1440],
                'help' => 'How often Spine Scout re-fetches trending works. Default 60.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(min: 15, max: 1440),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Integration::class,
        ]);
    }
}
