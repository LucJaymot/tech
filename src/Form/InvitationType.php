<?php

namespace App\Form;

use App\Enum\MembershipRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class InvitationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr' => ['autocomplete' => 'off'],
                'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)],
            ])
            ->add('role', EnumType::class, [
                'label' => 'Rôle',
                'class' => MembershipRole::class,
                'choice_label' => static fn (MembershipRole $role): string => $role->label(),
                'data' => MembershipRole::Member,
                'help' => 'Administrateur : gère les membres. Membre : participe. Lecteur : consulte.',
            ]);
    }
}
