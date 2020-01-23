<?php

declare(strict_types=1);

namespace Klaasie\Backend\Skins;

use Backend\Classes\Skin as SkinBase;
use File;

class Skin extends SkinBase
{
    public function skinDetails(){
        return [
            'name' => 'Tailwind Css'
        ];
    }

    public function __construct()
    {
        $this->skinPath = $this->defaultSkinPath = plugins_path() . '/klaasie/backend';
        $this->publicSkinPath = $this->defaultPublicSkinPath = File::localToPublic($this->skinPath);
    }
}
