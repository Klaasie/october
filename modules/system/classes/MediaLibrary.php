<?php namespace System\Classes;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Translation\Translator;
use Str;
use System\Classes\Contracts\MediaLibraryContract;
use October\Rain\Filesystem\Definitions as FileDefinitions;
use ApplicationException;
use SystemException;

/**
 * Provides abstraction level for the Media Library operations.
 * Implements the library caching features and security checks.
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class MediaLibrary implements MediaLibraryContract
{
    const SORT_BY_TITLE = 'title';
    const SORT_BY_SIZE = 'size';
    const SORT_BY_MODIFIED = 'modified';
    const SORT_DIRECTION_ASC = 'asc';
    const SORT_DIRECTION_DESC = 'desc';

    /**
     * @var Translator
     */
    private $translator;

    /**
     * @var Repository
     */
    private $config;

    /**
     * @var CacheRepository
     */
    private $cache;

    /**
     * @var UrlGenerator
     */
    private $urlGenerator;

    /**
     * @var Factory
     */
    private $storage;

    /**
     * @var string Cache key
     */
    protected $cacheKey = 'system-media-library-contents';

    /**
     * @var string Relative or absolute URL of the Library root folder.
     */
    protected $storagePath;

    /**
     * @var string The root Library folder path.
     */
    protected $storageFolder;

    /**
     * @var mixed A reference to the Media Library disk.
     */
    protected $storageDisk;

    /**
     * @var array Contains a list of files and directories to ignore.
     * The list can be customized with cms.storage.media.ignore configuration option.
     */
    protected $ignoreNames;

    /**
     * @var array Contains a list of regex patterns to ignore in files and directories.
     * The list can be customized with cms.storage.media.ignorePatterns configuration option.
     */
    protected $ignorePatterns;

    /**
     * @var int Cache for the storage folder name length.
     */
    protected $storageFolderNameLength;

    /**
     * MediaLibrary constructor.
     *
     * @param Translator $translator
     * @param Repository $config
     * @param CacheRepository $cache
     * @param UrlGenerator $urlGenerator
     * @param Factory $storage
     * @throws ApplicationException
     */
    public function __construct(
        Translator $translator,
        Repository $config,
        CacheRepository $cache,
        UrlGenerator $urlGenerator,
        Factory $storage
    ) {
        $this->translator = $translator;
        $this->config = $config;
        $this->cache = $cache;
        $this->urlGenerator = $urlGenerator;
        $this->storage = $storage;

        $this->storageFolder = $this->checkPath($this->config->get('cms.storage.media.folder', 'media'), true);
        $this->storagePath = rtrim($this->config->get('cms.storage.media.path', '/storage/app/media'), '/');

        $this->ignoreNames = $this->config->get('cms.storage.media.ignore', FileDefinitions::get('ignoreFiles'));

        $this->ignorePatterns = $this->config->get('cms.storage.media.ignorePatterns', ['^\..*']);

        $this->storageFolderNameLength = strlen($this->storageFolder);
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
    public static function instance(): MediaLibraryContract
    {
        return resolve(self::class);
    }

    /**
     * {@inheritDoc}
     */
    public function setCacheKey($cacheKey)
    {
        $this->cacheKey = $cacheKey;
    }

    /**
     * {@inheritDoc}
     */
    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     * @throws SystemException
     */
    public function listFolderContents($folder = '/', $sortBy = 'title', $filter = null, $ignoreFolders = false): array
    {
        $folder = $this->checkPath($folder);
        $fullFolderPath = $this->getMediaPath($folder);

        /*
         * Try to load the contents from cache
         */

        $cached = $this->cache->get($this->cacheKey, false);
        $cached = $cached ? @unserialize(@base64_decode($cached)) : [];

        if (!is_array($cached)) {
            $cached = [];
        }

        if (array_key_exists($fullFolderPath, $cached)) {
            $folderContents = $cached[$fullFolderPath];
        }
        else {
            $folderContents = $this->scanFolderContents($fullFolderPath);

            $cached[$fullFolderPath] = $folderContents;
            $this->cache->put(
                $this->cacheKey,
                base64_encode(serialize($cached)),
                $this->config->get('cms.storage.media.ttl', 10)
            );
        }

        /*
         * Sort the result and combine the file and folder lists
         */

        if ($sortBy !== false) {
            $this->sortItemList($folderContents['files'], $sortBy);
            $this->sortItemList($folderContents['folders'], $sortBy);
        }

        $this->filterItemList($folderContents['files'], $filter);

        if (!$ignoreFolders) {
            $folderContents = array_merge($folderContents['folders'], $folderContents['files']);
        }
        else {
            $folderContents = $folderContents['files'];
        }

        return $folderContents;
    }

    /**
     * {@inheritDoc}
     */
    public function findFiles($searchTerm, $sortBy = 'title', $filter = null): array
    {
        $words = explode(' ', Str::lower($searchTerm));
        $result = [];

        $findInFolder = function ($folder) use (&$findInFolder, $words, &$result, $sortBy, $filter) {
            $folderContents = $this->listFolderContents($folder, $sortBy, $filter);

            foreach ($folderContents as $item) {
                if ($item->type === MediaLibraryItem::TYPE_FOLDER) {
                    $findInFolder($item->path);
                }
                elseif ($this->pathMatchesSearch($item->path, $words)) {
                    $result[] = $item;
                }
            }
        };

        $findInFolder('/');

        /*
         * Sort the result
         */

        if ($sortBy !== false) {
            $this->sortItemList($result, $sortBy);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function deleteFiles($paths): bool
    {
        $fullPaths = [];
        foreach ($paths as $path) {
            $path = $this->checkPath($path);
            $fullPaths[] = $this->getMediaPath($path);
        }

        return $this->getStorageDisk()->delete($fullPaths);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function deleteFolder($path): bool
    {
        $path = $this->checkPath($path);
        $fullPaths = $this->getMediaPath($path);

        return $this->getStorageDisk()->deleteDirectory($fullPaths);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function exists($path): bool
    {
        $path = $this->checkPath($path);
        $fullPath = $this->getMediaPath($path);

        return $this->getStorageDisk()->exists($fullPath);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function folderExists($path): bool
    {
        $folderName = basename($path);
        $folderPath = dirname($path);

        $path = $this->checkPath($folderPath);
        $fullPath = $this->getMediaPath($path);

        $folders = $this->getStorageDisk()->directories($fullPath);
        foreach ($folders as $folder) {
            if (basename($folder) === $folderName) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritDoc}
     * @throws SystemException
     * @throws ApplicationException
     */
    public function listAllDirectories($exclude = []): array
    {
        $fullPath = $this->getMediaPath('/');

        $folders = $this->getStorageDisk()->allDirectories($fullPath);

        $folders = array_unique($folders, SORT_LOCALE_STRING);

        $result = [];

        foreach ($folders as $folder) {
            $folder = $this->getMediaRelativePath($folder);
            if ($folder === '') {
                $folder = '/';
            }

            if (Str::startsWith($folder, $exclude)) {
                continue;
            }

            $result[] = $folder;
        }

        if (!in_array('/', $result)) {
            array_unshift($result, '/');
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     * @throws FileNotFoundException
     */
    public function get($path): string
    {
        $path = $this->checkPath($path);
        $fullPath = $this->getMediaPath($path);
        return $this->getStorageDisk()->get($fullPath);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function put($path, $contents): bool
    {
        $path = $this->checkPath($path);
        $fullPath = $this->getMediaPath($path);
        return $this->getStorageDisk()->put($fullPath, $contents);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function moveFile($oldPath, $newPath, $isRename = false): bool
    {
        $oldPath = $this->checkPath($oldPath);
        $fullOldPath = $this->getMediaPath($oldPath);

        $newPath = $this->checkPath($newPath);
        $fullNewPath = $this->getMediaPath($newPath);

        return $this->getStorageDisk()->move($fullOldPath, $fullNewPath);
    }

    /**
     * {@inheritDoc}
     */
    public function copyFolder($originalPath, $newPath): bool
    {
        $disk = $this->getStorageDisk();

        $copyDirectory = function ($srcPath, $destPath) use (&$copyDirectory, $disk) {
            $srcPath = $this->checkPath($srcPath);
            $fullSrcPath = $this->getMediaPath($srcPath);

            $destPath = $this->checkPath($destPath);
            $fullDestPath = $this->getMediaPath($destPath);

            if (!$disk->makeDirectory($fullDestPath)) {
                return false;
            }

            $folderContents = $this->scanFolderContents($fullSrcPath);

            foreach ($folderContents['folders'] as $dirInfo) {
                if (!$copyDirectory($dirInfo->path, $destPath.'/'.basename($dirInfo->path))) {
                    return false;
                }
            }

            foreach ($folderContents['files'] as $fileInfo) {
                $fullFileSrcPath = $this->getMediaPath($fileInfo->path);

                if (!$disk->copy($fullFileSrcPath, $fullDestPath.'/'.basename($fileInfo->path))) {
                    return false;
                }
            }

            return true;
        };

        return $copyDirectory($originalPath, $newPath);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function moveFolder($originalPath, $newPath): bool
    {
        if (Str::lower($originalPath) !== Str::lower($newPath)) {
            // If there is no risk that the directory was renamed
            // by just changing the letter case in the name -
            // copy the directory to the destination path and delete
            // the source directory.

            if (!$this->copyFolder($originalPath, $newPath)) {
                return false;
            }

            $this->deleteFolder($originalPath);
        }
        else {
            // If there's a risk that the directory name was updated
            // by changing the letter case - swap source and destination
            // using a temporary directory with random name.

            $temporaryDirPath = $this->generateRandomTmpFolderName(dirname($originalPath));

            if (!$this->copyFolder($originalPath, $temporaryDirPath)) {
                $this->deleteFolder($temporaryDirPath);

                return false;
            }

            $this->deleteFolder($originalPath);

            return $this->moveFolder($temporaryDirPath, $newPath);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function makeFolder($path): bool
    {
        $path = $this->checkPath($path);
        $fullPath = $this->getMediaPath($path);

        return $this->getStorageDisk()->makeDirectory($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function resetCache()
    {
        $this->cache->forget($this->cacheKey);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function checkPath($path, $normalizeOnly = false): string
    {
        $path = str_replace('\\', '/', $path);
        $path = '/'.trim($path, '/');

        if ($normalizeOnly) {
            return $path;
        }

        /*
         * Validate folder names
         */
        $regexWhitelist = [
            '\w', // any word character
            preg_quote('@', '/'),
            preg_quote('.', '/'),
            '\s', // whitespace character
            preg_quote('-', '/'),
            preg_quote('_', '/'),
            preg_quote('/', '/'),
            preg_quote('(', '/'),
            preg_quote(')', '/'),
            preg_quote('[', '/'),
            preg_quote(']', '/'),
            preg_quote(',', '/'),
            preg_quote('=', '/'),
            preg_quote("'", '/'),
            preg_quote('&', '/'),
        ];

        if (!preg_match('/^[' . implode('', $regexWhitelist) . ']+$/iu', $path)) {
            throw new ApplicationException($this->translator->get('system::lang.media.invalid_path', compact('path')));
        }

        $regexDirectorySeparator = preg_quote('/', '#');
        $regexDot = preg_quote('.', '#');
        $regex = [
            // Beginning of path
            '(^'.$regexDot.'+?'.$regexDirectorySeparator.')',

            // Middle of path
            '('.$regexDirectorySeparator.$regexDot.'+?'.$regexDirectorySeparator.')',

            // End of path
            '('.$regexDirectorySeparator.$regexDot.'+?$)',
        ];

        /*
         * Validate invalid paths
         */
        $regex = '#'.implode('|', $regex).'#';
        if (preg_match($regex, $path) !== 0 || strpos($path, '//') !== false) {
            throw new ApplicationException($this->translator->get('system::lang.media.invalid_path', compact('path')));
        }

        return $path;
    }

    /**
     * Resolves the class and runs "checkPath()".
     * Keeping this method for backwards compatibility.
     *
     * @param $path
     * @param bool $normalizeOnly
     * @return string
     */
    public static function validatePath($path, $normalizeOnly = false): string
    {
        /** @var MediaLibraryContract $mediaLibrary */
        $mediaLibrary = resolve(self::class);
        return $mediaLibrary->checkPath($path, $normalizeOnly);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public static function url($file): string
    {
        /** @var MediaLibraryContract $mediaLibrary */
        $mediaLibrary = resolve(self::class);
        return $mediaLibrary->getPathUrl($file);
    }

    /**
     * {@inheritDoc}
     * @throws ApplicationException
     */
    public function getPathUrl($path): string
    {
        $path = $this->checkPath($path);

        $fullPath = $this->storagePath.implode('/', array_map('rawurlencode', explode('/', $path)));

        return $this->urlGenerator->to($fullPath);
    }

    /**
     * Returns a file or folder path with the prefixed storage folder.
     *
     * @param string $path Specifies a path to process.
     * @return string Returns a processed string.
     */
    protected function getMediaPath($path): string
    {
        return $this->storageFolder.$path;
    }

    /**
     * Returns path relative to the Library root folder.
     *
     * @param string $path Specifies a path relative to the Library disk root.
     * @return string Returns the updated path.
     * @throws ApplicationException
     * @throws SystemException
     */
    protected function getMediaRelativePath($path): string
    {
        $path = $this->checkPath($path, true);

        if (substr($path, 0, $this->storageFolderNameLength) == $this->storageFolder) {
            return substr($path, $this->storageFolderNameLength);
        }

        throw new SystemException(sprintf('Cannot convert Media Library path "%s" to a path relative to the Library root.', $path));
    }

    /**
     * Determines if the path should be visible (not ignored).
     *
     * @param string $path Specifies a path to check.
     * @return boolean Returns TRUE if the path is visible.
     */
    protected function isVisible($path): bool
    {
        $baseName = basename($path);

        if (in_array($baseName, $this->ignoreNames)) {
            return false;
        }

        foreach ($this->ignorePatterns as $pattern) {
            if (preg_match('/'.$pattern.'/', $baseName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Initializes a library item from a path and item type.
     *
     * @param string $path Specifies the item path relative to the storage disk root.
     * @param string $itemType Specifies the item type.
     * @return mixed Returns the MediaLibraryItem object or NULL if the item is not visible.
     * @throws ApplicationException
     * @throws SystemException
     */
    protected function initLibraryItem($path, $itemType)
    {
        $relativePath = $this->getMediaRelativePath($path);

        if (!$this->isVisible($relativePath)) {
            return;
        }

        /*
         * S3 doesn't allow getting the last modified timestamp for folders,
         * so this feature is disabled - folders timestamp is always NULL.
         */
        $lastModified = $itemType === MediaLibraryItem::TYPE_FILE
            ? $this->getStorageDisk()->lastModified($path)
            : null;

        /*
         * The folder size (number of items) doesn't respect filters. That
         * could be confusing for users, but that's safer than displaying
         * zero items for a folder that contains files not visible with a
         * currently applied filter. -ab
         */
        $size = $itemType === MediaLibraryItem::TYPE_FILE
            ? $this->getStorageDisk()->size($path)
            : $this->getFolderItemCount($path);

        $publicUrl = $this->getPathUrl($relativePath);

        return new MediaLibraryItem($relativePath, $size, $lastModified, $itemType, $publicUrl);
    }

    /**
     * Returns a number of items on a folder.
     *
     * @param string $path Specifies the folder path relative to the storage disk root.
     * @return integer Returns the number of items in the folder.
     */
    protected function getFolderItemCount($path): int
    {
        $folderItems = array_merge(
            $this->getStorageDisk()->files($path),
            $this->getStorageDisk()->directories($path)
        );

        $size = 0;
        foreach ($folderItems as $folderItem) {
            if ($this->isVisible($folderItem)) {
                $size++;
            }
        }

        return $size;
    }

    /**
     * Fetches the contents of a folder from the Library.
     *
     * @param string $fullFolderPath Specifies the folder path relative the the storage disk root.
     * @return array Returns an array containing two elements - 'files' and 'folders', each is an array of MediaLibraryItem objects.
     * @throws ApplicationException
     * @throws SystemException
     */
    protected function scanFolderContents($fullFolderPath): array
    {
        $result = [
            'files' => [],
            'folders' => []
        ];

        $files = $this->getStorageDisk()->files($fullFolderPath);
        foreach ($files as $file) {
            if ($libraryItem = $this->initLibraryItem($file, MediaLibraryItem::TYPE_FILE)) {
                $result['files'][] = $libraryItem;
            }
        }

        $folders = $this->getStorageDisk()->directories($fullFolderPath);
        foreach ($folders as $folder) {
            if ($libraryItem = $this->initLibraryItem($folder, MediaLibraryItem::TYPE_FOLDER)) {
                $result['folders'][] = $libraryItem;
            }
        }

        return $result;
    }

    /**
     * Sorts the item list by title, size or last modified date.
     *
     * @param array $itemList Specifies the item list to sort.
     * @param mixed $sortSettings Determines the sorting preference. Supported values are 'title', 'size', 'lastModified' (see SORT_BY_XXX class constants)
     *                            or an associative array with a 'by' key and a 'direction' key: ['by' => SORT_BY_XXX, 'direction' => SORT_DIRECTION_XXX].
     */
    protected function sortItemList(&$itemList, $sortSettings)
    {
        $files = [];
        $folders = [];

        // Convert string $sortBy to array
        if (is_string($sortSettings)) {
            $sortSettings = [
                'by' => $sortSettings,
                'direction' => self::SORT_DIRECTION_ASC,
            ];
        }

        usort($itemList, function ($a, $b) use ($sortSettings) {
            $result = 0;

            switch ($sortSettings['by']) {
                case self::SORT_BY_TITLE:
                    $result = strcasecmp($a->path, $b->path);
                    break;
                case self::SORT_BY_SIZE:
                    if ($a->size < $b->size) {
                        $result = -1;
                    } else {
                        $result = $a->size > $b->size ? 1 : 0;
                    }
                    break;
                case self::SORT_BY_MODIFIED:
                    if ($a->lastModified < $b->lastModified) {
                        $result = -1;
                    } else {
                        $result = $a->lastModified > $b->lastModified ? 1 : 0;
                    }
                    break;
            }

            // Reverse the polarity of the result to direct sorting in a descending order instead
            if ($sortSettings['direction'] === self::SORT_DIRECTION_DESC) {
                $result = 0 - $result;
            }

            return $result;
        });
    }

    /**
     * Filters item list by file type.
     *
     * @param array $itemList Specifies the item list to sort.
     * @param string $filter Determines the document type filtering preference.
     *                       Supported values are 'image', 'video', 'audio', 'document' (see FILE_TYPE_XXX constants of MediaLibraryItem class).
     */
    protected function filterItemList(&$itemList, $filter)
    {
        if (!$filter) {
            return;
        }

        $result = [];
        foreach ($itemList as $item) {
            if ($item->getFileType() == $filter) {
                $result[] = $item;
            }
        }

        $itemList = $result;
    }

    /**
     * Initializes and returns the Media Library disk.
     * This method should always be used instead of trying to access the
     * $storageDisk property directly as initializing the disc requires
     * communicating with the remote storage.
     *
     * @return Filesystem Returns the storage disk object.
     */
    protected function getStorageDisk(): Filesystem
    {
        if ($this->storageDisk) {
            return $this->storageDisk;
        }

        return $this->storageDisk = $this->storage->disk(
            $this->config->get('cms.storage.media.disk', 'local')
        );
    }

    /**
     * Determines if file path contains all words form the search term.
     *
     * @param string $path Specifies a path to examine.
     * @param array $words A list of words to check against.
     * @return boolean
     */
    protected function pathMatchesSearch($path, $words):  bool
    {
        $path = Str::lower($path);

        foreach ($words as $word) {
            $word = trim($word);
            if (!strlen($word)) {
                continue;
            }

            if (!Str::contains($path, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $location
     * @return string
     * @throws ApplicationException
     */
    protected function generateRandomTmpFolderName($location): string
    {
        $temporaryDirBaseName = time();

        $tmpPath = $location.'/tmp-'.$temporaryDirBaseName;

        while ($this->folderExists($tmpPath)) {
            $temporaryDirBaseName++;
            $tmpPath = $location.'/tmp-'.$temporaryDirBaseName;
        }

        return $tmpPath;
    }

    /**
     * {@inheritDoc}
     */
    public function getSortByTitleString(): string
    {
        return self::SORT_BY_TITLE;
    }

    /**
     * {@inheritDoc}
     */
    public function getSortBySizeString(): string
    {
        return self::SORT_BY_SIZE;
    }

    /**
     * {@inheritDoc}
     */
    public function getSortByModifiedString(): string
    {
        return self::SORT_BY_MODIFIED;
    }

    /**
     * {@inheritDoc}
     */
    public function getSortDirectionAscString(): string
    {
        return self::SORT_DIRECTION_ASC;
    }

    /**
     * {@inheritDoc}
     */
    public function getSortDirectionDescString(): string
    {
        return self::SORT_DIRECTION_DESC;
    }
}
