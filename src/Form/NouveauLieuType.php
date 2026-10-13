<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A venue created from the soirée form, only when no existing venue is
 * picked. Turned into a Lieu by SoireeType, then validated as one.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class NouveauLieuType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom du nouveau lieu', 'required' => false])
            ->add('adresse', TextareaType::class, ['label' => 'Adresse', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('capacite', IntegerType::class, ['label' => 'Capacité', 'required' => false, 'attr' => ['min' => 1, 'inputmode' => 'numeric'], 'invalid_message' => 'La capacité doit être un nombre entier.'])
            ->add('amenagePourPublic', CheckboxType::class, ['label' => 'Lieu aménagé pour accueillir du public', 'required' => false]);
    }
}
