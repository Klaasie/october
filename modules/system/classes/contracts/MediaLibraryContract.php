<?php namespace System\Classes\Contracts;

/**
 * Interface MediaLibraryContract
 *
 * @package System\Classes\Contracts
 */
interface MediaLibraryContract
{
    /**
     * @return self
     * @deprecated V1.0.xxx Instead of using this method,
     *                      rework your logic to resolve the class through dependency injection.
     */
    public static function instance(): self;

    /**
     * Set the cache key
     *
     * @param string $cacheKey The key to set as the cache key for this instance
     * @return void
     * @todo Type hint!
     */
    public function setCacheKey($cacheKey);

    /**
     * Get the cache key
     *
     * @return string The cache key to set as the cache key for this instance
     */
    public function getCacheKey(): string;

    /**
     * Returns a list of folders and files in a Library folder.
     *
     * @param string $folder Specifies the folder path relative the the Library root.
     * @param mixed $sortBy Determines the sorting preference.
     *                      Supported values are 'title', 'size', 'lastModified' (see SORT_BY_XXX class constants), FALSE (to disable sorting),
     *                      or an associative array with a 'by' key and a 'direction' key: ['by' => SORT_BY_XXX, 'direction' => SORT_DIRECTION_XXX].
     * @param string $filter Determines the document type filtering preference.
     *                       Supported values are 'image', 'video', 'audio', 'document' (see FILE_TYPE_XXX constants of MediaLibraryItem class).
     * @param boolean $ignoreFolders Determines whether folders should be suppressed in the result list.
     * @return array Returns an array of MediaLibraryItem objects.
     * @todo Type hint!
     */
    public function listFolderContents($folder = '/', $sortBy = 'title', $filter = null, $ignoreFolders = false): array;

    /**
     * Finds files in the Library.
     *
     * @param string $searchTerm Specifies the search term.
     * @param mixed $sortBy Determines the sorting preference.
     *                      Supported values are 'title', 'size', 'lastModified' (see SORT_BY_XXX class constants), FALSE (to disable sorting),
     *                      or an associative array with a 'by' key and a 'direction' key: ['by' => SORT_BY_XXX, 'direction' => SORT_DIRECTION_XXX].
     * @param string $filter Determines the document type filtering preference.
     *                       Supported values are 'image', 'video', 'audio', 'document' (see FILE_TYPE_XXX constants of MediaLibraryItem class).
     * @return array Returns an array of MediaLibraryItem objects.
     * @todo Type hint!
     */
    public function findFiles($searchTerm, $sortBy = 'title', $filter = null): array;

    /**
     * Deletes a file from the Library.
     *
     * @param array $paths A list of file paths relative to the Library root to delete.
     * @return bool
     * @todo Type hint!
     */
    public function deleteFiles($paths): bool;

    /**
     * Deletes a folder from the Library.
     *
     * @param string $path Specifies the folder path relative to the Library root.
     * @return bool
     * @todo Type hint!
     */
    public function deleteFolder($path): bool;

    /**
     * Determines if a file with the specified path exists in the library.
     *
     * @param string $path Specifies the file path relative the the Library root.
     * @return boolean Returns TRUE if the file exists.
     * @todo Type hint!
     */
    public function exists($path): bool;

    /**
     * Determines if a folder with the specified path exists in the library.
     *
     * @param string $path Specifies the folder path relative the the Library root.
     * @return boolean Returns TRUE if the folder exists.
     * @todo Type hint!
     */
    public function folderExists($path): bool;

    /**
     * Returns a list of all directories in the Library, optionally excluding some of them.
     *
     * @param array $exclude A list of folders to exclude from the result list.
     *                       The folder paths should be specified relative to the Library root.
     * @return array
     * @todo Type hint!
     */
    public function listAllDirectories($exclude = []): array;

    /**
     * Returns a file contents.
     *
     * @param string $path Specifies the file path relative the the Library root.
     * @return string Returns the file contents
     * @todo Type hint!
     */
    public function get($path): string;

    /**
     * Puts a file to the library.
     *
     * @param string $path Specifies the file path relative the the Library root.
     * @param string $contents Specifies the file contents.
     * @return boolean
     * @todo Type hint!
     */
    public function put($path, $contents): bool;

    /**
     * Moves a file to another location.
     *
     * @param string $oldPath Specifies the original path of the file.
     * @param string $newPath Specifies the new path of the file.
     * @param bool $isRename
     * @return boolean
     * @todo Type hint!
     */
    public function moveFile($oldPath, $newPath, $isRename = false): bool;

    /**
     * Copies a folder.
     *
     * @param string $originalPath Specifies the original path of the folder.
     * @param string $newPath Specifies the new path of the folder.
     * @return boolean
     * @todo Type hint!
     */
    public function copyFolder($originalPath, $newPath): bool;

    /**
     * Moves a folder.
     *
     * @param string $originalPath Specifies the original path of the folder.
     * @param string $newPath Specifies the new path of the folder.
     * @return boolean
     * @todo Type hint!
     */
    public function moveFolder($originalPath, $newPath): bool;

    /**
     * Creates a folder.
     *
     * @param string $path Specifies the folder path.
     * @return boolean
     * @todo Type hint!
     */
    public function makeFolder($path): bool;

    /**
     * Resets the Library cache.
     *
     * The cache stores the library table of contents locally in order to optimize
     * the performance when working with remote storage. The default cache TTL is
     * 10 minutes. The cache is deleted automatically when an item is added, changed
     * or deleted. This method allows to reset the cache forcibly.
     *
     * @return void
     */
    public function resetCache();

    /**
     * Checks if file path doesn't contain any substrings that would pose a security threat.
     * Throws an exception if the path is not valid.
     *
     * @param string $path Specifies the path.
     * @param boolean $normalizeOnly Specifies if only the normalization, without validation should be performed.
     * @return string Returns a normalized path.
     * @todo Type hint!
     */
    public function checkPath($path, $normalizeOnly = false): string;

    /**
     * Returns a public file URL.
     *
     * @param string $path Specifies the file path relative the the Library root.
     * @return string
     * @todo Type hint!
     */
    public function getPathUrl($path): string;

    /**
     * @return string
     */
    public function getSortByTitleString(): string;

    /**
     * @return string
     */
    public function getSortBySizeString(): string;

    /**
     * @return string
     */
    public function getSortByModifiedString(): string;

    /**
     * @return string
     */
    public function getSortDirectionAscString(): string;

    /**
     * @return string
     */
    public function getSortDirectionDescString():string;
}
