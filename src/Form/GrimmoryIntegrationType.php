<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Integration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class GrimmoryIntegrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $hasExistingCredentials = (bool) ($options['has_existing_credentials'] ?? false);

        $builder
            ->add('baseUrl', UrlType::class, [
                'label' => 'Komga server URL',
                'required' => true,
                'attr' => ['placeholder' => 'https://grimmory.local/komga'],
                'help' => 'Root of your Grimmory (Komga) server (scheme + host + port + optional path). Spine Scout appends /api/v1/... itself.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(
                        pattern: '~^https?://[^\s/$.?#].[^\s]*$~i',
                        message: 'Enter a URL like https://host or https://host/komga.',
                    ),
                ],
            ])
            ->add('username', TextType::class, [
                'label' => 'Komga username',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => 'spinescout',
                    'autocomplete' => 'off',
                ],
                'help' => 'A Komga account Spine Scout uses to read your library. A read-only role is sufficient.',
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Komga password',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => $hasExistingCredentials ? '•••••••• (leave blank to keep current)' : '',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('syncIntervalMinutes', IntegerType::class, [
                'label' => 'Sync interval (minutes)',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 1440],
                'help' => 'How often Spine Scout polls Grimmory. Default 15.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(min: 1, max: 1440),
                ],
            ])
        ;

        $libraryChoices = $this->buildLibraryChoices($options['discovered_libraries']);
        if ($libraryChoices !== []) {
            $builder->add('selectedLibraries', ChoiceType::class, [
                'label' => 'Libraries to sync',
                'choices' => $libraryChoices,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Which Komga libraries Spine Scout should pull books from. Leave all unchecked to sync everything.',
            ]);
        }
    }

    /**
     * @param array<int, array{id: string, name: string}> $discovered
     * @return array<string, string> label => value
     */
    private function buildLibraryChoices(array $discovered): array
    {
        $out = [];
        foreach ($discovered as $row) {
            if (!isset($row['id'], $row['name'])) {
                continue;
            }
            $out[$row['name']] = $row['id'];
        }
        return $out;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Integration::class,
            'has_existing_credentials' => false,
            'discovered_libraries' => [],
        ]);
        $resolver->setAllowedTypes('has_existing_credentials', 'bool');
        $resolver->setAllowedTypes('discovered_libraries', 'array');
    }
}
