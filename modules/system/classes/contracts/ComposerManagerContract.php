<?php

namespace System\Classes\Contracts;

/**
 * Interface ComposerManagerContract
 *
 * @package System\Classes\Contracts
 */
interface ComposerManagerContract
{
    /**
     * @return self
     * @deprecated V1.0.xxx Instead of using this method,
     *                      rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): self;

    /**
     * Similar function to including vendor/autoload.php.
     *
     * @param string $vendorPath Absolute path to the vendor directory.
     * @return void
     */
    public function autoload($vendorPath);
}
