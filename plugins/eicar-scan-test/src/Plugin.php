<?php
declare(strict_types=1);

/**
 * Harmless EICAR test marker. It is data only and is never executed.
 * Remove this package after confirming the scanner result.
 */
final class EicarScanTestPlugin implements LiteBBSPlugin
{
    public function boot(PluginContext $context): void
    {
        // This test package intentionally registers no behavior.
    }
}
