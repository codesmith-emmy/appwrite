<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use Utopia\CLI\Console;
use Utopia\Fetch\Client;
use Utopia\Platform\Action;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class DevGenerateTranslations extends Action
{
    private string $apiKey = '';

    public static function getName(): string
    {
        return 'dev-generate-translations';
    }

    public function __construct()
    {
        $this
            ->desc('Generate translations in all languages')
            ->param('dry-run', 'true', new Boolean(true), 'If action should do a dry run. Dry run does not write into files', true)
            ->param('api-key', '', new Text(256), 'Open AI API key. Only used during non-dry runs to generate translations.', true)
            ->callback(fn ($dryRun, $apiKey) => $this->action($dryRun, $apiKey));
    }

    public function action(bool|string $dryRun, string $apiKey): void
    {
        $dryRun = \strval($dryRun) === 'true';

        Console::info("Started");

        if (!$dryRun && empty($apiKey)) {
            Console::error("Please specify --api-key='OPEN_AI_API_KEY' or run with --dry-run");
            return;
        }

        $this->apiKey = $apiKey;

        $dir = __DIR__ . '/../../../../app/config/locale/translations';
        $mainFile = 'en.json';

        $mainJson = \json_decode(\file_get_contents($dir . '/' . $mainFile), true);
        $mainKeys = \array_keys($mainJson);

        $files = array_diff(scandir($dir), array('.', '..', $mainFile));

        foreach ($files as $file) {
            $fileJson = \json_decode(\file_get_contents($dir . '/' . $file), true);
            $fileKeys = \array_keys($fileJson);

            // Trick to clear specific key from all translation files:
            // $json = \json_decode(\file_get_contents($dir . '/' . $file), true);
            // unset($json['emails.magicSession.optionUrl']);
            // \file_put_contents($dir . '/' . $file, \json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | 0));
            // continue;

            $missingKeys = [];

            foreach ($mainKeys as $key) {
                if (!(\in_array($key, $fileKeys))) {
                    $missingKeys[] = $key;
                }
            }

            if (\count($missingKeys) > 0) {
                if ($dryRun) {
                    $keys = \implode(', ', $missingKeys);
                    Console::warning("{$file} missing translation for: {$keys}");
                } else {
                    $language = \explode('.', $file)[0];
                    $json = \json_decode(\file_get_contents($dir . '/' . $file), true);

                    foreach ($missingKeys as $missingKey) {
                        $translation = $this->generateTranslation($language, $mainJson[$key]);

                        // This puts new key at beginning to prevent merge conflict issue and ending comma
                        $newPair = [];
                        $newPair[$missingKey] = $translation;
                        $json = \array_merge($newPair, $json);
                    }

                    \file_put_contents($dir . '/' . $file, \json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | 0));

                    Console::success("Generated {$key} for {$language}");
                }
            }
        }

        Console::info("Done");
    }

    private function generateTranslation(string $targetLanguage, string $enTranslation): string
    {
        $placeholders = [];

        $id = 0;
        $pattern = '/{{\w+}}/';

        $enTranslation = preg_replace_callback($pattern, function ($match) use (&$id, &$placeholders) {
            $placeholders[$id] = $match[0];
            $key = "<m id={$id} />";
            $id++;
            return $key;
        }, $enTranslation);

        $response = Client::fetch('https://api-free.deepl.com/v2/translate', [
            'content-type' => Client::CONTENT_TYPE_APPLICATION_JSON,
            'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey
        ], Client::METHOD_POST, [
            'target_lang' => $targetLanguage,
            'text' => [$enTranslation]
        ], [], 60);

        $body = \json_decode($response->getBody(), true);

        if ($response->getStatusCode() >= 400) {
            throw new Exception($response->getBody() . ' with status code ' . $response->getStatusCode() . ' for language ' . $targetLanguage . ' and message ' . $enTranslation);
        }

        $targetTranslation = $body['translations'][0]['text'];

        $id = 0;
        foreach ($placeholders as $placeholder) {
            $targetTranslation = \str_replace("<m id={$id} />", $placeholder, $targetTranslation);
            $id++;
        }

        return $targetTranslation;
    }
}
