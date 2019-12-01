<?php

use System\Classes\Contracts\MediaLibraryContract;

class MediaLibraryTest extends TestCase // @codingStandardsIgnoreLine
{
    public function invalidPathsProvider()
    {
        return [
            ['./file'],
            ['../secret'],
            ['.../secret'],
            ['/../secret'],
            ['/.../secret'],
            ['/secret/..'],
            ['file/../secret'],
            ['file/..'],
            ['......./secret'],
            ['./file'],
        ];
    }

    public function validPathsProvider()
    {
        return [
            ['file'],
            ['folder/file'],
            ['/file'],
            ['/folder/file'],
            ['/.file'],
            ['/..file'],
            ['/...file'],
            ['file.ext'],
            ['file..ext'],
            ['file...ext'],
            ['one,two.ext'],
            ['one(two)[].ext'],
            ['one=(two)[].ext'],
            ['one_(two)[].ext'],
        ];
    }

    /**
     * @dataProvider invalidPathsProvider
     */
    public function testInvalidPathsOnValidatePath($path)
    {
        $this->expectException('ApplicationException');
        /** @var MediaLibraryContract $mediaLibrary */
        $mediaLibrary = resolve(MediaLibraryContract::class);
        $mediaLibrary->checkPath($path);
    }

    /**
     * @dataProvider validPathsProvider
     */
    public function testValidPathsOnValidatePath($path)
    {
        /** @var MediaLibraryContract $mediaLibrary */
        $mediaLibrary = resolve(MediaLibraryContract::class);
        $result = $mediaLibrary->checkPath($path);
        $this->assertInternalType('string', $result);
    }
}
