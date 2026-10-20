<?php

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Extra classes passed to a component are added to its own, never replace them.
 */
final class ComponentsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private static function classOf(Crawler $node): string
    {
        $class = $node->attr('class');
        self::assertIsString($class);

        return $class;
    }

    public function testButtonKeepsItsClassesWithExtraClass(): void
    {
        $button = $this->renderTwigComponent('Button', ['type' => 'submit', 'class' => 'sm:flex-1', 'data-test' => 'x'], 'Envoyer')->crawler()->filter('button');

        self::assertSame('submit', $button->attr('type'));
        self::assertSame('x', $button->attr('data-test'));
        foreach (['bg-(--accent)', 'min-h-11', 'sm:flex-1'] as $class) {
            self::assertStringContainsString($class, self::classOf($button));
        }
    }

    public function testButtonAsLinkKeepsItsClassesWithExtraClass(): void
    {
        $link = $this->renderTwigComponent('Button', ['href' => '/x', 'variant' => 'secondary', 'class' => 'mt-4'], 'Lien')->crawler()->filter('a');

        self::assertSame('/x', $link->attr('href'));
        self::assertStringContainsString('bg-touche', self::classOf($link));
        self::assertStringContainsString('mt-4', self::classOf($link));
    }

    public function testBadgeKeepsItsClassesWithExtraClass(): void
    {
        $badge = $this->renderTwigComponent('Badge', ['status' => 'success', 'class' => 'ml-2'], 'Validé')->crawler()->filter('span')->first();

        self::assertStringContainsString('text-emerald-400', self::classOf($badge));
        self::assertStringContainsString('ml-2', self::classOf($badge));
    }

    public function testLedAlwaysCarriesItsLabelAndStatusText(): void
    {
        $led = $this->renderTwigComponent('Led', ['statut' => 'bloquant', 'libelle' => '2 manquants'])->crawler()->filter('[data-led]');

        self::assertSame('bloquant', $led->attr('data-led'));
        self::assertSame('Bloquant : 2 manquants', trim((string) preg_replace('/\s+/', ' ', $led->text())));
        self::assertStringContainsString('bg-red-500', self::classOf($led->filter('span[aria-hidden="true"]')));

        $ok = $this->renderTwigComponent('Led', ['statut' => 'ok', 'libelle' => 'Complet'])->crawler()->filter('[data-led]');
        self::assertSame('OK : Complet', trim((string) preg_replace('/\s+/', ' ', $ok->text())));
    }

    public function testCardAndEmptyStateKeepTheirClassesWithExtraClass(): void
    {
        $card = $this->renderTwigComponent('Card', ['class' => 'mt-6'])->crawler()->filter('section');
        self::assertStringContainsString('rounded-xl', self::classOf($card));
        self::assertStringContainsString('mt-6', self::classOf($card));

        $empty = $this->renderTwigComponent('EmptyState', ['title' => 'Vide', 'class' => 'mt-6'])->crawler()->filter('div')->first();
        self::assertStringContainsString('border-dashed', self::classOf($empty));
        self::assertStringContainsString('mt-6', self::classOf($empty));
    }
}
