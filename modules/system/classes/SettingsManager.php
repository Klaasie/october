<?php namespace System\Classes;

use Backend\Classes\AuthManager;
use Backend\Helpers\Backend;
use Backend\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use System\Classes\Contracts\PluginManagerContract;
use System\Classes\Contracts\SettingsManagerContract;
use SystemException;

/**
 * Manages the system settings.
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class SettingsManager implements SettingsManagerContract
{
    /**
     * Allocated category types
     */
    const CATEGORY_CMS = 'system::lang.system.categories.cms';
    const CATEGORY_MISC = 'system::lang.system.categories.misc';
    const CATEGORY_MAIL = 'system::lang.system.categories.mail';
    const CATEGORY_LOGS = 'system::lang.system.categories.logs';
    const CATEGORY_SHOP = 'system::lang.system.categories.shop';
    const CATEGORY_TEAM = 'system::lang.system.categories.team';
    const CATEGORY_USERS = 'system::lang.system.categories.users';
    const CATEGORY_SOCIAL = 'system::lang.system.categories.social';
    const CATEGORY_SYSTEM = 'system::lang.system.categories.system';
    const CATEGORY_EVENTS = 'system::lang.system.categories.events';
    const CATEGORY_BACKEND = 'system::lang.system.categories.backend';
    const CATEGORY_CUSTOMERS = 'system::lang.system.categories.customers';
    const CATEGORY_MYSETTINGS = 'system::lang.system.categories.my_settings';
    const CATEGORY_NOTIFICATIONS = 'system::lang.system.categories.notifications';

    /**
     * @var array Cache of registration callbacks.
     */
    protected $callbacks = [];

    /**
     * @var array List of registered items.
     */
    protected $items;

    /**
     * @var array Grouped collection of all items, by category.
     */
    protected $groupedItems;

    /**
     * @var string Active plugin or module owner.
     */
    protected $contextOwner;

    /**
     * @var string Active item code.
     */
    protected $contextItemCode;

    /**
     * @var array Settings item defaults.
     */
    protected static $itemDefaults = [
        'code'        => null,
        'label'       => null,
        'category'    => null,
        'icon'        => null,
        'url'         => null,
        'permissions' => [],
        'order'       => 500,
        'context'     => 'system',
        'keywords'    => null
    ];

    /**
     * @var PluginManagerContract
     */
    protected $pluginManager;

    /**
     * @var Dispatcher
     */
    private $events;

    /**
     * @var Backend
     */
    private $backend;

    /**
     * @var AuthManager
     */
    private $backendAuth;

    /**
     * SettingsManager constructor.
     *
     * @param PluginManagerContract $pluginManager
     */
    public function __construct(PluginManagerContract $pluginManager)
    {
        $this->pluginManager = $pluginManager;
        $this->events = resolve('events');
        $this->backend = resolve('backend.helper');
        $this->backendAuth = resolve('backend.auth');
    }

    /**
     * Return itself
     *
     * Kept this one to remain backwards compatible.
     *
     * @return self
     * @deprecated V1.0.xxx Instead of using this method,
     *                      rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): SettingsManagerContract
    {
        return resolve(SettingsManagerContract::class);
    }

    /**
     * Load Items
     */
    protected function loadItems()
    {
        /*
         * Load module items
         */
        foreach ($this->callbacks as $callback) {
            $callback($this);
        }

        /*
         * Load plugin items
         */
        $plugins = $this->pluginManager->getPlugins();

        foreach ($plugins as $id => $plugin) {
            $items = $plugin->registerSettings();
            if (!is_array($items)) {
                continue;
            }

            $this->registerSettingItems($id, $items);
        }

        /*
         * Extensibility
         */
        $this->events->fire('system.settings.extendItems', [$this]);

        /*
         * Sort settings items
         */
        usort($this->items, static function ($a, $b) {
            return $a->order - $b->order;
        });

        /*
         * Filter items user lacks permission for
         */
        /** @var User $user */
        $user = $this->backendAuth->getUser();
        $this->items = $this->filterItemPermissions($user, $this->items);

        /*
         * Process each item in to a category array
         */
        $catItems = [];
        foreach ($this->items as $code => $item) {
            $category = $item->category ?: self::CATEGORY_MISC;
            if (!isset($catItems[$category])) {
                $catItems[$category] = [];
            }

            $catItems[$category][$code] = $item;
        }

        $this->groupedItems = $catItems;
    }

    /**
     * {@inheritDoc}
     */
    public function listItems($context = null): array
    {
        if ($this->items === null || $this->groupedItems === null) {
            $this->loadItems();
        }

        if ($context !== null) {
            return $this->filterByContext($this->groupedItems, $context);
        }

        return $this->groupedItems;
    }

    /**
     * Filters a set of items by a given context.
     *
     * @param  array $items
     * @param  string $context
     * @return array
     */
    protected function filterByContext($items, $context): array
    {
        $filteredItems = [];
        foreach ($items as $categoryName => $category) {
            $filteredCategory = [];
            foreach ($category as $item) {
                $itemContext = is_array($item->context) ? $item->context : [$item->context];
                if (in_array($context, $itemContext)) {
                    $filteredCategory[] = $item;
                }
            }

            if (count($filteredCategory)) {
                $filteredItems[$categoryName] = $filteredCategory;
            }
        }

        return $filteredItems;
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
    public function registerSettingItems($owner, array $definitions)
    {
        if (!$this->items) {
            $this->items = [];
        }

        $this->addSettingItems($owner, $definitions);
    }

    /**
     * {@inheritDoc}
     */
    public function addSettingItems($owner, array $definitions)
    {
        foreach ($definitions as $code => $definition) {
            $this->addSettingItem($owner, $code, $definition);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addSettingItem($owner, $code, array $definition)
    {
        $itemKey = $this->makeItemKey($owner, $code);

        if (isset($this->items[$itemKey])) {
            $definition = array_merge((array) $this->items[$itemKey], $definition);
        }

        $item = array_merge(self::$itemDefaults, array_merge($definition, [
            'code' => $code,
            'owner' => $owner
        ]));

        /*
         * Link to the generic settings page if a URL is not provided
         */
        if (isset($item['class']) && !isset($item['url'])) {
            $uri = [];

            if (strpos($owner, '.') !== null) {
                list($author, $plugin) = explode('.', $owner);
                $uri[] = strtolower($author);
                $uri[] = strtolower($plugin);
            }
            else {
                $uri[] = strtolower($owner);
            }

            $uri[] = strtolower($code);
            $uri =  implode('/', $uri);
            $item['url'] = $this->backend->url('system/settings/update/' . $uri);
        }

        $this->items[$itemKey] = (object) $item;
    }

    /**
     * {@inheritDoc}
     * @throws SystemException
     */
    public function removeSettingItem($owner, $code)
    {
        if (!$this->items) {
            throw new SystemException('Unable to remove settings item before items are loaded.');
        }

        $itemKey = $this->makeItemKey($owner, $code);
        unset($this->items[$itemKey]);

        if ($this->groupedItems) {
            foreach ($this->groupedItems as $category => $items) {
                if (isset($items[$itemKey])) {
                    unset($this->groupedItems[$category][$itemKey]);
                }
            }
        }
    }

    /**
     * Sets the navigation context.
     * @param string $owner Specifies the setting items owner plugin or module in the format Vendor.Module.
     * @param string $code Specifies the settings item code.
     */
    public static function setContext($owner, $code)
    {
        /** @var self $instance */
        $instance = resolve(self::class);

        $instance->setContextOwner(strtolower($owner));
        $instance->setContextItemCode(strtolower($code));
    }

    /**
     * {@inheritDoc}
     */
    public function setContextOwner(string $contextOwner)
    {
        $this->contextOwner = $contextOwner;
    }

    /**
     * {@inheritDoc}
     */
    public function setContextItemCode(string $contextItemCode)
    {
        $this->contextItemCode = $contextItemCode;
    }

    /**
     * {@inheritDoc}
     */
    public function getContext()
    {
        return (object) [
            'itemCode' => $this->contextItemCode,
            'owner' => $this->contextOwner
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function findSettingItem($owner, $code)
    {
        if ($this->items === null) {
            $this->loadItems();
        }

        $owner = strtolower($owner);
        $code = strtolower($code);

        foreach ($this->items as $item) {
            if (strtolower($item->owner) === $owner && strtolower($item->code) === $code) {
                return $item;
            }
        }

        return false;
    }

    /**
     * Removes settings items from an array if the supplied user lacks permission.
     *
     * @param User $user A user object
     * @param array $items A collection of setting items
     * @return array The filtered settings items
     */
    protected function filterItemPermissions($user, array $items): array
    {
        if (!$user) {
            return $items;
        }

        $items = array_filter($items, static function ($item) use ($user) {
            if (!$item->permissions || !count($item->permissions)) {
                return true;
            }

            return $user->hasAnyAccess($item->permissions);
        });

        return $items;
    }

    /**
     * Internal method to make a unique key for an item.
     *
     * @param $owner
     * @param $code
     * @return string
     */
    protected function makeItemKey($owner, $code): string
    {
        return strtoupper($owner).'.'.strtoupper($code);
    }
}
