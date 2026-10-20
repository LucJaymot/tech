<?php

namespace App\Form;

use App\Entity\Collective;
use App\Entity\Lieu;
use App\Entity\Soiree;
use App\Repository\LieuRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creation and edition of a soirée. Its collective is the "collective"
 * option, never a field. A venue is picked among the collective's, or
 * created in the same form ("nouveauLieu").
 *
 * @extends AbstractType<Soiree>
 */
final class SoireeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Collective $collective */
        $collective = $options['collective'];

        $builder
            ->add('nom', TextType::class, ['label' => 'Nom', 'empty_data' => ''])
            ->add('ouverture', DateTimeType::class, [
                'label' => 'Ouverture',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Le jour de l\'ouverture est la date de la soirée.',
                'constraints' => [new Assert\NotBlank(), ...($options['creation'] ? [new Assert\GreaterThan('now', message: 'La soirée doit avoir lieu dans le futur.')] : [])],
                // Left empty, the soirée keeps its date: NotBlank above reports it.
                'setter' => static function (Soiree $soiree, ?\DateTimeImmutable $ouverture): void {
                    if (null !== $ouverture) {
                        $soiree->setOuverture($ouverture);
                    }
                },
            ])
            ->add('fermeture', DateTimeType::class, [
                'label' => 'Fermeture',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Elle peut être le lendemain, 24 heures au plus après l\'ouverture.',
                'constraints' => [new Assert\NotBlank()],
                'setter' => static function (Soiree $soiree, ?\DateTimeImmutable $fermeture): void {
                    if (null !== $fermeture) {
                        $soiree->setFermeture($fermeture);
                    }
                },
            ])
            ->add('lieu', EntityType::class, [
                'label' => 'Lieu',
                'class' => Lieu::class,
                'choice_label' => static fn (Lieu $lieu): string => \sprintf('%s (%d personnes)', $lieu->getNom(), $lieu->getCapacite()),
                'query_builder' => static fn (LieuRepository $lieux): QueryBuilder => $lieux->createQueryBuilder('l')
                    ->where('l.collective = :collective')
                    ->setParameter('collective', $collective)
                    ->orderBy('l.nom'),
                'required' => false,
                'placeholder' => 'Nouveau lieu, ou aucun pour l\'instant',
            ])
            ->add('nouveauLieu', NouveauLieuType::class, ['label' => false, 'mapped' => false, 'required' => false])
            ->add('jauge', IntegerType::class, [
                'label' => 'Jauge',
                'required' => false,
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
                'invalid_message' => 'La jauge doit être un nombre entier.',
            ])
            ->add('buvette', CheckboxType::class, ['label' => 'Buvette', 'required' => false])
            ->add('artistesPayes', CheckboxType::class, ['label' => 'Artistes payés', 'required' => false])
            // Before validation: the new venue is then checked with the soirée.
            ->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event) use ($collective): void {
                $soiree = $event->getData();
                $nouveau = $event->getForm()->get('nouveauLieu')->getData();
                if ($soiree instanceof Soiree && null === $soiree->getLieu() && \is_array($nouveau) && '' !== trim((string) ($nouveau['nom'] ?? ''))) {
                    $soiree->setLieu((new Lieu($collective, trim((string) $nouveau['nom']), trim((string) ($nouveau['adresse'] ?? '')), (int) ($nouveau['capacite'] ?? 0)))
                        ->setAmenagePourPublic((bool) ($nouveau['amenagePourPublic'] ?? false)));
                }
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => Soiree::class,
                'creation' => false,
                // Placeholders until the fields are mapped onto it.
                'empty_data' => static function (FormInterface $form): Soiree {
                    $ouverture = $form->get('ouverture')->getData() ?? new \DateTimeImmutable('tomorrow 20:00');

                    return new Soiree($form->getConfig()->getOption('collective'), (string) $form->get('nom')->getData(), $ouverture, $ouverture, $ouverture->modify('+1 hour'));
                },
                // A new venue is validated as a Lieu: show its errors on its own fields.
                'error_mapping' => [
                    'lieu.nom' => 'nouveauLieu.nom',
                    'lieu.adresse' => 'nouveauLieu.adresse',
                    'lieu.capacite' => 'nouveauLieu.capacite',
                ],
            ])
            ->setRequired('collective')
            ->setAllowedTypes('collective', Collective::class)
            ->setAllowedTypes('creation', 'bool');
    }
}
