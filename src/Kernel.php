<?php

namespace Veliu\OrderPrinter;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/** @psalm-api */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
