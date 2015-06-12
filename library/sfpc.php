<?php

/**
    sfpc - safe file put contents - transactional safe file_put_contents
*/

class sfpc {
    static function file_put_contents($filename, $content, $mtime = null)
    {
        $tempnam = tempnam(pathinfo($filename,PATHINFO_DIRNAME), 'phph-tmp-file-');
        file_put_contents($tempnam, $content);
        chmod($tempnam, 0664); // make alle files readable by world
        if ($mtime) { touch($tempnam, $mtime); }
        rename($tempnam, $filename);
    }

    static function symlink($target, $link)
    {
        // do not create the temp file with tempnam - symlink wants to create it ...
        $tempnam = pathinfo($link, PATHINFO_DIRNAME) .  'phph-tmp-file-' . uniqid(true);
        symlink($target, $tempnam);
        rename($tempnam, $link);
    }
}