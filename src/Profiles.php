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
     * @return list<Profile>
     */
    public static function google(): array
    {
        $files = glob(dirname(__DIR__) . '/profiles/google/*.json') ?: [];
        sort($files, SORT_STRING);

        return array_map(static fn (string $file): Profile => RuleProfile::fromFile($file), $files);
    }
}
