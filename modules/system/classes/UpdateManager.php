<?php namespace System\Classes;

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use October\Rain\Exception\ApplicationException;
use October\Rain\Filesystem\Filesystem;
use System\Classes\Contracts\PluginManagerContract;
use System\Classes\Contracts\UpdateManagerContract;
use Http;
use Schema;
use Cms\Classes\ThemeManager;
use System\Models\Parameter;
use System\Models\PluginVersion;
use System\Helpers\Cache as CacheHelper;
use October\Rain\Filesystem\Zip;
use Carbon\Carbon;
use Exception;

/**
 * Update manager
 *
 * Handles the CMS install and update process.
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class UpdateManager implements UpdateManagerContract
{
    /**
     * @var array The notes for the current operation.
     */
    protected $notes = [];

    /**
     * @var OutputStyle
     */
    protected $notesOutput;

    /**
     * @var string Application base path.
     */
    protected $baseDirectory;

    /**
     * @var string A temporary working directory.
     */
    protected $tempDirectory;

    /**
     * @var PluginManagerContract
     */
    protected $pluginManager;

    /**
     * @var ThemeManager
     */
    protected $themeManager;

    /**
     * @var VersionManager
     */
    protected $versionManager;

    /**
     * @var Application
     */
    private $app;

    /**
     * @var Repository
     */
    private $config;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Translator
     */
    private $translator;

    /**
     * @var CacheRepository
     */
    private $cache;

    /**
     * @var UrlGenerator
     */
    private $urlGenerator;

    /**
     * @var string Secure API Key
     */
    protected $key;

    /**
     * @var string Secure API Secret
     */
    protected $secret;

    /**
     * @var boolean If set to true, core updates will not be downloaded or extracted.
     */
    protected $disableCoreUpdates = false;

    /**
     * @var array Cache of gateway products
     */
    protected $productCache;

    /**
     * @var Migrator
     */
    protected $migrator;

    /**
     * @var DatabaseMigrationRepository
     */
    protected $repository;

    /**
     * UpdateManager constructor.
     *
     * @param Application $app
     * @param PluginManagerContract $pluginManager
     * @param Repository $config
     * @param Filesystem $filesystem
     * @param Translator $translator
     * @param CacheRepository $cache
     * @param UrlGenerator $urlGenerator
     */
    public function __construct(
        Application $app,
        PluginManagerContract $pluginManager,
        Repository $config,
        Filesystem $filesystem,
        Translator $translator,
        CacheRepository $cache,
        UrlGenerator $urlGenerator
    ) {
        $this->pluginManager = $pluginManager;
        $this->themeManager = class_exists(ThemeManager::class) ? ThemeManager::instance() : null;
        $this->versionManager = VersionManager::instance();
        $this->app = $app;
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->translator = $translator;
        $this->cache = $cache;
        $this->urlGenerator = $urlGenerator;

        $this->tempDirectory = temp_path();
        $this->baseDirectory = base_path();
        $this->disableCoreUpdates = $this->config->get('cms.disableCoreUpdates', false);

        $this->migrator = $this->app->make('migrator');
        $this->repository = $this->app->make('migration.repository');

        /*
         * Ensure temp directory exists
         */
        if (!$this->filesystem->isDirectory($this->tempDirectory)) {
            $this->filesystem->makeDirectory($this->tempDirectory, 0777, true);
        }
    }

    /**
     * {@inheritDoc}
     */
    public static function instance(): UpdateManagerContract
    {
        return resolve(self::class);
    }

    /**
     * {@inheritDoc}
     */
    public function update(): UpdateManagerContract
    {
        $firstUp = !Schema::hasTable($this->getMigrationTableName());
        if ($firstUp) {
            $this->repository->createRepository();
            $this->note('Migration table created');
        }

        /*
         * Update modules
         */
        $modules = $this->config->get('cms.loadModules', []);
        foreach ($modules as $module) {
            $this->migrateModule($module);
        }

        /*
         * Update plugins
         */
        $plugins = $this->pluginManager->sortByDependencies();
        foreach ($plugins as $plugin) {
            $this->updatePlugin($plugin);
        }

        Parameter::set('system::update.count', 0);
        CacheHelper::clear();

        /*
         * Seed modules
         */
        if ($firstUp) {
            $modules = $this->config->get('cms.loadModules', []);
            foreach ($modules as $module) {
                $this->seedModule($module);
            }
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function check($force = false): int
    {
        /*
         * Already know about updates, never retry.
         */
        $oldCount = Parameter::get('system::update.count');
        if ($oldCount > 0) {
            return $oldCount;
        }

        /*
         * Retry period not passed, skipping.
         */
        if (!$force
            && ($retryTimestamp = Parameter::get('system::update.retry'))
            && Carbon::createFromTimeStamp($retryTimestamp)->isFuture()
        ) {
            return $oldCount;
        }

        try {
            $result = $this->requestUpdateList();
            $newCount = array_get($result, 'update', 0);
        }
        catch (Exception $ex) {
            $newCount = 0;
        }

        /*
         * Remember update count, set retry date
         */
        Parameter::set('system::update.count', $newCount);
        Parameter::set('system::update.retry', Carbon::now()->addHours(24)->timestamp);

        return $newCount;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function requestUpdateList($force = false): array
    {
        $installed = PluginVersion::all();
        $versions = $installed->lists('version', 'code');
        $names = $installed->lists('name', 'code');
        $icons = $installed->lists('icon', 'code');
        $frozen = $installed->lists('is_frozen', 'code');
        $updatable = $installed->lists('is_updatable', 'code');
        $build = Parameter::get('system::core.build');
        $themes = [];

        if ($this->themeManager) {
            $themes = array_keys($this->themeManager->getInstalled());
        }

        $params = [
            'core' => $this->getHash(),
            'plugins' => serialize($versions),
            'themes' => serialize($themes),
            'build' => $build,
            'force' => $force
        ];

        $result = $this->requestServerData('core/update', $params);
        $updateCount = (int) array_get($result, 'update', 0);

        /*
         * Inject known core build
         */
        if ($core = array_get($result, 'core')) {
            $core['old_build'] = Parameter::get('system::core.build');
            $result['core'] = $core;
        }

        /*
         * Inject the application's known plugin name and version
         */
        $plugins = [];
        foreach (array_get($result, 'plugins', []) as $code => $info) {
            $info['name'] = $names[$code] ?? $code;
            $info['old_version'] = $versions[$code] ?? false;
            $info['icon'] = $icons[$code] ?? false;

            /*
             * If a plugin has updates frozen, or cannot be updated,
             * do not add to the list and discount an update unit.
             */
            if (
                (isset($frozen[$code]) && $frozen[$code]) ||
                (isset($updatable[$code]) && !$updatable[$code])
            ) {
                $updateCount = max(0, --$updateCount);
            }
            else {
                $plugins[$code] = $info;
            }
        }
        $result['plugins'] = $plugins;

        /*
         * Strip out themes that have been installed before
         */
        if ($this->themeManager) {
            $themes = [];
            foreach (array_get($result, 'themes', []) as $code => $info) {
                if (!$this->themeManager->isInstalled($code)) {
                    $themes[$code] = $info;
                }
            }
            $result['themes'] = $themes;
        }

        /*
         * If there is a core update and core updates are disabled,
         * remove the entry and discount an update unit.
         */
        if ($this->disableCoreUpdates && array_get($result, 'core')) {
            $updateCount = max(0, --$updateCount);
            unset($result['core']);
        }

        /*
         * Recalculate the update counter
         */
        $updateCount += count($themes);
        $result['hasUpdates'] = $updateCount > 0;
        $result['update'] = $updateCount;
        Parameter::set('system::update.count', $updateCount);

        return $result;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function requestProjectDetails($projectId): array
    {
        return $this->requestServerData('project/detail', ['id' => $projectId]);
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(): UpdateManagerContract
    {
        /*
         * Rollback plugins
         */
        $plugins = $this->pluginManager->getPlugins();
        foreach ($plugins as $name => $plugin) {
            $this->rollbackPlugin($name);
        }

        /*
         * Register module migration files
         */
        $paths = [];
        $modules = $this->config->get('cms.loadModules', []);

        foreach ($modules as $module) {
            $paths[] = base_path() . '/modules/'.strtolower($module).'/database/migrations';
        }

        /*
         * Rollback modules
         */
        while (true) {
            $rolledBack = $this->migrator->rollback($paths, ['pretend' => false]);

            foreach ($this->migrator->getNotes() as $note) {
                $this->note($note);
            }

            if (count($rolledBack) === 0) {
                break;
            }
        }

        Schema::dropIfExists($this->getMigrationTableName());

        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function setBuildNumberManually(): int
    {
        $postData = [];

        if ($this->config->get('cms.edgeUpdates', false)) {
            $postData['edge'] = 1;
        }

        $result = $this->requestServerData('ping', $postData);

        $build = (int) array_get($result, 'pong', 420);

        $this->setBuild($build);

        return $build;
    }

    //
    // Modules
    //

    /**
     * {@inheritDoc}
     */
    public function getHash()
    {
        return Parameter::get('system::core.hash', md5('NULL'));
    }

    /**
     * {@inheritDoc}
     */
    public function migrateModule($module): UpdateManagerContract
    {
        $this->migrator->run(base_path() . '/modules/'.strtolower($module).'/database/migrations');

        $this->note($module);

        foreach ($this->migrator->getNotes() as $note) {
            $this->note(' - '.$note);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function seedModule($module): UpdateManagerContract
    {
        $className = '\\'.$module.'\Database\Seeds\DatabaseSeeder';
        if (!class_exists($className)) {
            return $this;
        }

        $seeder = $this->app->make($className);
        $seeder->run();

        $this->note(sprintf('<info>Seeded %s</info> ', $module));
        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     * @throws FileNotFoundException
     */
    public function downloadCore($hash)
    {
        $this->requestServerFile('core/get', 'core', $hash, ['type' => 'update']);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function extractCore()
    {
        $filePath = $this->getFilePath('core');

        if (!Zip::extract($filePath, $this->baseDirectory)) {
            throw new ApplicationException(
                $this->translator->get('system::lang.zip.extract_failed', ['file' => $filePath])
            );
        }

        @unlink($filePath);
    }

    /**
     * {@inheritDoc}
     */
    public function setBuild($build, $hash = null)
    {
        $params = [
            'system::core.build' => $build
        ];

        if ($hash) {
            $params['system::core.hash'] = $hash;
        }

        Parameter::set($params);
    }

    //
    // Plugins
    //

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function requestPluginDetails($name): array
    {
        return $this->requestServerData('plugin/detail', ['name' => $name]);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function requestPluginContent($name): array
    {
        return $this->requestServerData('plugin/content', ['name' => $name]);
    }

    /**
     * {@inheritDoc}
     */
    public function updatePlugin($name): UpdateManagerContract
    {
        /*
         * Update the plugin database and version
         */
        if (!($plugin = $this->pluginManager->findByIdentifier($name))) {
            $this->note('<error>Unable to find:</error> ' . $name);
            return $this;
        }

        $this->note($name);

        $this->versionManager->resetNotes()->setNotesOutput($this->notesOutput);

        if ($this->versionManager->updatePlugin($plugin) !== false) {
            foreach ($this->versionManager->getNotes() as $note) {
                $this->note($note);
            }
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function rollbackPlugin($name): UpdateManagerContract
    {
        /*
         * Remove the plugin database and version
         */
        if (!($plugin = $this->pluginManager->findByIdentifier($name))
            && $this->versionManager->purgePlugin($name)
        ) {
            $this->note('<info>Purged from database:</info> ' . $name);
            return $this;
        }

        if ($this->versionManager->removePlugin($plugin)) {
            $this->note('<info>Rolled back:</info> ' . $name);
            return $this;
        }

        $this->note('<error>Unable to find:</error> ' . $name);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     * @throws FileNotFoundException
     */
    public function downloadPlugin($name, $hash, $installation = false): UpdateManagerContract
    {
        $fileCode = $name . $hash;
        $this->requestServerFile('plugin/get', $fileCode, $hash, [
            'name' => $name,
            'installation' => $installation ? 1 : 0
        ]);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function extractPlugin($name, $hash)
    {
        $fileCode = $name . $hash;
        $filePath = $this->getFilePath($fileCode);

        if (!Zip::extract($filePath, $this->baseDirectory . '/plugins/')) {
            throw new ApplicationException(
                $this->translator->get('system::lang.zip.extract_failed', ['file' => $filePath])
            );
        }

        @unlink($filePath);
    }

    //
    // Themes
    //

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function requestThemeDetails($name): array
    {
        return $this->requestServerData('theme/detail', ['name' => $name]);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     * @throws FileNotFoundException
     */
    public function downloadTheme($name, $hash)
    {
        $fileCode = $name . $hash;

        $this->requestServerFile('theme/get', $fileCode, $hash, ['name' => $name]);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function extractTheme($name, $hash)
    {
        $fileCode = $name . $hash;
        $filePath = $this->getFilePath($fileCode);

        if (!Zip::extract($filePath, $this->baseDirectory . '/themes/')) {
            throw new ApplicationException(
                $this->translator->get('system::lang.zip.extract_failed', ['file' => $filePath])
            );
        }

        if ($this->themeManager) {
            $this->themeManager->setInstalled($name);
        }

        @unlink($filePath);
    }

    //
    // Products
    //

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function requestProductDetails($codes, $type = null): array
    {
        if ($type !== 'plugin' && $type !== 'theme') {
            $type = 'plugin';
        }

        $codes = (array) $codes;
        $this->loadProductDetailCache();

        /*
         * New products requested
         */
        $newCodes = array_diff($codes, array_keys($this->productCache[$type]));
        if (count($newCodes)) {
            $dataCodes = [];
            $data = $this->requestServerData($type.'/details', ['names' => $newCodes]);
            foreach ($data as $product) {
                $code = array_get($product, 'code', -1);
                $this->cacheProductDetail($type, $code, $product);
                $dataCodes[] = $code;
            }

            /*
             * Cache unknown products
             */
            $unknownCodes = array_diff($newCodes, $dataCodes);
            foreach ($unknownCodes as $code) {
                $this->cacheProductDetail($type, $code, -1);
            }

            $this->saveProductDetailCache();
        }

        /*
         * Build details from cache
         */
        $result = [];
        $requestedDetails = array_intersect_key($this->productCache[$type], array_flip($codes));

        foreach ($requestedDetails as $detail) {
            if ($detail === -1) {
                continue;
            }
            $result[] = $detail;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function requestPopularProducts($type = null): array
    {
        if ($type !== 'plugin' && $type !== 'theme') {
            $type = 'plugin';
        }

        $cacheKey = 'system-updates-popular-'.$type;

        if ($this->cache->has($cacheKey)) {
            return @unserialize(@base64_decode($this->cache->get($cacheKey))) ?: [];
        }

        $data = $this->requestServerData($type.'/popular');
        $this->cache->put($cacheKey, base64_encode(serialize($data)), 60);

        foreach ($data as $product) {
            $code = array_get($product, 'code', -1);
            $this->cacheProductDetail($type, $code, $product);
        }

        $this->saveProductDetailCache();

        return $data;
    }

    /**
     * Load product detail cache
     *
     * @return void
     */
    protected function loadProductDetailCache()
    {
        $defaultCache = ['theme' => [], 'plugin' => []];
        $cacheKey = 'system-updates-product-details';

        if ($this->cache->has($cacheKey)) {
            $this->productCache = @unserialize(@base64_decode($this->cache->get($cacheKey))) ?: $defaultCache;
        }
        else {
            $this->productCache = $defaultCache;
        }
    }

    /**
     * Save product detail cache
     *
     * @return void
     */
    protected function saveProductDetailCache()
    {
        if ($this->productCache === null) {
            $this->loadProductDetailCache();
        }

        $cacheKey = 'system-updates-product-details';
        $expiresAt = Carbon::now()->addDays(2);
        $this->cache->put($cacheKey, base64_encode(serialize($this->productCache)), $expiresAt);
    }

    /**
     * Cache product detail
     *
     * @param $type
     * @param $code
     * @param $data
     * @return void
     */
    protected function cacheProductDetail($type, $code, $data)
    {
        if ($this->productCache === null) {
            $this->loadProductDetailCache();
        }

        $this->productCache[$type][$code] = $data;
    }

    //
    // Changelog
    //

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function requestChangelog()
    {
        $result = Http::get('https://octobercms.com/changelog?json');

        if ($result->code == 404) {
            throw new ApplicationException($this->translator->get('system::lang.server.response_empty'));
        }

        if ($result->code != 200) {
            throw new ApplicationException(
                strlen($result->body)
                ? $result->body
                : $this->translator->get('system::lang.server.response_empty')
            );
        }

        try {
            $resultData = json_decode($result->body, true);
        }
        catch (Exception $ex) {
            throw new ApplicationException($this->translator->get('system::lang.server.response_invalid'));
        }

        return $resultData;
    }

    //
    // Notes
    //

    /**
     * Raise a note event for the migrator.
     *
     * @param  string  $message
     * @return self
     */
    protected function note($message): UpdateManagerContract
    {
        if ($this->notesOutput !== null) {
            $this->notesOutput->writeln($message);
        }
        else {
            $this->notes[] = $message;
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    /**
     * {@inheritDoc}
     */
    public function resetNotes(): UpdateManagerContract
    {
        $this->notesOutput = null;

        $this->notes = [];

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setNotesOutput($output): UpdateManagerContract
    {
        $this->notesOutput = $output;

        return $this;
    }

    //
    // Gateway access
    //

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function requestServerData($uri, $postData = []): array
    {
        $result = Http::post($this->createServerUrl($uri), function ($http) use ($postData) {
            $this->applyHttpAttributes($http, $postData);
        });

        if ((int) $result->code === 404) {
            throw new ApplicationException($this->translator->get('system::lang.server.response_not_found'));
        }

        if ((int) $result->code !== 200) {
            throw new ApplicationException(
                strlen($result->body)
                ? $result->body
                : $this->translator->get('system::lang.server.response_empty')
            );
        }

        $resultData = false;

        try {
            $resultData = @json_decode($result->body, true);
        }
        catch (Exception $ex) {
            throw new ApplicationException($this->translator->get('system::lang.server.response_invalid'));
        }

        if ($resultData === false || (is_string($resultData) && $resultData === '')) {
            throw new ApplicationException($this->translator->get('system::lang.server.response_invalid'));
        }

        return $resultData;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     * @throws FileNotFoundException
     */
    public function requestServerFile($uri, $fileCode, $expectedHash, $postData = [])
    {
        $filePath = $this->getFilePath($fileCode);

        $result = Http::post($this->createServerUrl($uri), function ($http) use ($postData, $filePath) {
            $this->applyHttpAttributes($http, $postData);
            $http->toFile($filePath);
        });

        if ((int) $result->code !== 200) {
            throw new ApplicationException($this->filesystem->get($filePath));
        }

        if (md5_file($filePath) !== $expectedHash) {
            @unlink($filePath);
            throw new ApplicationException($this->translator->get('system::lang.server.file_corrupt'));
        }
    }

    /**
     * Calculates a file path for a file code
     *
     * @param  string $fileCode A unique file code
     * @return string           Full path on the disk
     */
    protected function getFilePath($fileCode): string
    {
        $name = md5($fileCode) . '.arc';
        return $this->tempDirectory . '/' . $name;
    }

    /**
     * {@inheritDoc}
     */
    public function setSecurity($key, $secret)
    {
        $this->key = $key;
        $this->secret = $secret;
    }

    /**
     * Create a complete gateway server URL from supplied URI
     *
     * @param  string $uri URI
     * @return string      URL
     */
    protected function createServerUrl($uri): string
    {
        $gateway = $this->config->get('cms.updateServer', 'http://gateway.octobercms.com/api');
        if (substr($gateway, -1) !== '/') {
            $gateway .= '/';
        }

        return $gateway . $uri;
    }

    /**
     * Modifies the Network HTTP object with common attributes.
     *
     * @param  Http $http      Network object
     * @param  array $postData Post data
     * @return void
     */
    protected function applyHttpAttributes($http, $postData)
    {
        $postData['protocol_version'] = '1.1';
        $postData['client'] = 'october';

        $postData['server'] = base64_encode(serialize([
            'php'   => PHP_VERSION,
            'url'   => $this->urlGenerator->to('/'),
            'since' => PluginVersion::orderBy('created_at')->value('created_at')
        ]));

        if ($projectId = Parameter::get('system::project.id')) {
            $postData['project'] = $projectId;
        }

        if ($this->config->get('cms.edgeUpdates', false)) {
            $postData['edge'] = 1;
        }

        if ($this->key && $this->secret) {
            $postData['nonce'] = $this->createNonce();
            $http->header('Rest-Key', $this->key);
            $http->header('Rest-Sign', $this->createSignature($postData, $this->secret));
        }

        if ($credentials = $this->config->get('cms.updateAuth')) {
            $http->auth($credentials);
        }

        $http->noRedirect();
        $http->data($postData);
    }

    /**
     * Create a nonce based on millisecond time
     *
     * @return int
     */
    protected function createNonce(): int
    {
        $mt = explode(' ', microtime());
        return $mt[1] . substr($mt[0], 2, 6);
    }

    /**
     * Create a unique signature for transmission.
     *
     * @param $data
     * @param $secret
     * @return string
     */
    protected function createSignature($data, $secret): string
    {
        return base64_encode(hash_hmac('sha512', http_build_query($data, '', '&'), base64_decode($secret), true));
    }

    /**
     * {@inheritDoc}
     */
    public function getMigrationTableName(): string
    {
        return $this->config->get('database.migrations', 'migrations');
    }
}
