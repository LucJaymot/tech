<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Development-only helpers to preview UI states.
 */
final class DevController extends AbstractController
{
    #[Route('/_dev/flashes', name: 'dev_flashes', env: 'dev')]
    public function flashes(): Response
    {
        $this->addFlash('success', 'Enregistrement effectué.');
        $this->addFlash('info', 'Une information à connaître.');
        $this->addFlash('warning', 'Attention, vérifiez ce point.');
        $this->addFlash('error', 'Une erreur est survenue.');

        return $this->redirectToRoute('app_home');
    }

    #[Route('/_dev/composants', name: 'dev_components', env: 'dev')]
    public function components(Request $request): Response
    {
        $form = $this->createFormBuilder()
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'help' => 'Nous ne la partagerons pas.',
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
            ->add('topic', ChoiceType::class, [
                'label' => 'Sujet',
                'placeholder' => 'Choisir…',
                'choices' => ['Question' => 'question', 'Bug' => 'bug', 'Suggestion' => 'idea'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('terms', CheckboxType::class, [
                'label' => 'J\'accepte les conditions',
                'constraints' => [new Assert\IsTrue(message: 'Vous devez accepter les conditions.')],
            ])
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->addFlash('success', 'Formulaire valide.');

            return $this->redirectToRoute('dev_components');
        }

        return $this->render('dev/components.html.twig', [
            'form' => $form,
        ]);
    }
}
