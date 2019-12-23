<?php

use Cms\Classes\Theme;
use System\Classes\CombineAssets;
use System\Classes\Contracts\CombineAssetsContract;

class CombineAssetsTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        CombineAssets::resetCache();
    }

    //
    // Tests
    //

    public function testCombiner()
    {
        /** @var CombineAssetsContract $combiner */
        $combiner = resolve(CombineAssetsContract::class);

        /*
         * Supported file extensions should exist
         */
        $jsExt = $cssExt = self::getProtectedProperty($combiner, 'jsExtensions');
        $this->assertInternalType('array', $jsExt);

        $cssExt = self::getProtectedProperty($combiner, 'cssExtensions');
        $this->assertInternalType('array', $cssExt);

        /*
         * Check service methods
         */
        $this->assertTrue(method_exists($combiner, 'combine'));
        $this->assertTrue(method_exists($combiner, 'resetCache'));
    }

    public function testCombine()
    {
        /** @var CombineAssetsContract $combiner */
        $combiner = resolve(CombineAssetsContract::class);

        $url = $combiner->combine(
            [
                'assets/css/style1.css',
                'assets/css/style2.css'
            ],
            base_path().'/tests/fixtures/themes/test'
        );
        $this->assertNotNull($url);
        $this->assertRegExp('/\w+[-]\d+/i', $url); // Must contain hash-number

        $url = $combiner->combine(
            [
                'assets/js/script1.js',
                'assets/js/script2.js'
            ],
            base_path().'/tests/fixtures/themes/test'
        );
        $this->assertNotNull($url);
        $this->assertRegExp('/\w+[-]\d+/i', $url); // Must contain hash-number
    }

    public function testPutCache()
    {
        $sampleId = md5('testhash');
        $sampleStore = ['version' => 12345678];
        $samplePath = '/tests/fixtures/Cms/themes/test';

        /** @var CombineAssetsContract $combiner */
        $combiner = resolve(CombineAssetsContract::class);
        $value = self::callProtectedMethod($combiner, 'putCache', [$sampleId, $sampleStore]);

        $this->assertTrue($value);
    }

    public function testGetTargetPath()
    {
        /** @var CombineAssetsContract $combiner */
        $combiner = resolve(CombineAssetsContract::class);

        $value = self::callProtectedMethod($combiner, 'getTargetPath', ['/combine']);
        $this->assertEquals('combine/', $value);

        $value = self::callProtectedMethod($combiner, 'getTargetPath', ['/index.php/combine']);
        $this->assertEquals('index-php/combine/', $value);
    }

    public function testMakeCacheId()
    {
        $sampleResources = ['assets/css/style1.css', 'assets/css/style2.css'];
        $samplePath = base_path().'/tests/fixtures/cms/themes/test';

        /** @var CombineAssetsContract $combiner */
        $combiner = resolve(CombineAssetsContract::class);
        self::setProtectedProperty($combiner, 'localPath', $samplePath);

        $value = self::callProtectedMethod($combiner, 'getCacheKey', [$sampleResources]);
        $this->assertEquals(md5($samplePath.implode('|', $sampleResources)), $value);
    }

    public function testResetCache()
    {
        /** @var CombineAssetsContract $combiner */
        $combiner = resolve(CombineAssetsContract::class);
        $this->assertNull($combiner->resetCache());
    }
}
