<?php namespace System\Classes;

use Closure;
use Str;
use System\Classes\Contracts\MarkupManagerContract;
use System\Classes\Contracts\PluginManagerContract;
use Twig\TokenParser\AbstractTokenParser as TwigTokenParser;
use Twig\TwigFilter as TwigSimpleFilter;
use Twig\TwigFunction as TwigSimpleFunction;
use ApplicationException;

/**
 * This class manages Twig functions, token parsers and filters.
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class MarkupManager implements MarkupManagerContract
{
    const EXTENSION_FILTER = 'filters';
    const EXTENSION_FUNCTION = 'functions';
    const EXTENSION_TOKEN_PARSER = 'tokens';

    /**
     * @var array Cache of registration callbacks.
     */
    protected $callbacks = [];

    /**
     * @var array Globally registered extension items
     */
    protected $items;

    /**
     * @var PluginManagerContract
     */
    protected $pluginManager;

    /**
     * @var array Transaction based extension items
     */
    protected $transactionItems;

    /**
     * @var bool Manager is in transaction mode
     */
    protected $transactionMode = false;

    /**
     * MarkupManager constructor.
     *
     * @param PluginManagerContract $pluginManager
     */
    public function __construct(PluginManagerContract $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    /**
     * Return itself.
     *
     * Kept this one to remain backwards compatible.
     *
     * @return self
     * @deprecated V1.0.xxx Instead of using this method,
     *                      rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): MarkupManagerContract
    {
        return resolve(self::class);
    }

    /**
     * Load extensions
     */
    protected function loadExtensions()
    {
        /*
         * Load module items
         */
        foreach ($this->callbacks as $callback) {
            $callback($this);
        }

        /*
         * Load plugin itemsOe
         */
        $plugins = $this->pluginManager->getPlugins();

        foreach ($plugins as $id => $plugin) {
            $items = $plugin->registerMarkupTags();
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $type => $definitions) {
                if (!is_array($definitions)) {
                    continue;
                }

                $this->registerExtensions($type, $definitions);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function registerCallback(callable $callback)
    {
        $this->callbacks[] = $callback;
    }

    /**
     * {@inheritDoc}
     */
    public function registerExtensions($type, array $definitions)
    {
        $items = $this->transactionMode ? 'transactionItems' : 'items';

        if ($this->$items === null) {
            $this->$items = [];
        }

        if (!array_key_exists($type, $this->$items)) {
            $this->$items[$type] = [];
        }

        foreach ($definitions as $name => $definition) {
            switch ($type) {
                case self::EXTENSION_TOKEN_PARSER:
                    $this->$items[$type][] = $definition;
                    break;
                case self::EXTENSION_FILTER:
                case self::EXTENSION_FUNCTION:
                    $this->$items[$type][$name] = $definition;
                    break;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function registerFilters(array $definitions)
    {
        $this->registerExtensions(self::EXTENSION_FILTER, $definitions);
    }

    /**
     * {@inheritDoc}
     */
    public function registerFunctions(array $definitions)
    {
        $this->registerExtensions(self::EXTENSION_FUNCTION, $definitions);
    }

    /**
     * {@inheritDoc}
     */
    public function registerTokenParsers(array $definitions)
    {
        $this->registerExtensions(self::EXTENSION_TOKEN_PARSER, $definitions);
    }

    /**
     * {@inheritDoc}
     */
    public function listExtensions($type): array
    {
        $results = [];

        if ($this->items === null) {
            $this->loadExtensions();
        }

        if (isset($this->items[$type]) && is_array($this->items[$type])) {
            $results = $this->items[$type];
        }

        if ($this->transactionItems !== null && isset($this->transactionItems[$type])) {
            $results = array_merge($results, $this->transactionItems[$type]);
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function listFilters(): array
    {
        return $this->listExtensions(self::EXTENSION_FILTER);
    }

    /**
     * {@inheritDoc}
     */
    public function listFunctions(): array
    {
        return $this->listExtensions(self::EXTENSION_FUNCTION);
    }

    /**
     * {@inheritDoc}
     */
    public function listTokenParsers(): array
    {
        return $this->listExtensions(self::EXTENSION_TOKEN_PARSER);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function makeTwigFunctions($functions = []): array
    {
        if (!is_array($functions)) {
            $functions = [];
        }

        foreach ($this->listFunctions() as $name => $callable) {
            /*
             * Handle a wildcard function
             */
            if (strpos($name, '*') !== false && $this->isWildCallable($callable)) {
                $callable = function ($name) use ($callable) {
                    $arguments = array_slice(func_get_args(), 1);
                    $method = $this->isWildCallable($callable, Str::camel($name));
                    return call_user_func_array($method, $arguments);
                };
            }

            if (!is_callable($callable)) {
                throw new ApplicationException(sprintf('The markup function for %s is not callable.', $name));
            }

            $functions[] = new TwigSimpleFunction($name, $callable, ['is_safe' => ['html']]);
        }

        return $functions;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function makeTwigFilters($filters = []): array
    {
        if (!is_array($filters)) {
            $filters = [];
        }

        foreach ($this->listFilters() as $name => $callable) {
            /*
             * Handle a wildcard function
             */
            if (strpos($name, '*') !== false && $this->isWildCallable($callable)) {
                $callable = function ($name) use ($callable) {
                    $arguments = array_slice(func_get_args(), 1);
                    $method = $this->isWildCallable($callable, Str::camel($name));
                    return call_user_func_array($method, $arguments);
                };
            }

            if (!is_callable($callable)) {
                throw new ApplicationException(sprintf('The markup filter for %s is not callable.', $name));
            }

            $filters[] = new TwigSimpleFilter($name, $callable, ['is_safe' => ['html']]);
        }

        return $filters;
    }

    /**
     * {@inheritDoc}
     */
    public function makeTwigTokenParsers($parsers = []): array
    {
        if (!is_array($parsers)) {
            $parsers = [];
        }

        $extraParsers = $this->listTokenParsers();
        foreach ($extraParsers as $obj) {
            if (!$obj instanceof TwigTokenParser) {
                continue;
            }

            $parsers[] = $obj;
        }

        return $parsers;
    }

    /**
     * Tests if a callable type contains a wildcard, also acts as an utility to replace the wildcard with a string.
     *
     * @param callable|string|array  $callable
     * @param  string|bool $replaceWith
     * @return string|array|bool
     */
    protected function isWildCallable($callable, $replaceWith = false)
    {
        $isWild = false;

        if (is_string($callable) && strpos($callable, '*') !== false) {
            $isWild = $replaceWith ? str_replace('*', $replaceWith, $callable) : true;
        }

        if (is_array($callable)) {
            if (is_string($callable[0]) && strpos($callable[0], '*') !== false) {
                if ($replaceWith) {
                    $isWild = $callable;
                    $isWild[0] = str_replace('*', $replaceWith, $callable[0]);
                }
                else {
                    $isWild = true;
                }
            }

            if (!empty($callable[1]) && strpos($callable[1], '*') !== false) {
                if ($replaceWith) {
                    $isWild = $isWild ?: $callable;
                    $isWild[1] = str_replace('*', $replaceWith, $callable[1]);
                }
                else {
                    $isWild = true;
                }
            }
        }

        return $isWild;
    }

    //
    // Transactions
    //

    /**
     * {@inheritDoc}
     */
    public function transaction(Closure $callback)
    {
        $this->beginTransaction();
        $callback($this);
        $this->endTransaction();
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction()
    {
        $this->transactionMode = true;
    }

    /**
     * {@inheritDoc}
     */
    public function endTransaction()
    {
        $this->transactionMode = false;

        $this->transactionItems = null;
    }
}
