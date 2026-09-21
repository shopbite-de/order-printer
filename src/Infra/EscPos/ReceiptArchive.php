<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\EscPos;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Veliu\OrderPrinter\Domain\Receipt\ReceiptArchiveInterface;

/**
 * The receipt copies PrintProcessor writes to <project>/<DATA_DIR>/<orderNumber>_<createdAt>.txt.
 *
 * @psalm-api
 */
final readonly class ReceiptArchive implements ReceiptArchiveInterface
{
    private string $directory;

    public function __construct(
        #[Autowire(env: 'DATA_DIR')]
        string $dataDirectory,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
    ) {
        $this->directory = $projectDir.$dataDirectory;
    }

    #[\Override]
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        $deleted = 0;
        foreach (glob($this->directory.'*.txt') ?: [] as $file) {
            $modifiedAt = filemtime($file);
            if (false === $modifiedAt || $modifiedAt >= $before->getTimestamp()) {
                continue;
            }
            if (@unlink($file)) {
                ++$deleted;
            }
        }

        return $deleted;
    }
}
