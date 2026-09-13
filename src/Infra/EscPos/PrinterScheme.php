<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\EscPos;

enum PrinterScheme: string
{
    case File = 'file';
    case Tcp = 'tcp';
    case Dummy = 'dummy';
}
