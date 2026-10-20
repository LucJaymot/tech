<?php

namespace App\Form;

use App\Booking\Hospitalite;
use App\Booking\Programmation;
use App\Booking\Rider;
use App\Entity\Artiste;
use App\Entity\Booking;
use App\Entity\Soiree;
use App\Enum\ModeRemuneration;
use App\Repository\ArtisteRepository;
use App\Validator\PassagesSansChevauchement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * An artist on a soirée's line-up: the artist (from the collective's address
 * book, or new), the deal, the passage in the timetable, the technical rider
 * and the hospitality. Creation and edition alike; the artist itself cannot
 * change once booked.
 *
 * @extends AbstractType<Booking>
 */
final class ProgrammationType extends AbstractType
{
    public function __construct(
        private readonly Programmation $programmation,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Soiree $soiree */
        $soiree = $options['soiree'];
        $edition = $options['edition'];

        $builder
            ->add('artiste', EntityType::class, [
                'label' => 'Artiste',
                'class' => Artiste::class,
                'choice_label' => 'nom',
                'query_builder' => static fn (ArtisteRepository $artistes): QueryBuilder => $artistes->createQueryBuilder('a')
                    ->where('a.collective = :collective')
                    ->setParameter('collective', $soiree->getCollective())
                    ->orderBy('a.nom'),
                'required' => false,
                'disabled' => $edition,
                'placeholder' => 'Nouvel artiste',
                // Given to the Booking constructor in empty_data, never changed after.
                'setter' => static function (): void {},
                'help' => $edition ? null : 'Les artistes déjà venus sont dans le carnet du collectif.',
            ])
            ->add('nouvelArtiste', FormType::class, ['label' => false, 'mapped' => false, 'required' => false])
            ->add('cachet', MoneyType::class, [
                'label' => 'Cachet',
                'currency' => 'EUR',
                'divisor' => 100,
                'input' => 'integer',
                'help' => 'Brut, en euros. 0 pour un artiste non rémunéré.',
                'invalid_message' => 'Saisissez un montant, par exemple 450 ou 450,50.',
                'attr' => ['inputmode' => 'decimal'],
                'constraints' => [new Assert\NotBlank()],
                // Left empty, the booking keeps its fee: NotBlank above reports it.
                'setter' => static function (Booking $booking, ?int $cachet): void {
                    if (null !== $cachet) {
                        $booking->setCachet($cachet);
                    }
                },
            ])
            ->add('modeRemuneration', EnumType::class, [
                'label' => 'Rémunération',
                'class' => ModeRemuneration::class,
                'choice_label' => static fn (ModeRemuneration $mode): string => $mode->label(),
                'help' => 'Pas d\'espèces ni de facture d\'auto-entrepreneur.',
            ])
            ->add('debut', TimeType::class, ['label' => 'Début du passage', 'mapped' => false, 'required' => false, 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'H:i'])
            ->add('fin', TimeType::class, ['label' => 'Fin du passage', 'mapped' => false, 'required' => false, 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'H:i', 'help' => 'Laissez vide si l\'horaire n\'est pas encore calé. Mêmes horaires qu\'un autre artiste : B2B.'])
            ->add('rider', FormType::class, ['label' => false, 'mapped' => false])
            ->add('hospitalite', FormType::class, ['label' => false, 'mapped' => false]);

        $builder->get('nouvelArtiste')
            ->add('nom', TextType::class, ['label' => 'Nom de scène', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'attr' => ['rows' => 2]]);

        $minutes = ['required' => false, 'empty_data' => '0', 'constraints' => [new Assert\PositiveOrZero()], 'attr' => ['min' => 0, 'inputmode' => 'numeric']];
        $builder->get('rider')
            ->add('installationMinutes', IntegerType::class, ['label' => 'Installation (minutes)'] + $minutes)
            ->add('balanceMinutes', IntegerType::class, ['label' => 'Balance (minutes)'] + $minutes)
            ->add('materielDemande', TextareaType::class, ['label' => 'Fourni sur place', 'required' => false, 'help' => 'Un élément par ligne.', 'attr' => ['rows' => 3]])
            ->add('materielApporte', TextareaType::class, ['label' => 'Apporté par l\'artiste', 'required' => false, 'help' => 'Un élément par ligne.', 'attr' => ['rows' => 3]])
            ->add('notes', TextareaType::class, ['label' => 'Notes techniques', 'required' => false, 'attr' => ['rows' => 2]]);

        $builder->get('hospitalite')
            ->add('repas', IntegerType::class, ['label' => 'Repas'] + $minutes)
            ->add('regimes', TextType::class, ['label' => 'Régimes alimentaires', 'required' => false, 'help' => 'Séparés par des virgules : végétarien, sans gluten…'])
            ->add('invitations', IntegerType::class, ['label' => 'Invitations (guest-list)'] + $minutes)
            ->add('hebergement', CheckboxType::class, ['label' => 'Hébergement à prévoir', 'required' => false])
            ->add('notes', TextareaType::class, ['label' => 'Notes d\'accueil', 'required' => false, 'attr' => ['rows' => 2]]);

        // The unmapped parts are shown from the booking being edited.
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $booking = $event->getData();
            if (!$booking instanceof Booking) {
                return;
            }
            $form = $event->getForm();
            $form->get('debut')->setData($booking->getPassage()?->getDebut()->format('H:i'));
            $form->get('fin')->setData($booking->getPassage()?->getFin()->format('H:i'));
            $rider = $booking->getRider();
            $form->get('rider')->setData([
                'installationMinutes' => $rider->installationMinutes,
                'balanceMinutes' => $rider->balanceMinutes,
                'materielDemande' => implode("\n", $rider->materielDemande),
                'materielApporte' => implode("\n", $rider->materielApporte),
                'notes' => $rider->notes,
            ]);
            $hospitalite = $booking->getHospitalite();
            $form->get('hospitalite')->setData([
                'repas' => $hospitalite->repas,
                'regimes' => implode(', ', $hospitalite->regimes),
                'invitations' => $hospitalite->invitations,
                'hebergement' => $hospitalite->hebergement,
                'notes' => $hospitalite->notes,
            ]);
        });

        // Before validation: the booking then gets checked whole.
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $booking = $event->getData();
            if (!$booking instanceof Booking) {
                return;
            }
            $form = $event->getForm();

            $rider = (array) $form->get('rider')->getData();
            $booking->setRider(new Rider(
                (int) ($rider['installationMinutes'] ?? 0),
                (int) ($rider['balanceMinutes'] ?? 0),
                self::lignes($rider['materielDemande'] ?? null),
                self::lignes($rider['materielApporte'] ?? null),
                self::texte($rider['notes'] ?? null),
            ));
            $hospitalite = (array) $form->get('hospitalite')->getData();
            $booking->setHospitalite(new Hospitalite(
                (int) ($hospitalite['repas'] ?? 0),
                array_values(array_filter(array_map('trim', explode(',', (string) ($hospitalite['regimes'] ?? ''))), static fn (string $r): bool => '' !== $r)),
                (int) ($hospitalite['invitations'] ?? 0),
                (bool) ($hospitalite['hebergement'] ?? false),
                self::texte($hospitalite['notes'] ?? null),
            ));

            try {
                $this->programmation->placer($booking, $form->get('debut')->getData(), $form->get('fin')->getData());
            } catch (\InvalidArgumentException $e) {
                $form->get('debut')->addError(new FormError($e->getMessage()));
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($soiree): void {
            foreach ($this->validator->validate($soiree, new PassagesSansChevauchement()) as $violation) {
                $event->getForm()->get('debut')->addError(new FormError((string) $violation->getMessage()));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => Booking::class,
                'edition' => false,
                // A new booking, its artist picked or created in "nouvelArtiste".
                'empty_data' => static function (FormInterface $form): Booking {
                    /** @var Soiree $soiree */
                    $soiree = $form->getConfig()->getOption('soiree');
                    $artiste = $form->get('artiste')->getData();
                    if (!$artiste instanceof Artiste) {
                        $nouveau = (array) $form->get('nouvelArtiste')->getData();
                        $artiste = (new Artiste($soiree->getCollective(), trim((string) ($nouveau['nom'] ?? ''))))
                            ->setEmail(self::texte($nouveau['email'] ?? null))
                            ->setTelephone(self::texte($nouveau['telephone'] ?? null))
                            ->setNotes(self::texte($nouveau['notes'] ?? null));
                    }

                    return new Booking($soiree, $artiste, (int) $form->get('cachet')->getData(), $form->get('modeRemuneration')->getData() ?? ModeRemuneration::Cession);
                },
                // A new artist is validated as an Artiste: its errors go to its own fields.
                'error_mapping' => [
                    'artiste.nom' => 'nouvelArtiste.nom',
                    'artiste.email' => 'nouvelArtiste.email',
                    'artiste.telephone' => 'nouvelArtiste.telephone',
                ],
            ])
            ->setRequired('soiree')
            ->setAllowedTypes('soiree', Soiree::class)
            ->setAllowedTypes('edition', 'bool');
    }

    /**
     * @return list<string> one item per non-empty line
     */
    private static function lignes(mixed $texte): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $texte) ?: []), static fn (string $l): bool => '' !== $l));
    }

    private static function texte(mixed $texte): ?string
    {
        $texte = trim((string) $texte);

        return '' === $texte ? null : $texte;
    }
}
