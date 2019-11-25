<?php namespace System\Classes\Contracts;

use Closure;

/**
 * Interface MarkupManagerContract
 *
 * @package System\Classes\Contracts
 */
interface MarkupManagerContract
{
    /**
     * Registers a callback function that defines simple Twig extensions.
     * The callback function should register menu items by calling the manager's
     * `registerFunctions`, `registerFilters`, `registerTokenParsers` function.
     * The manager instance is passed to the callback function as an argument. Usage:
     *
     *     MarkupManager::registerCallback(function ($manager) {
     *         $manager->registerFilters([...]);
     *         $manager->registerFunctions([...]);
     *         $manager->registerTokenParsers([...]);
     *     });
     *
     * @param callable $callback A callable function.
     */
    public function registerCallback(callable $callback);

    /**
     * Registers the CMS Twig extension items.
     * The argument is an array of the extension definitions. The array keys represent the
     * function/filter name, specific for the plugin/module. Each element in the
     * array should be an associative array.
     *
     * @param string $type The extension type: filters, functions, tokens
     * @param array $definitions An array of the extension definitions.
     * @return void
     * @todo Type hint!
     */
    public function registerExtensions($type, array $definitions);

    /**
     * Registers a CMS Twig Filter
     *
     * @param array $definitions An array of the extension definitions.
     * @return void
     */
    public function registerFilters(array $definitions);

    /**
     * Registers a CMS Twig Function
     *
     * @param array $definitions An array of the extension definitions.
     * @return void
     */
    public function registerFunctions(array $definitions);

    /**
     * Registers a CMS Twig Token Parser
     *
     * @param array $definitions An array of the extension definitions.
     * @return void
     */
    public function registerTokenParsers(array $definitions);

    /**
     * Returns a list of the registered Twig extensions of a type.
     *
     * @param $type string The Twig extension type
     * @return array
     * @todo Type hint!
     */
    public function listExtensions($type): array;

    /**
     * Returns a list of the registered Twig filters.
     *
     * @return array
     */
    public function listFilters(): array;

    /**
     * Returns a list of the registered Twig functions.
     *
     * @return array
     */
    public function listFunctions(): array;

    /**
     * Returns a list of the registered Twig token parsers.
     *
     * @return array
     */
    public function listTokenParsers(): array;

    /**
     * Makes a set of Twig functions for use in a twig extension.
     *
     * @param  array $functions Current collection
     * @return array
     * @todo Type hint!
     */
    public function makeTwigFunctions($functions = []): array;

    /**
     * Makes a set of Twig filters for use in a twig extension.
     *
     * @param  array $filters Current collection
     * @return array
     * @todo Type hint!
     */
    public function makeTwigFilters($filters = []): array;

    /**
     * Makes a set of Twig token parsers for use in a twig extension.
     *
     * @param  array $parsers Current collection
     * @return array
     * @todo Type hint!
     */
    public function makeTwigTokenParsers($parsers = []): array;

    /**
     * Execute a single serving transaction, containing filters, functions,
     * and token parsers that are disposed of afterwards.
     *
     * @param  Closure  $callback
     * @return void
     */
    public function transaction(Closure $callback);

    /**
     * Start a new transaction.
     *
     * @return void
     */
    public function beginTransaction();

    /**
     * Ends an active transaction.
     *
     * @return void
     */
    public function endTransaction();
}
