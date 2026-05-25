<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Integration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class HardcoverIntegrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $hasExistingToken = (bool) ($options['has_existing_credentials'] ?? false);
        $prefs = (array) ($options['edition_preferences'] ?? ['languages' => [], 'formats' => [], 'countries' => []]);

        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Enable Hardcover',
                'required' => false,
                'help' => 'Pulls Trending Books from Hardcover when enabled and a token is set.',
            ])
            ->add('apiToken', PasswordType::class, [
                'label' => 'Hardcover API token',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => $hasExistingToken ? '•••••••• (leave blank to keep current)' : 'Paste from hardcover.app account settings',
                    'autocomplete' => 'off',
                ],
                'help' => 'Get one at hardcover.app → Account → Hardcover API. Tokens expire annually on Jan 1.',
            ])
            ->add('syncIntervalMinutes', IntegerType::class, [
                'label' => 'Refresh interval (minutes)',
                'required' => true,
                'attr' => ['min' => 5, 'max' => 1440],
                'help' => 'How often Spine Scout re-fetches Trending Books. Default 60.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(min: 5, max: 1440),
                ],
            ])
            ->add('preferredLanguages', TextType::class, [
                'label' => 'Preferred edition languages',
                'mapped' => false,
                'required' => false,
                'data' => implode(', ', $prefs['languages'] ?? []),
                'attr' => ['placeholder' => 'e.g. eng, spa'],
                'help' => 'Comma-separated ISO 639-3 codes (eng, spa, fre, ger, ita…). Earlier codes win. Empty = no preference.',
            ])
            ->add('preferredFormats', TextType::class, [
                'label' => 'Preferred edition formats',
                'mapped' => false,
                'required' => false,
                'data' => implode(', ', $prefs['formats'] ?? []),
                'attr' => ['placeholder' => 'e.g. Hardcover, Paperback'],
                'help' => "Comma-separated Hardcover physical_format values (Hardcover, Paperback, Audiobook, Ebook…). Earlier entries win.",
            ])
            ->add('preferredCountries', TextType::class, [
                'label' => 'Preferred edition countries',
                'mapped' => false,
                'required' => false,
                'data' => implode(', ', $prefs['countries'] ?? []),
                'attr' => ['placeholder' => 'e.g. US, GB'],
                'help' => 'Comma-separated ISO 3166-1 alpha-2 country codes (US, GB, CA, AU…). Earlier codes win.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Integration::class,
            'has_existing_credentials' => false,
            'edition_preferences' => ['languages' => [], 'formats' => [], 'countries' => []],
        ]);
        $resolver->setAllowedTypes('has_existing_credentials', 'bool');
        $resolver->setAllowedTypes('edition_preferences', 'array');
    }
}
