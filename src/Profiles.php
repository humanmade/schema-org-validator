<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

/**
 * The profiles bundled with the package.
 */
final class Profiles
{
    /**
     * Every profile in profiles/google, sorted by file name.
     *
     * Pass profile ids such as `google/article`, or just `article`, to load only those.
     *
     * @return list<Profile>
     */
    public static function google(string ...$ids): array
    {
        $files = glob(dirname(__DIR__) . '/profiles/google/*.json') ?: [];
        sort($files, SORT_STRING);

        $wanted = array_map(static fn (string $id): string => preg_replace('~^google/~', '', $id) ?? $id, $ids);
        $profiles = [];
        foreach ($files as $file) {
            if ($wanted === [] || in_array(basename($file, '.json'), $wanted, true)) {
                $profiles[] = RuleProfile::fromFile($file);
            }
        }

        return $profiles;
    }
}
