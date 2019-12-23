<?php namespace Backend\Classes;

use Backend\Classes\Contracts\NavigationManagerContract;
use Backend\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Logging\Log;
use Illuminate\Contracts\Validation\Factory;
use October\Rain\Exception\SystemException;
use System\Classes\Contracts\PluginManagerContract;

/**
 * Manages the backend navigation.
 *
 * @package october\backend
 * @author Alexey Bobkov, Samuel Georges
 */
class NavigationManager implements NavigationManagerContract
{
    /**
     * @var array Cache of registration callbacks.
     */
    protected $callbacks = [];

    /**
     * @var array List of registered items.
     */
    protected $items;

    protected $contextSidenavPartials = [];

    protected $contextOwner;
    protected $contextMainMenuItemCode;
    protected $contextSideMenuItemCode;

    protected static $mainItemDefaults = [
        'code'        => null,
        'label'       => null,
        'icon'        => null,
        'iconSvg'     => null,
        'counter'     => null,
        'counterLabel'=> null,
        'url'         => null,
        'permissions' => [],
        'order'       => 500,
        'sideMenu'    => []
    ];

    protected static $sideItemDefaults = [
        'code'        => null,
        'label'       => null,
        'icon'        => null,
        'url'         => null,
        'iconSvg'     => null,
        'counter'     => null,
        'counterLabel'=> null,
        'order'       => -1,
        'attributes'  => [],
        'permissions' => []
    ];

    /**
     * @var PluginManagerContract
     */
    protected $pluginManager;

    /**
     * @var Dispatcher
     */
    private $dispatcher;

    /**
     * @var Repository
     */
    private $config;

    /**
     * @var AuthManager
     */
    private $authManager;

    /**
     * @var Log
     */
    private $log;

    /**
     * @param PluginManagerContract $pluginManager
     * @param Log $log
     * @param Repository $config
     * @throws SystemException
     */
    public function __construct(PluginManagerContract $pluginManager, Log $log, Repository $config)
    {
        $this->pluginManager = $pluginManager;
        $this->dispatcher = resolve('events');
        $this->authManager = resolve('backend.auth');
        $this->log = $log;
        $this->config = $config;
    }

    /**
     * Static instance method
     * Kept this one to remain backwards compatible.
     *
     * @deprecated V1.0.xxx Instead of using this method,
     *             rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): NavigationManagerContract
    {
        return resolve(self::class);
    }

    /**
     * Loads the menu items from modules and plugins
     * @return void
     * @throws SystemException
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
            $items = $plugin->registerNavigation();
            if (!is_array($items)) {
                continue;
            }

            $this->registerMenuItems($id, $items);
        }

        /**
         * @event backend.menu.extendItems
         * Provides an opportunity to manipulate the backend navigation
         *
         * Example usage:
         *
         *     Event::listen('backend.menu.extendItems', function ((\Backend\Classes\NavigationManager) $navigationManager) {
         *         $navigationManager->addMainMenuItems(...)
         *         $navigationManager->addSideMenuItems(...)
         *         $navigationManager->removeMainMenuItem(...)
         *     });
         *
         */
        $this->dispatcher->fire('backend.menu.extendItems', [$this]);

        /*
         * Sort menu items
         */
        uasort($this->items, static function ($a, $b) {
            return $a->order - $b->order;
        });

        /*
         * Filter items user lacks permission for
         */
        $user = $this->authManager->getUser();
        $this->items = $this->filterItemPermissions($user, $this->items);

        foreach ($this->items as $item) {
            if (!$item->sideMenu || !count($item->sideMenu)) {
                continue;
            }

            /*
             * Apply incremental default orders
             */
            $orderCount = 0;
            foreach ($item->sideMenu as $sideMenuItem) {
                if ($sideMenuItem->order !== -1) {
                    continue;
                }
                $sideMenuItem->order = ($orderCount += 100);
            }

            /*
             * Sort side menu items
             */
            uasort($item->sideMenu, static function ($a, $b) {
                return $a->order - $b->order;
            });

            /*
             * Filter items user lacks permission for
             */
            $item->sideMenu = $this->filterItemPermissions($user, $item->sideMenu);
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
    public function registerMenuItems($owner, array $definitions)
    {
        if (!$this->items) {
            $this->items = [];
        }

        /** @var Factory $validation */
        $validation = resolve(Factory::class);
        $validator = $validation->make($definitions, [
            '*.label' => 'required',
            '*.icon' => 'required_without:*.iconSvg',
            '*.url' => 'required',
            '*.sideMenu.*.label' => 'nullable|required',
            '*.sideMenu.*.icon' => 'nullable|required_without:*.sideMenu.*.iconSvg',
            '*.sideMenu.*.url' => 'nullable|required',
        ]);

        if ($validator->fails()) {
            $errorMessage = 'Invalid menu item detected in ' . $owner . '. Contact the plugin author to fix (' . $validator->errors()->first() . ')';
            if ($this->config->get('app.debug', false)) {
                throw new SystemException($errorMessage);
            }

            $this->log->error($errorMessage);
        }

        $this->addMainMenuItems($owner, $definitions);
    }

    /**
     * {@inheritDoc}
     */
    public function addMainMenuItems($owner, array $definitions)
    {
        foreach ($definitions as $code => $definition) {
            $this->addMainMenuItem($owner, $code, $definition);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addMainMenuItem($owner, $code, array $definition)
    {
        $itemKey = $this->makeItemKey($owner, $code);

        if (isset($this->items[$itemKey])) {
            $definition = array_merge((array) $this->items[$itemKey], $definition);
        }

        $item = (object) array_merge(self::$mainItemDefaults, array_merge($definition, [
            'code'  => $code,
            'owner' => $owner
        ]));

        $this->items[$itemKey] = $item;

        if ($item->sideMenu) {
            $this->addSideMenuItems($owner, $code, $item->sideMenu);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function removeMainMenuItem($owner, $code)
    {
        $itemKey = $this->makeItemKey($owner, $code);
        unset($this->items[$itemKey]);
    }

    /**
     * {@inheritDoc}
     */
    public function addSideMenuItems($owner, $code, array $definitions)
    {
        foreach ($definitions as $sideCode => $definition) {
            $this->addSideMenuItem($owner, $code, $sideCode, (array) $definition);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addSideMenuItem($owner, $code, $sideCode, array $definition): bool
    {
        $itemKey = $this->makeItemKey($owner, $code);

        if (!isset($this->items[$itemKey])) {
            return false;
        }

        $mainItem = $this->items[$itemKey];

        $definition = array_merge($definition, [
            'code'  => $sideCode,
            'owner' => $owner
        ]);

        if (isset($mainItem->sideMenu[$sideCode])) {
            $definition = array_merge((array) $mainItem->sideMenu[$sideCode], $definition);
        }

        $item = (object) array_merge(self::$sideItemDefaults, $definition);

        $this->items[$itemKey]->sideMenu[$sideCode] = $item;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function removeSideMenuItem($owner, $code, $sideCode): bool
    {
        $itemKey = $this->makeItemKey($owner, $code);
        if (!isset($this->items[$itemKey])) {
            return false;
        }

        $mainItem = $this->items[$itemKey];
        unset($mainItem->sideMenu[$sideCode]);

        return true;
    }

    /**
     * {@inheritDoc}
     * @throws SystemException
     */
    public function listMainMenuItems(): array
    {
        if ($this->items === null) {
            $this->loadItems();
        }

        foreach ($this->items as $item) {
            if ($item->counter === false) {
                continue;
            }

            if ($item->counter !== null && is_callable($item->counter)) {
                $item->counter = call_user_func($item->counter, $item);
            } elseif (!empty((int) $item->counter)) {
                $item->counter = (int) $item->counter;
            } elseif (!empty($sideItems = $this->listSideMenuItems($item->owner, $item->code))) {
                $item->counter = 0;
                foreach ($sideItems as $sideItem) {
                    $item->counter += $sideItem->counter;
                }
            }

            if (empty($item->counter)) {
                $item->counter = null;
            }
        }

        return $this->items;
    }

    /**
     * {@inheritDoc}
     */
    public function listSideMenuItems($owner = null, $code = null): array
    {
        $activeItem = null;

        if ($owner !== null && $code !== null) {
            $activeItem = @$this->items[$this->makeItemKey($owner, $code)];
        } else {
            foreach ($this->listMainMenuItems() as $item) {
                if ($this->isMainMenuItemActive($item)) {
                    $activeItem = $item;
                    break;
                }
            }
        }

        if (!$activeItem) {
            return [];
        }

        $items = $activeItem->sideMenu;

        foreach ($items as $item) {
            if ($item->counter !== null && is_callable($item->counter)) {
                $item->counter = call_user_func($item->counter, $item);
                if (empty($item->counter)) {
                    $item->counter = null;
                }
            }
        }

        return $items;
    }

    /**
     * {@inheritDoc}
     */
    public function setContext($owner, $mainMenuItemCode, $sideMenuItemCode = null)
    {
        $this->setContextOwner($owner);
        $this->setContextMainMenu($mainMenuItemCode);
        $this->setContextSideMenu($sideMenuItemCode);
    }

    /**
     * {@inheritDoc}
     */
    public function setContextOwner($owner)
    {
        $this->contextOwner = $owner;
    }

    /**
     * {@inheritDoc}
     */
    public function setContextMainMenu($mainMenuItemCode)
    {
        $this->contextMainMenuItemCode = $mainMenuItemCode;
    }

    /**
     * {@inheritDoc}
     */
    public function getContext()
    {
        return (object)[
            'mainMenuCode' => $this->contextMainMenuItemCode,
            'sideMenuCode' => $this->contextSideMenuItemCode,
            'owner' => $this->contextOwner
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function setContextSideMenu($sideMenuItemCode)
    {
        $this->contextSideMenuItemCode = $sideMenuItemCode;
    }

    /**
     * {@inheritDoc}
     */
    public function isMainMenuItemActive($item): bool
    {
        return $this->contextOwner == $item->owner && $this->contextMainMenuItemCode == $item->code;
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveMainMenuItem()
    {
        foreach ($this->listMainMenuItems() as $item) {
            if ($this->isMainMenuItemActive($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function isSideMenuItemActive($item): bool
    {
        if ($this->contextSideMenuItemCode === true) {
            $this->contextSideMenuItemCode = null;
            return true;
        }

        return $this->contextOwner == $item->owner && $this->contextSideMenuItemCode == $item->code;
    }

    /**
     * {@inheritDoc}
     */
    public function registerContextSidenavPartial($owner, $mainMenuItemCode, $partial)
    {
        $this->contextSidenavPartials[$owner.$mainMenuItemCode] = $partial;
    }

    /**
     * {@inheritDoc}
     */
    public function getContextSidenavPartial($owner, $mainMenuItemCode)
    {
        $key = $owner.$mainMenuItemCode;

        return $this->contextSidenavPartials[$key] ?? null;
    }

    /**
     * Removes menu items from an array if the supplied user lacks permission.
     *
     * @param User $user A user object
     * @param array $items A collection of menu items
     * @return array The filtered menu items
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
