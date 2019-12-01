<?php namespace System\Classes;

use Assetic\Filter\CssImportFilter;
use Assetic\Filter\CssRewriteFilter;
use Assetic\Filter\JSMinFilter;
use Exception;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use October\Rain\Filesystem\Filesystem;
use October\Rain\Parse\Assetic\JavascriptImporter;
use October\Rain\Parse\Assetic\LessCompiler;
use October\Rain\Parse\Assetic\ScssCompiler;
use October\Rain\Parse\Assetic\StylesheetMinify;
use System\Classes\Contracts\CombineAssetsContract;
use Assetic\Asset\FileAsset;
use Assetic\Asset\AssetCache;
use Assetic\Asset\AssetCollection;
use Assetic\Factory\AssetFactory;
use October\Rain\Parse\Assetic\FilesystemCache;
use System\Helpers\Cache as CacheHelper;
use ApplicationException;
use DateTime;

/**
 * Combiner class used for combining JavaScript and StyleSheet files.
 *
 * This works by taking a collection of asset locations, serializing them,
 * then storing them in the session with a unique ID. The ID is then used
 * to generate a URL to the `/combine` route via the system controller.
 *
 * When the combine route is hit, the unique ID is used to serve up the
 * assets -- minified, compiled or both. Special E-Tags are used to prevent
 * compilation and delivery of cached assets that are unchanged.
 *
 * Use the `CombineAssets::combine` method to combine your own assets.
 *
 * The functionality of this class is controlled by these config items:
 *
 * - cms.enableAssetCache - Cache untouched assets
 * - cms.enableAssetMinify - Compress assets using minification
 * - cms.enableAssetDeepHashing - Advanced caching of imports
 *
 * @see \System\Classes\SystemController System controller
 * @see https://octobercms.com/docs/services/session Session service
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class CombineAssets implements CombineAssetsContract
{
    /**
     * @var Application The application instance, since Plugins are an extension of a Service Provider
     */
    protected $app;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Repository
     */
    private $config;

    /**
     * @var Translator
     */
    private $translator;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Dispatcher
     */
    private $events;

    /**
     * @var Router
     */
    private $router;

    /**
     * @var UrlGenerator
     */
    private $urlGenerator;

    /**
     * @var ResponseFactory
     */
    private $response;

    /**
     * @var array A list of known JavaScript extensions.
     */
    protected static $jsExtensions = ['js'];

    /**
     * @var array A list of known StyleSheet extensions.
     */
    protected static $cssExtensions = ['css', 'less', 'scss', 'sass'];

    /**
     * @var array Aliases for asset file paths.
     */
    protected $aliases = [];

    /**
     * @var array Bundles that are compiled to the filesystem.
     */
    protected $bundles = [];

    /**
     * @var array Filters to apply to each file.
     */
    protected $filters = [];

    /**
     * @var string The local path context to find assets.
     */
    protected $localPath;

    /**
     * @var string The output folder for storing combined files.
     */
    protected $storagePath;

    /**
     * @var bool Cache untouched files.
     */
    public $useCache = false;

    /**
     * @var bool Compress (minify) asset files.
     */
    public $useMinify = false;

    /**
     * @var bool When true, cache will be busted when an import is modified.
     * Enabling this feature will make page loading slower.
     */
    public $useDeepHashing = false;

    /**
     * @var array Cache of registration callbacks.
     */
    private static $callbacks = [];

    /**
     * CombineAssets constructor.
     * @param Application $app
     * @param Filesystem $filesystem
     * @param Repository $config
     * @param Translator $translator
     * @param Request $request
     * @param UrlGenerator $urlGenerator
     * @param CacheRepository $cache
     * @param ResponseFactory $response
     */
    public function __construct(
        Application $app,
        Filesystem $filesystem,
        Repository $config,
        Translator $translator,
        Request $request,
        UrlGenerator $urlGenerator,
        ResponseFactory $response
    ) {
        $this->app = $app;
        $this->filesystem = $filesystem;
        $this->config = $config;
        $this->translator = $translator;
        $this->request = $request;
        $this->events = resolve('events');
        $this->router = resolve('router');
        $this->urlGenerator = $urlGenerator;
        $this->response = $response;

        /*
         * Register preferences
         */
        $this->useCache = $this->config->get('cms.enableAssetCache', false);
        $this->useMinify = $this->config->get('cms.enableAssetMinify', null);
        $this->useDeepHashing = $this->config->get('cms.enableAssetDeepHashing', null);

        if ($this->useMinify === null) {
            $this->useMinify = !$this->config->get('app.debug', false);
        }

        if ($this->useDeepHashing === null) {
            $this->useDeepHashing = $this->config->get('app.debug', false);
        }

        /*
         * Register JavaScript filters
         */
        $this->registerFilter('js', new JavascriptImporter);

        /*
         * Register CSS filters
         */
        $this->registerFilter('css', new CssImportFilter);
        $this->registerFilter(['css', 'less', 'scss'], new CssRewriteFilter);
        $this->registerFilter('less', new LessCompiler);
        $this->registerFilter('scss', new ScssCompiler);

        /*
         * Minification filters
         */
        if ($this->useMinify) {
            $this->registerFilter('js', new JSMinFilter);
            $this->registerFilter(['css', 'less', 'scss'], new StylesheetMinify);
        }

        /*
         * Common Aliases
         */
        $this->registerAlias('jquery', '~/modules/backend/assets/js/vendor/jquery-and-migrate.min.js');
        $this->registerAlias('framework', '~/modules/system/assets/js/framework.js');
        $this->registerAlias('framework.extras', '~/modules/system/assets/js/framework.extras.js');
        $this->registerAlias('framework.extras.js', '~/modules/system/assets/js/framework.extras.js');
        $this->registerAlias('framework.extras', '~/modules/system/assets/css/framework.extras.css');
        $this->registerAlias('framework.extras.css', '~/modules/system/assets/css/framework.extras.css');

        /*
         * Deferred registration
         */
        foreach (static::$callbacks as $callback) {
            $callback($this);
        }
    }

    /**
     * Static combine method
     * Kept this one to remain backwards compatible.
     *
     * @deprecated V1.0.xxx Instead of using this method,
     *             rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): CombineAssets
    {
        return resolve(self::class);
    }

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
     * Kept this one to remain backwards compatible.
     *
     * @param array $assets Collection of assets
     * @param string $localPath Prefix all assets with this path (optional)
     * @return string URL to contents.
     * @deprecated V1.0.xxx Instead of using this method,
     *             rework your logic to resolve the class through dependency injection.
     */
    public static function combine($assets = [], $localPath = null): string
    {
        /** @var CombineAssetsContract $combineAssets */
        $combineAssets = resolve(self::class);
        return $combineAssets->prepareRequest($assets, $localPath);
    }

    /**
     * {@inheritDoc}
     */
    public function combineToFile($assets, $destination, $localPath = null)
    {
        // Disable cache always
        $this->storagePath = null;

        // Prefix all assets
        if ($localPath) {
            if (substr($localPath, -1) !== '/') {
                $localPath .= '/';
            }
            $assets = array_map(static function ($asset) use ($localPath) {
                if (strpos($asset, '@') === 0) {
                    return $asset;
                }
                return $localPath.$asset;
            }, $assets);
        }

        list($assets, $extension) = $this->prepareAssets($assets);

        $rewritePath = $this->filesystem->localToPublic(dirname($destination));

        $combiner = $this->prepareCombiner($assets, $rewritePath);

        $contents = $combiner->dump();

        $this->filesystem->put($destination, $contents);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     * @throws Exception
     */
    public function getContents($cacheKey): string
    {
        $cacheInfo = $this->getCache($cacheKey);
        if (!$cacheInfo) {
            throw new ApplicationException(
                $this->translator->get('system::lang.combiner.not_found', ['name' => $cacheKey])
            );
        }

        $this->localPath = $cacheInfo['path'];
        $this->storagePath = storage_path('cms/combiner/assets');

        /*
         * Analyse cache information
         */
        $lastModifiedTime = gmdate("D, d M Y H:i:s \G\M\T", array_get($cacheInfo, 'lastMod'));
        $etag = array_get($cacheInfo, 'etag');
        $mime = (array_get($cacheInfo, 'extension') === 'css')
            ? 'text/css'
            : 'application/javascript';

        /*
         * Set 304 Not Modified header, if necessary
         */
        $response = $this->response->make();
        $response->header('Content-Type', $mime);
        $response->header('Cache-Control', 'private, max-age=604800');
        $response->setLastModified(new DateTime($lastModifiedTime));
        $response->setEtag($etag);
        $response->setPublic();
        $modified = !$response->isNotModified($this->app->make('request'));

        /*
         * Request says response is cached, no code evaluation needed
         */
        if ($modified) {
            $this->setHashOnCombinerFilters($cacheKey);
            $combiner = $this->prepareCombiner($cacheInfo['files']);
            $contents = $combiner->dump();
            $response->setContent($contents);
        }

        return $response;
    }

    /**
     * Prepares an array of assets by normalizing the collection and processing aliases.
     *
     * @param array $assets
     * @return array
     */
    protected function prepareAssets(array $assets): array
    {
        if (!is_array($assets)) {
            $assets = [$assets];
        }

        /*
         * Split assets in to groups.
         */
        $combineJs = [];
        $combineCss = [];

        foreach ($assets as $asset) {
            /*
             * Allow aliases to go through without an extension
             */
            if (strpos($asset, '@') === 0) {
                $combineJs[] = $asset;
                $combineCss[] = $asset;
                continue;
            }

            $extension = $this->filesystem->extension($asset);

            if (in_array($extension, self::$jsExtensions)) {
                $combineJs[] = $asset;
                continue;
            }

            if (in_array($extension, self::$cssExtensions)) {
                $combineCss[] = $asset;
                continue;
            }
        }

        /*
         * Determine which group of assets to combine.
         */
        if (count($combineCss) > count($combineJs)) {
            $extension = 'css';
            $assets = $combineCss;
        }
        else {
            $extension = 'js';
            $assets = $combineJs;
        }

        /*
         * Apply registered aliases
         */
        if ($aliasMap = $this->getAliases($extension)) {
            foreach ($assets as $key => $asset) {
                if (strpos($asset, '@') !== 0) {
                    continue;
                }
                $_asset = substr($asset, 1);

                if (isset($aliasMap[$_asset])) {
                    $assets[$key] = $aliasMap[$_asset];
                }
            }
        }

        return [$assets, $extension];
    }

    /**
     * {@inheritDoc}
     */
    public function prepareRequest(array $assets, $localPath = null): string
    {
        if (substr($localPath, -1) !== '/') {
            $localPath .= '/';
        }

        $this->localPath = $localPath;
        $this->storagePath = storage_path('cms/combiner/assets');

        list($assets, $extension) = $this->prepareAssets($assets);

        /*
         * Cache and process
         */
        $cacheKey = $this->getCacheKey($assets);
        $cacheInfo = $this->useCache ? $this->getCache($cacheKey) : false;

        if (!$cacheInfo) {
            $this->setHashOnCombinerFilters($cacheKey);

            $combiner = $this->prepareCombiner($assets);

            if ($this->useDeepHashing) {
                $factory = new AssetFactory($this->localPath);
                $lastMod = $factory->getLastModified($combiner);
            }
            else {
                $lastMod = $combiner->getLastModified();
            }

            $cacheInfo = [
                'version'   => $cacheKey.'-'.$lastMod,
                'etag'      => $cacheKey,
                'lastMod'   => $lastMod,
                'files'     => $assets,
                'path'      => $this->localPath,
                'extension' => $extension
            ];

            $this->putCache($cacheKey, $cacheInfo);
        }

        return $this->getCombinedUrl($cacheInfo['version']);
    }

    /**
     * Returns the combined contents from a prepared cache identifier.
     *
     * @param array $assets List of asset files.
     * @param string $rewritePath
     * @return string|AssetCollection Combined file contents.
     */
    protected function prepareCombiner(array $assets, $rewritePath = null)
    {
        /**
         * @event cms.combiner.beforePrepare
         * Provides an opportunity to interact with the asset combiner before assets are combined
         *
         * Example usage:
         *
         *     Event::listen('cms.combiner.beforePrepare', function ((\System\Classes\CombineAssets) $assetCombiner, (array) $assets) {
         *         $assetCombiner->registerFilter(...)
         *     });
         *
         */
        $this->events->fire('cms.combiner.beforePrepare', [$this, $assets]);

        $files = [];
        $filesSalt = null;
        foreach ($assets as $asset) {
            $filters = $this->getFilters($this->filesystem->extension($asset)) ?: [];
            $path = file_exists($asset)
                ? $asset
                : $this->filesystem->symbolizePath($asset, null)
                    ?: $this->localPath . $asset;
            $files[] = new FileAsset($path, $filters, public_path());
            $filesSalt .= $this->localPath . $asset;
        }
        $filesSalt = md5($filesSalt);

        $collection = new AssetCollection($files, [], $filesSalt);
        $collection->setTargetPath($this->getTargetPath($rewritePath));

        if ($this->storagePath === null) {
            return $collection;
        }

        if (!$this->filesystem->isDirectory($this->storagePath)) {
            @$this->filesystem->makeDirectory($this->storagePath);
        }

        $cache = new FilesystemCache($this->storagePath);

        $cachedFiles = [];
        foreach ($files as $file) {
            $cachedFiles[] = new AssetCache($file, $cache);
        }

        $cachedCollection = new AssetCollection($cachedFiles, [], $filesSalt);
        $cachedCollection->setTargetPath($this->getTargetPath($rewritePath));
        return $cachedCollection;
    }

    /**
     * Busts the cache based on a different cache key.
     *
     * @param $hash
     * @return void
     */
    protected function setHashOnCombinerFilters($hash)
    {
        $allFilters = call_user_func_array('array_merge', $this->getFilters());

        foreach ($allFilters as $filter) {
            if (method_exists($filter, 'setHash')) {
                $filter->setHash($hash);
            }
        }
    }

    /**
     * Returns a deep hash on filters that support it.
     *
     * @param array $assets List of asset files.
     * @return string
     */
    protected function getDeepHashFromAssets($assets): string
    {
        $key = '';

        $assetFiles = array_map(function ($file) {
            return file_exists($file)
                ? $file
                : $this->filesystem->symbolizePath($file, null)
                    ?: $this->localPath . $file;
        }, $assets);

        foreach ($assetFiles as $file) {
            $filters = $this->getFilters($this->filesystem->extension($file));

            foreach ($filters as $filter) {
                if (method_exists($filter, 'hashAsset')) {
                    $key .= $filter->hashAsset($file, $this->localPath);
                }
            }
        }

        return $key;
    }

    /**
     * Returns the URL used for accessing the combined files.
     *
     * @param string $outputFilename A custom file name to use.
     * @return string
     */
    protected function getCombinedUrl($outputFilename = 'undefined.css'): string
    {
        $combineAction = 'System\Classes\Controller@combine';
        $actionExists = $this->router->getRoutes()->getByAction($combineAction) !== null;

        if ($actionExists) {
            return $this->urlGenerator->action($combineAction, [$outputFilename], false);
        }

        return '/combine/'.$outputFilename;
    }

    /**
     * Returns the target path for use with the combiner. The target
     * path helps generate relative links within CSS.
     *
     * /combine              returns combine/
     * /index.php/combine    returns index-php/combine/
     *
     * @param string|null $path
     * @return string The new target path
     */
    protected function getTargetPath($path = null): string
    {
        if ($path === null) {
            $baseUri = substr($this->request->getBaseUrl(), strlen($this->request->getBasePath()));
            $path = $baseUri.'/combine';
        }

        if (strpos($path, '/') === 0) {
            $path = substr($path, 1);
        }

        $path = str_replace('.', '-', $path).'/';
        return $path;
    }

    //
    // Registration
    //

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
     * Kept this one to remain backwards compatible.
     *
     * @param callable $callback A callable function.
     * @return void
     * @deprecated V1.0.xxx Instead of using this method,
     *             rework your logic to resolve the class through dependency injection.
     */
    public static function registerCallback(callable $callback)
    {
        self::$callbacks[] = $callback;
    }

    //
    // Filters
    //

    /**
     * {@inheritDoc}
     */
    public function registerFilter($extension, $filter): CombineAssetsContract
    {
        if (is_array($extension)) {
            foreach ($extension as $_extension) {
                $this->registerFilter($_extension, $filter);
            }
            return $this;
        }

        $extension = strtolower($extension);

        if (!isset($this->filters[$extension])) {
            $this->filters[$extension] = [];
        }

        if ($filter !== null) {
            $this->filters[$extension][] = $filter;
        }

        return $this;
    }

    /**
     * Clears any registered filters.
     *
     * @param string $extension Extension name. Eg: css
     * @return self
     */
    public function resetFilters($extension = null): CombineAssetsContract
    {
        if ($extension === null) {
            $this->filters = [];
        }
        else {
            $this->filters[$extension] = [];
        }

        return $this;
    }

    /**
     * Returns filters.
     *
     * @param string $extension Extension name. Eg: css
     * @return array|null
     */
    public function getFilters($extension = null)
    {
        if ($extension === null) {
            return $this->filters;
        }

        return $this->filters[$extension] ?? null;
    }

    //
    // Bundles
    //

    /**
     * Registers bundle.
     *
     * @param string|array $files Files to be registered to bundle
     * @param string $destination Destination file will be compiled to.
     * @param string $extension Extension name. Eg: css
     * @return self
     */
    public function registerBundle($files, $destination = null, $extension = null): CombineAssetsContract
    {
        if (!is_array($files)) {
            $files = [$files];
        }

        $firstFile = array_values($files)[0];

        if ($extension === null) {
            $extension = $this->filesystem->extension($firstFile);
        }

        $extension = strtolower(trim($extension));

        if ($destination === null) {
            $file = $this->filesystem->name($firstFile);
            $path = dirname($firstFile);
            $preprocessors = array_diff(self::$cssExtensions, ['css']);

            if (in_array($extension, $preprocessors)) {
                $cssPath = $path.'/../css';
                if (
                    $this->filesystem->isDirectory($this->filesystem->symbolizePath($cssPath)) &&
                    in_array(strtolower(basename($path)), $preprocessors)
                ) {
                    $path = $cssPath;
                }
                $destination = $path.'/'.$file.'.css';
            }
            else {
                $destination = $path.'/'.$file.'-min.'.$extension;
            }
        }

        $this->bundles[$extension][$destination] = $files;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getBundles($extension = null)
    {
        if ($extension === null) {
            return $this->bundles;
        }

        return $this->bundles[$extension] ?? null;
    }

    //
    // Aliases
    //

    /**
     * {@inheritDoc}
     */
    public function registerAlias($alias, $file, $extension = null): CombineAssetsContract
    {
        if ($extension === null) {
            $extension = $this->filesystem->extension($file);
        }

        $extension = strtolower($extension);

        if (!isset($this->aliases[$extension])) {
            $this->aliases[$extension] = [];
        }

        $this->aliases[$extension][$alias] = $file;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function resetAliases($extension = null): CombineAssetsContract
    {
        if ($extension === null) {
            $this->aliases = [];
        }
        else {
            $this->aliases[$extension] = [];
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getAliases($extension = null)
    {
        if ($extension === null) {
            return $this->aliases;
        }

        return $this->aliases[$extension] ?? null;
    }

    //
    // Cache
    //

    /**
     * Stores information about a asset collection against a cache identifier.
     *
     * @param string $cacheKey Cache identifier.
     * @param array $cacheInfo List of asset files.
     * @return bool Successful
     */
    protected function putCache($cacheKey, array $cacheInfo): bool
    {
        $cacheKey = 'combiner.'.$cacheKey;

        /** @var CacheRepository $cache */
        $cache = resolve(CacheRepository::class);

        if ($cache->has($cacheKey)) {
            return false;
        }

        $this->putCacheIndex($cacheKey);

        $cache->forever($cacheKey, base64_encode(serialize($cacheInfo)));

        return true;
    }

    /**
     * Look up information about a cache identifier.
     *
     * @param string $cacheKey Cache identifier
     * @return array|bool Cache information
     */
    protected function getCache($cacheKey)
    {
        $cacheKey = 'combiner.'.$cacheKey;

        /** @var CacheRepository $cache */
        $cache = resolve(CacheRepository::class);

        if (!$cache->has($cacheKey)) {
            return false;
        }

        return @unserialize(@base64_decode($cache->get($cacheKey)));
    }

    /**
     * Builds a unique string based on assets
     *
     * @param array $assets Asset files
     * @return string Unique identifier
     */
    protected function getCacheKey(array $assets): string
    {
        $cacheKey = $this->localPath . implode('|', $assets);

        /*
         * Deep hashing
         */
        if ($this->useDeepHashing) {
            $cacheKey .= $this->getDeepHashFromAssets($assets);
        }

        $dataHolder = (object) ['key' => $cacheKey];

        /**
         * @event cms.combiner.getCacheKey
         * Provides an opportunity to modify the asset combiner's cache key
         *
         * Example usage:
         *
         *     Event::listen('cms.combiner.getCacheKey', function ((\System\Classes\CombineAssets) $assetCombiner, (stdClass) $dataHolder) {
         *         $dataHolder->key = rand();
         *     });
         *
         */
        $dataHolder = (object) ['key' => $cacheKey];
        $this->events->fire('cms.combiner.getCacheKey', [$this, $dataHolder]);
        $cacheKey = $dataHolder->key;

        return md5($cacheKey);
    }

    /**
     * Resets the combiner cache
     *
     * Kept this one to remain backwards compatible.
     *
     * @return void
     * @deprecated V1.0.xxx Instead of using this method,
     *             rework your logic to resolve the class through dependency injection.
     */
    public static function resetCache()
    {
        /** @var CombineAssetsContract $self */
        $self = resolve(self::class);
        $self->forgetCache();
    }

    /**
     * Resets the combiner cache
     *
     * @return void
     */
    public function forgetCache()
    {
        /** @var CacheRepository $cache */
        $cache = resolve(CacheRepository::class);

        if ($cache->has('combiner.index')) {
            $index = (array) @unserialize(@base64_decode($cache->get('combiner.index'))) ?: [];

            foreach ($index as $cacheKey) {
                $cache->forget($cacheKey);
            }

            $cache->forget('combiner.index');
        }

        CacheHelper::instance()->clearCombiner();
    }

    /**
     * Adds a cache identifier to the index store used for performing a reset of the cache.
     *
     * @param string $cacheKey Cache identifier
     * @return bool Returns false if identifier is already in store
     */
    protected function putCacheIndex($cacheKey): bool
    {
        $index = [];

        /** @var CacheRepository $cache */
        $cache = resolve(CacheRepository::class);

        if ($cache->has('combiner.index')) {
            $index = (array) @unserialize(@base64_decode($cache->get('combiner.index'))) ?: [];
        }

        if (in_array($cacheKey, $index)) {
            return false;
        }

        $index[] = $cacheKey;

        $cache->forever('combiner.index', base64_encode(serialize($index)));

        return true;
    }
}
