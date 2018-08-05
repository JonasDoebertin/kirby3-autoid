<?php

namespace Bnomei;

class AutoID
{
    private static $fieldname = 'autoid'; // TODO: custom fieldname is not a good idea imho
    public static function fieldname(): string
    {
        return static::$fieldname;
    }

    private static $indexname = null;

    private static $cache = null;
    private static function cache(): \Kirby\Cache\Cache
    {
        if (!static::$cache) {
            static::$cache = kirby()->cache('bnomei.autoid');
        }
        // create new index table on new version of plugin
        if (!static::$indexname) {
            static::$indexname = 'index'.str_replace('.', '', kirby()->plugin('bnomei/autoid')->version());
        }
        return static::$cache;
    }

    // get current index, rebuild if cache does not exist
    private static function index(): array
    {
        $index = static::cache()->get(static::$indexname);
        if (!$index) {
            $indexed = static::rebuildIndex();
            // TODO: logging?
        }
        return static::cache()->get(static::$indexname);
    }

    private static function updateIndex(array $index): array
    {
        return static::cache()->set(static::$indexname, $index);
    }

    private static function rebuildIndex(): int
    {
        if ($c = static::cache()) {
            $c->flush();
        }
        $indexed = 0;
        $entries = [];
        // NOTE: this operation will be very slow if index grows
        foreach (kirby()->pages()->index() as $page) {
            $newEntries = static::indexPage($page);
            $indexed += count($newEntries);
            $entries = array_merge($entries, $newEntries);
        }
        // update cache in one push to improve performance
        // if cache is filebased
        static::pushEntries($entries);
        return $indexed;
    }

    // append autoid data to in memory array
    private static function commitEntry(
        array $tmp,
        string $autoid,
        string $pageId,
        string $structureFieldname = null,
        string $filename = null
    ): array {
        // 1 char keys to reduce filesize of cache => more speed
        $tmp[$autoid] = [
            'i' => $pageId,
            's' => $structureFieldname,
            'f' => $filename,
        ];
        return $tmp;
    }

    // write array of autoid data to cache
    private static function pushEntries($entries): bool
    {
        $index = array_merge(static::indexPage(), $entries);
        return static::updateIndex($index);
    }

    private static function removeEntry($autoid): bool
    {
        $index = static::cache()->get(static::$indexname);
        if ($index && is_array($index) && \Kirby\Toolkit\A::get($index, $autoid())) {
            unset($index, $autoid);
            return static::cache()->set(static::$indexname, $index);
        }
        return false;
    }

    private static function indexPage(\Kirby\Cms\Page $page, array $commits = []): int
    {
        $commitsPage = [];
        $commitsFiles = [];
        $updatePage = []; // array to update Page-Object with
        $updateFile = []; // array to update Page-Object with

        foreach ($page->blueprint()->fields() as $field) {
            if (option('bnomei.autoid.index.pages') && $field->key() == static::$fieldname) {
                if ($field->isEmpty()) {
                    $autoid = static::generator();
                    $updatePage[] = [
                        static::$fieldname => $autoid
                    ];
                    $commitsPage = static::commitEntry($commitsPage, $autoid, $page->id());
                } else {
                    $commitsPage = static::commitEntry($commitsPage, $field->value(), $page->id());
                }
            } else if (option('bnomei.autoid.index.structures')) {
                // make copy as array so can update
                $data = \Yaml::decode($field->value());
                // var_dump($data); die(); // TODO: check structures-array structure ;-)
                $copy = $data; // this is a copy since its an array
                $hasChange = false;
                foreach ($data as $structureField) {
                    // TODO: this probably wrong. key might be numeric sort order. maybe loop through fields in $structureObject?
                    // TODO: is support for nested structures needed?
                    if ($structureField['key'] == static::$fieldname) {
                        // check is is empty or not
                        $value = \Kirby\Toolkit\A::get($structureField, 'value');
                        if(empty($value)) {
                            // update structure in copy
                            $hasChange = true;
                            $autoid = static::generator();
                            $copy[''][''][static::$fieldname] = $autoid; // TODO: figure this out
                            $commitsPage = static::commitEntry($commitsPage, $autoid, $page->id(), $field->key());
                        } else {
                            $commitsPage = static::commitEntry($commitsPage, $value, $page->id(), $field->key());
                        }
                    }
                }
                if($hasChange) {
                    $updatePage[] = [
                        $field->key() => \Yaml::encode($copy),
                    ];
                }
            }
        }

        // loop through each File of page and check blueprint and fields
        if (option('bnomei.autoid.index.files')) {
            foreach ($page->files() as $file) {
                foreach ($file->blueprint()->fields() as $field) {
                    if ($field->key() == static::$fieldname) {
                        if ($field->isEmpty()) {
                            $autoid = static::generator();
                            $updateFile = [
                                static::$fieldname => $autoid
                            ];
                            
                            try {
                                $file->update($updateFile);
                                $commitsFiles = static::commitEntry($commitsFiles, $autoid, $page->id(), null, $file->filename());  // TODO: name or filename?
                            } catch (Exception $e) {
                                // echo $e->getMessage();
                                // TODO: throw exception again?
                            }
                        } else {
                            $commitsFiles = static::commitEntry($commitsFiles, $field->value(), $page->id(), null, $file->filename());  // TODO: name or filename?
                        }
                    }
                }
            }
        }
        
        try {
            if (count($updatePage) > 0) {
                $page->update($updatePage);
            }
        } catch (Exception $e) {
            // echo $e->getMessage();
            // TODO: throw exception again?
            $commitsPage = []; // reset since failed
        }
        return array_merge($commits, $commitsPage, $commitsFiles);
    }

    public static function find($autoid)
    {
        if ($entry = \Kirby\Toolkit\A::get(static::index(), $autoid)) {
            if ($page = \page(\Kirby\Toolkit\A::get($entry, 'i'))) {
                if ($structureField = \Kirby\Toolkit\A::get($entry, 's')) {
                    foreach($page->${$structureField}()->toStructure() as $structureObject) {
                        // TODO: this probably wrong. key might be numeric sort order. maybe loop through fields in $structureObject?
                        if($structureObject->key() == static::$fieldname {
                            return $structureObject;
                        }
                    }
                } elseif ($filename = \Kirby\Toolkit\A::get($entry, 'f')) {
                    return $page->file($filename);
                }
                return $page;
            }
        }
        return null;
    }

    /****************************************************************
     * PUBLIC generator
     */

    // http://stackoverflow.com/questions/1846202/php-how-to-generate-a-random-unique-alphanumeric-string/13733588#13733588
    public static function cryptoRandSecure($min, $max)
    {
        $range = $max - $min;
        if ($range < 1) {
            return $min;
        } // not so random...
        $log = ceil(log($range, 2));
        $bytes = (int) ($log / 8) + 1; // length in bytes
        $bits = (int) $log + 1; // length in bits
        $filter = (int) (1 << $bits) - 1; // set all lower bits to 1
        do {
            $rnd = \hexdec(\bin2hex(\openssl_random_pseudo_bytes($bytes)));
            $rnd = $rnd & $filter; // discard irrelevant bits
        } while ($rnd > $range);
        return $min + $rnd;
    }

    public static function getToken($length = 40, $withLower = true, $withUpper = true, $withNumbers = true)
    {
        $token = "";
        $codeAlphabet = "";
        if ($withUpper) {
            $codeAlphabet .= "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        }
        if ($withLower) {
            $codeAlphabet .= "abcdefghijklmnopqrstuvwxyz";
        }
        if ($withNumbers) {
            $codeAlphabet .= "0123456789";
        }
        $max = strlen($codeAlphabet); // edited
        for ($i=0; $i < $length; $i++) {
            $token .= $codeAlphabet[self::cryptoRandSecure(0, $max-1)];
        }
        return $token;
    }

    public static function defaultGenerator(): string
    {
        // alphanumeric: 8 chars, lowercase and numbers
        // (26 + 10) ^ 8 = 2.821.109.907.456 = ~ 2.8 trillion possibilities
        return static::getToken(8, true, false, true);
    }

    public static function generator(string $seed = null): string
    {
        $hash = null;
        $generator = kirby()->option('bnomei.autoid.generator');
        if ($generator && is_callable($generator)) {
            $hash = $generator($seed);
        }
        else {
            $hash = static::defaultGenerator();
        }
        // if custom generator is not unique enough give it a few tries
        $break = option('bnomei.autoid.generator.break');
        while($break > 0 && \Kirby\Toolkit\A::get(static::index(), $hash) != null) {
            $hash = static::generator($seed);
            $break--;
            if($break == 0) {
                // TODO: throw exception and/or do logging?
                $hash = static::defaultGenerator();
            }
        }
        return $hash;
    }

    /****************************************************************
     * PUBLIC add/remove
     */

    public static function addPage(\Kirby\Cms\Page $page): bool
    {
        return static::pushEntries(static::indexPage($page));
    }

    public static function removePage(\Kirby\Cms\Page $page): bool
    {
        $field = $page->${static::$fieldname}();
        if ($field->isNotEmpty()) {
            return static::removeEntry($field->value());
        }
        return false;
    }

    public static function addFile(\Kirby\Cms\File $file): bool
    {
        return static::pushEntries(static::indexPage($file->page()));
    }

    public static function removeFile(\Kirby\Cms\File $file): bool
    {
        $field = $file->${static::$fieldname}();
        if ($field->isNotEmpty()) {
            return static::removeEntry($field->value());
        }
        return false;
    }
}