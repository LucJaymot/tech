<?php

namespace App\Pwa;

use SpomkyLabs\PwaBundle\ImageProcessor\Configuration;
use SpomkyLabs\PwaBundle\ImageProcessor\ImageProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Keeps the PWA icons resized by GD: the bundle redoes it on every kernel
 * boot outside production (≈ 300 ms, on each test).
 */
#[AsDecorator('pwa.image_processor.gd')]
final class ImagesPwaEnCache implements ImageProcessorInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly ImageProcessorInterface $inner,
        private readonly CacheInterface $cache,
    ) {
    }

    public function process(string $image, ?int $width, ?int $height, ?string $format, ?Configuration $configuration = null): string
    {
        $cle = 'pwa_image_'.hash('xxh128', $image.'|'.$width.'|'.$height.'|'.$format.'|'.$configuration);

        return $this->cache->get($cle, fn (): string => $this->inner->process($image, $width, $height, $format, $configuration));
    }

    public function getSizes(string $image): array
    {
        return $this->inner->getSizes($image);
    }
}
