<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Times without a time zone (soirée hours, créneaux, check-ins) are local
     * times of the collectives: make PHP agree, whatever the server's php.ini.
     */
    public function boot(): void
    {
        parent::boot();

        date_default_timezone_set($this->getContainer()->getParameter('app.timezone'));
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
