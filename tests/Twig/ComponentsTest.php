<?php

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Extra classes passed to a component are added to its own, never replace them.
 */
final class ComponentsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testButtonKeepsItsClassesWithExtraClass(): void
    {
        $button = $this->renderTwigComponent('Button', ['type' => 'submit', 'class' => 'sm:flex-1', 'data-test' => 'x'], 'Envoyer')->crawler()->filter('button');

        self::assertSame('submit', $button->attr('type'));
        self::assertSame('x', $button->attr('data-test'));
        foreach (['bg-indigo-500', 'min-h-11', 'sm:flex-1'] as $class) {
            self::assertStringContainsString($class, $button->attr('class'));
        }
    }

    public function testButtonAsLinkKeepsItsClassesWithExtraClass(): void
    {
        $link = $this->renderTwigComponent('Button', ['href' => '/x', 'variant' => 'secondary', 'class' => 'mt-4'], 'Lien')->crawler()->filter('a');

        self::assertSame('/x', $link->attr('href'));
        self::assertStringContainsString('bg-slate-800', $link->attr('class'));
        self::assertStringContainsString('mt-4', $link->attr('class'));
    }

    public function testBadgeKeepsItsClassesWithExtraClass(): void
    {
        $badge = $this->renderTwigComponent('Badge', ['status' => 'success', 'class' => 'ml-2'], 'Validé')->crawler()->filter('span')->first();

        self::assertStringContainsString('bg-emerald-950', $badge->attr('class'));
        self::assertStringContainsString('ml-2', $badge->attr('class'));
    }

    public function testCardAndEmptyStateKeepTheirClassesWithExtraClass(): void
    {
        $card = $this->renderTwigComponent('Card', ['class' => 'mt-6'])->crawler()->filter('section');
        self::assertStringContainsString('rounded-xl', $card->attr('class'));
        self::assertStringContainsString('mt-6', $card->attr('class'));

        $empty = $this->renderTwigComponent('EmptyState', ['title' => 'Vide', 'class' => 'mt-6'])->crawler()->filter('div')->first();
        self::assertStringContainsString('border-dashed', $empty->attr('class'));
        self::assertStringContainsString('mt-6', $empty->attr('class'));
    }
}
