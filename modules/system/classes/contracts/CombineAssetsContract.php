<?php namespace System\Classes\Contracts;

use System\Classes\CombineAssets;

/**
 * Interface CombineAssetsContract
 *
 * @package System\Classes\Contracts
 */
interface CombineAssetsContract
{
    /**
     * @return self
     * @deprecated V1.0.xxx Instead of using this method,
     *                      rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): self;

    /**
     * Combines JavaScript or StyleSheet file references
     * to produce a page relative URL to the combined contents.
     *
     *     $assets = [
     *         'assets/vendor/mustache/mustache.js',
     *         'assets/js/vendor/jquery.ui.widget.js',
     *         'assets/js/vendor/canvas-to-blob.js',
     *     ];
     *
     *     CombineAssets::combine($assets, base_path('plugins/acme/blog'));
     *
     * @param array $assets Collection of assets
     * @param string $localPath Prefix all assets with this path (optional)
     * @return string URL to contents.
     * @todo Type hint!
     */
    public static function combine($assets = [], $localPath = null): string;

    /**
     * Combines a collection of assets files to a destination file
     *
     *     $assets = [
     *         'assets/less/header.less',
     *         'assets/less/footer.less',
     *     ];
     *
     *     CombineAssets::combineToFile(
     *         $assets,
     *         base_path('themes/website/assets/theme.less'),
     *         base_path('themes/website')
     *     );
     *
     * @param array $assets Collection of assets
     * @param string $destination Write the combined file to this location
     * @param string $localPath Prefix all assets with this path (optional)
     * @return void
     * @todo Type hint!
     * @todo This is never used by the system, remove?
     */
    public function combineToFile($assets, $destination, $localPath = null);

    /**
     * Returns the combined contents from a prepared cache identifier.
     *
     * @param string $cacheKey Cache identifier.
     * @return string Combined file contents.
     * @todo Type hint!
     */
    public function getContents($cacheKey): string;

    /**
     * Registers a callback function that defines bundles.
     * The callback function should register bundles by calling the manager's
     * `registerBundle` method. This instance is passed to the callback
     * function as an argument. Usage:
     *
     *     CombineAssets::registerCallback(function ($combiner) {
     *         $combiner->registerBundle('~/modules/backend/assets/less/october.less');
     *     });
     *
     * @param callable $callback A callable function.
     * @return void
     */
    public static function registerCallback(callable $callback);

    /**
     * Register a filter to apply to the combining process.
     *
     * @param string|array $extension Extension name. Eg: css
     * @param object $filter Collection of files to combine.
     * @return self
     * @todo Type hint!
     */
    public function registerFilter($extension, $filter): self;

    /**
     * Clears any registered filters.
     *
     * @param string $extension Extension name. Eg: css
     * @return self
     * @todo Type hint!
     */
    public function resetFilters($extension = null): CombineAssetsContract;

    /**
     * Returns filters.
     *
     * @param string $extension Extension name. Eg: css
     * @return array|null
     * @todo Type hint!
     */
    public function getFilters($extension = null);

    /**
     * Registers bundle.
     *
     * @param string|array $files Files to be registered to bundle
     * @param string $destination Destination file will be compiled to.
     * @param string $extension Extension name. Eg: css
     * @return self
     * @todo Type hint!
     */
    public function registerBundle($files, $destination = null, $extension = null): CombineAssetsContract;

    /**
     * Returns bundles.
     *
     * @param string $extension Extension name. Eg: css
     * @return array|null
     * @todo Type hint!
     */
    public function getBundles($extension = null);

    /**
     * Register an alias to use for a longer file reference.
     *
     * @param string $alias Alias name. Eg: framework
     * @param string $file Path to file to use for alias
     * @param string $extension Extension name. Eg: css
     * @return self
     * @todo Type hint!
     */
    public function registerAlias($alias, $file, $extension = null): CombineAssetsContract;

    /**
     * Clears any registered aliases.
     *
     * @param string $extension Extension name. Eg: css
     * @return self
     * @todo Type hint!
     * @todo Never used internally
     */
    public function resetAliases($extension = null): CombineAssetsContract;

    /**
     * Returns aliases.
     *
     * @param string $extension Extension name. Eg: css
     * @return array|null
     * @todo Type hint!
     */
    public function getAliases($extension = null);

    /**
     * Resets the combiner cache
     *
     * @return void
     */
    public static function resetCache();
}
