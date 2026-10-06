<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator\Generator;

use RuntimeException;
use Throwable;

/**
 * Command line front end for the vocabulary generator.
 */
final class Cli
{
    private const USAGE = "Usage: generate-vocabulary [version|latest] [--file=<path>] [--output=<path>]\n\n"
        . "Writes data/vocabulary.php from the schema.org JSON-LD dump and prints the version it generated.\n"
        . "  --file=<path>    Read a local schemaorg-current-https.jsonld instead of downloading it.\n"
        . "                   The version argument is then used as the version label.\n"
        . "  --output=<path>  Write somewhere other than data/vocabulary.php.\n";

    /**
     * Runs the generator and returns the process exit code.
     *
     * @param list<string> $args Command line arguments without the script name.
     */
    public function run(array $args): int
    {
        $positional = [];
        $file = null;
        $output = dirname(__DIR__, 2) . '/data/vocabulary.php';
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                fwrite(STDOUT, self::USAGE);

                return 0;
            } elseif (strpos($arg, '--file=') === 0) {
                $file = substr($arg, 7);
            } elseif (strpos($arg, '--output=') === 0) {
                $output = substr($arg, 9);
            } elseif (strpos($arg, '--') === 0) {
                fwrite(STDERR, "Unknown option: {$arg}\n");

                return 2;
            } else {
                $positional[] = $arg;
            }
        }

        $version = $positional[0] ?? 'latest';
        $generator = new VocabularyGenerator();

        try {
            if ($file !== null) {
                $json = file_get_contents($file);
                if ($json === false) {
                    throw new RuntimeException("Could not read {$file}.");
                }
            } else {
                if ($version === 'latest') {
                    $version = $generator->latestVersion();
                }
                $json = $this->download($generator, $version);
            }

            $jsonld = json_decode($json, true);
            if (!is_array($jsonld)) {
                throw new RuntimeException('The vocabulary file is not valid JSON.');
            }

            $data = $generator->build($jsonld, $version);
            if (file_put_contents($output, $generator->export($data)) === false) {
                throw new RuntimeException(sprintf('Could not write %s.', $output));
            }
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        fwrite(STDERR, sprintf(
            "Wrote %s: %d types, %d properties, %d data types, %d enumerations.\n",
            $output,
            count($data['types']),
            count($data['properties']),
            count($data['dataTypes']),
            count($data['enumerations'])
        ));
        fwrite(STDOUT, $version . "\n");

        return 0;
    }

    /**
     * Downloads the dump for a version, using a copy in the system temporary directory when there is one.
     */
    private function download(VocabularyGenerator $generator, string $version): string
    {
        $cache = sys_get_temp_dir() . '/schemaorg-' . preg_replace('/[^0-9A-Za-z.]/', '_', $version) . '.jsonld';
        if (is_file($cache)) {
            return (string) file_get_contents($cache);
        }

        $json = $generator->fetch("https://schema.org/version/{$version}/schemaorg-current-https.jsonld");
        file_put_contents($cache, $json);

        return $json;
    }
}
