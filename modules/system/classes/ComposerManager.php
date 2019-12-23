<?php namespace System\Classes;

use Composer\Autoload\ClassLoader;
use System\Classes\Contracts\ComposerManagerContract;

/**
 * Composer manager
 *
 * This class manages composer packages introduced by plugins. Each loaded
 * package is added to a global pool to ensure a package is not loaded
 * twice by the composer instance introduced by a plugin. This class
 * is used as a substitute for the vendor/autoload.php file.
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class ComposerManager implements ComposerManagerContract
{
    /**
     * @var array
     */
    protected $namespacePool = [];

    /**
     * @var array
     */
    protected $psr4Pool = [];

    /**
     * @var array
     */
    protected $classMapPool = [];

    /**
     * @var array
     */
    protected $includeFilesPool = [];

    /**
     * @var ClassLoader The primary composer instance.
     */
    protected $loader;

    /**
     * ComposerManager constructor.
     */
    public function __construct()
    {
        $this->loader = include base_path() .'/vendor/autoload.php';
        $this->preloadPools();
    }

    /**
     * Returns itself
     *
     * Kept this one to remain backwards compatible.
     *
     * @return self
     * @deprecated V1.0.xxx Instead of using this method,
     *                      rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): ComposerManagerContract
    {
        return resolve(self::class);
    }

    /**
     * Preload Pools
     *
     * @return void
     */
    protected function preloadPools()
    {
        $this->classMapPool = array_fill_keys(array_keys($this->loader->getClassMap()), true);
        $this->namespacePool = array_fill_keys(array_keys($this->loader->getPrefixes()), true);
        $this->psr4Pool = array_fill_keys(array_keys($this->loader->getPrefixesPsr4()), true);
        $this->includeFilesPool = $this->preloadIncludeFilesPool();
    }

    /**
     * Preload include files pool
     *
     * @return array
     */
    protected function preloadIncludeFilesPool(): array
    {
        $result = [];
        $vendorPath = base_path() .'/vendor';

        if (file_exists($file = $vendorPath . '/composer/autoload_files.php')) {
            $includeFiles = require $file;
            foreach ($includeFiles as $includeFile) {
                $relativeFile = $this->stripVendorDir($includeFile, $vendorPath);
                $result[$relativeFile] = true;
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function autoload($vendorPath)
    {
        $dir = $vendorPath . '/composer';

        if (file_exists($file = $dir . '/autoload_namespaces.php')) {
            $map = require $file;
            foreach ($map as $namespace => $path) {
                if (isset($this->namespacePool[$namespace])) {
                    continue;
                }
                $this->loader->set($namespace, $path);
                $this->namespacePool[$namespace] = true;
            }
        }

        if (file_exists($file = $dir . '/autoload_psr4.php')) {
            $map = require $file;
            foreach ($map as $namespace => $path) {
                if (isset($this->psr4Pool[$namespace])) {
                    continue;
                }
                $this->loader->setPsr4($namespace, $path);
                $this->psr4Pool[$namespace] = true;
            }
        }

        if (file_exists($file = $dir . '/autoload_classmap.php')) {
            $classMap = require $file;
            if ($classMap) {
                $classMapDiff = array_diff_key($classMap, $this->classMapPool);
                $this->loader->addClassMap($classMapDiff);
                $this->classMapPool += array_fill_keys(array_keys($classMapDiff), true);
            }
        }

        if (file_exists($file = $dir . '/autoload_files.php')) {
            $includeFiles = require $file;
            foreach ($includeFiles as $includeFile) {
                $relativeFile = $this->stripVendorDir($includeFile, $vendorPath);
                if (isset($this->includeFilesPool[$relativeFile])) {
                    continue;
                }
                require $includeFile;
                $this->includeFilesPool[$relativeFile] = true;
            }
        }
    }

    /**
     * Removes the vendor directory from a path.
     *
     * @param string $path
     * @param $vendorDir
     * @return string
     */
    protected function stripVendorDir($path, $vendorDir): string
    {
        $path = realpath($path);
        $vendorDir = realpath($vendorDir);

        if (strpos($path, $vendorDir) === 0) {
            $path = substr($path, strlen($vendorDir));
        }

        return $path;
    }
}
