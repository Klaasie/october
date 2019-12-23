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
     * Similar function to including vendor/autoload.php.
     *
     * @param string $vendorPath Absolute path to the vendor directory.
     * @return void
     */
    public function autoload($vendorPath);
}
