<?php

declare(strict_types=1);

namespace CrowdSec\Whm\Helper;

use CrowdSec\Whm\Acquisition\Config;
use CrowdSec\Whm\Exception;
use Symfony\Component\Yaml\Exception\DumpException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

class Data
{
    private $acquisDir;
    private $acquisPath;
    private $configContent = [];
    /** @TODO Have a plugin setting for this path */
    private $configPath = '/etc/crowdsec/config.yaml';
    private $yamlAcquisitionByHash = [];

    public function convertFormToYaml(array $formData): array
    {
        $acquisitionData = [];

        $this->validateFilepath($formData);
        $acquisitionData['filepath'] = $this->getAcquisDir() . $formData['filepath'];
        unset($formData['filepath']);

        $config = new Config($this->getAcquisitionVersion());

        $arrayConfigs = $config->getConfigsByType('array');
        $stringConfigs = $config->getConfigsByType('string');
        $booleanConfigs = $config->getConfigsByType('boolean');
        $integerConfigs = $config->getConfigsByType('integer');
        $enumConfigs = $config->getConfigsByType('enum');
        $mapNames = $config->getMapNames();

        foreach ($formData as $key => $value) {
            if ($value) {
                $sourcePrefix = '/^([^_]+)_/';
                // Remove source from key
                $source = $this->extractSourcekey($key, $sourcePrefix);

                $finalKey = preg_replace($sourcePrefix, '', $key);
                $finalValue = null;
                if (in_array($finalKey, $arrayConfigs[$source] ?? [])) {
                    $finalValue = $this->formatValue($value, 'array');
                } elseif (
                    in_array(
                        $finalKey,
                        array_merge($stringConfigs[$source] ?? [], $enumConfigs[$source] ?? [])
                    )
                ) {
                    $finalValue = $this->formatValue($value);
                } elseif (in_array($finalKey, $booleanConfigs[$source] ?? [])) {
                    if (in_array($value, ['true', 'false'])) {
                        $finalValue = $this->formatValue($value, 'boolean');
                    }
                } elseif (in_array($finalKey, $integerConfigs[$source] ?? [])) {
                    $finalValue = $this->formatValue($value, 'integer');
                }

                if (!is_null($finalValue)) {
                    $this->processKeyValueForYaml($mapNames, $finalKey, $finalValue, $acquisitionData);
                }
            }
        }

        return $acquisitionData;
    }

    public function convertYamlToForm(array $acquisitionData): array
    {
        $formData = [];
        $filepath = $acquisitionData['filepath'] ?? '';
        $formData['filepath'] = str_replace($this->getAcquisDir(), '', $filepath);

        $config = new Config($this->getAcquisitionVersion());

        foreach (['array', 'string', 'boolean', 'integer', 'enum'] as $type) {
            $this->handleDataByType($acquisitionData, $config->getConfigsByType($type), $type, $formData);
        }

        return $formData;
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function deleteYamlAcquisitionByHash(string $hash, bool $forceReload = false): bool
    {
        try {
            $acquis = $forceReload ? $this->reloadYamlAcquisitionByHash($hash) : $this->getYamlAcquisitionByHash($hash);
            $filepath = $acquis['filepath'] ?? '';

            if (!$acquis || !$filepath) {
                return false;
            }
            $yamlContents = $this->getMultiYamlContent($filepath);
            $singleContent = (1 === count($yamlContents));

            return $singleContent ? unlink($filepath) :
                $this->processMultipleYamlContents($yamlContents, $filepath, $hash);
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return false;
        }
    }

    public function error(string $message): void
    {
        error_log('[CrowdSec Plugin log] ' . $message);
    }

    public function getAcquisDir(): string
    {
        if (null === $this->acquisDir) {
            $config = $this->getConfig();
            $this->acquisDir = \rtrim($config['crowdsec_service']['acquisition_dir'], '/') . '/';
        }

        return $this->acquisDir;
    }

    public function getAcquisFromYamls(): array
    {
        $filePath = $this->getAcquisPath();
        $allAcquis = [];
        $i = 0;
        $mainContents = $this->getMultiYamlContent($filePath);
        foreach ($mainContents as $mainContent) {
            $allAcquis[$i] = $this->getYamlContentFromString($mainContent);
            $allAcquis[$i]['filepath'] = $filePath;

            ++$i;
        }

        $overrideAcquis = $this->getOverrideAcquisFiles();

        foreach ($overrideAcquis as $acquisition) {
            $path = $acquisition['filepath'];
            foreach ($acquisition['content'] as $content) {
                $allAcquis[$i] = $this->getYamlContentFromString($content);
                $allAcquis[$i]['filepath'] = $path;
                ++$i;
            }
        }

        return $allAcquis;
    }

    public function getAcquisPath(): string
    {
        if (null === $this->acquisPath) {
            $config = $this->getConfig();
            $this->acquisPath = (string) $config['crowdsec_service']['acquisition_path'];
        }

        return $this->acquisPath;
    }

    public function getAcquisitionVersion(): string
    {
        return 'v1';
    }

    public function getMultiYamlContent($filepath): array
    {
        $contents = file_get_contents($filepath);

        if (false === $contents) {
            throw new \RuntimeException("Unable to read file: $filepath");
        }

        $multiFileContents = explode('---', $contents);

        // Remove values that are empty or contain only whitespace
        $multiFileContents = array_filter($multiFileContents, function ($value) {
            return '' !== trim($value);
        });

        return array_values($multiFileContents);
    }

    public function getYamlAcquisitionByHash(string $hash): array
    {
        if (!isset($this->yamlAcquisitionByHash[$hash])) {
            $this->yamlAcquisitionByHash[$hash] = $this->reloadYamlAcquisitionByHash($hash);
        }

        return $this->yamlAcquisitionByHash[$hash];
    }

    public function hash(array $array): string
    {
        ksort($array);
        array_walk_recursive($array, [$this, 'recursiveKsort']);

        return \hash('sha256', json_encode($array));
    }

    public function reloadYamlAcquisitionByHash(string $hash): array
    {
        $result = [];
        $allYamlAcquis = $this->getAcquisFromYamls();

        foreach ($allYamlAcquis as $yamlAcquis) {
            if ($hash === $this->hash($yamlAcquis)) {
                $result = $yamlAcquis;
                break;
            }
        }

        return $result;
    }

    public function upsertYamlAcquisitionByHash(string $hash, string $filepath, array $newContent): bool
    {
        try {
            $acquis = $this->getYamlAcquisitionByHash($hash);
            unset($newContent['filepath']);

            if (!$acquis) {
                return $this->writeYamlSingleContent($newContent, $filepath, \FILE_APPEND);
            }

            $filepath = $acquis['filepath'] ?? '';
            if (!$filepath) {
                return false;
            }

            $yamlContents = $this->getMultiYamlContent($filepath);
            $oldContents = $this->prepareOldContents($yamlContents, $filepath);

            return $this->editMultiYamlContent($filepath, $hash, $oldContents, $newContent);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Write content in a yaml file.
     */
    public function writeYamlSingleContent(array $content, string $filepath, int $flags = 0): bool
    {
        try {
            $yaml = Yaml::dump($content, 4);
            $folder = pathinfo($filepath, \PATHINFO_DIRNAME);
            if (!is_dir($folder)) {
                mkdir($folder, 0755, true);
            }
            if (file_exists($filepath) && \FILE_APPEND === $flags) {
                $yaml = "---\n" . $yaml;
            }

            return (bool) file_put_contents($filepath, $yaml, $flags);
        } catch (DumpException $e) {
            $this->error('Unable to dump ' . $filepath . ': ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->error('Unable write single content ' . $filepath . ': ' . $e->getMessage());
        }

        return false;
    }

    private function editMultiYamlContent(
        string $filepath,
        string $hash,
        array $oldContents,
        array $newContent = []
    ): bool {
        $keyToEdit = $this->findKeyToEdit($oldContents, $hash);

        if (null === $keyToEdit) {
            return false;
        }

        $newContents = $oldContents;
        $newContents[$keyToEdit] = $newContent ?: $newContents[$keyToEdit];
        if (!$newContent) {
            unset($newContents[$keyToEdit]);
        }

        return $this->overwriteYamlMultipleContents($newContents, $filepath);
    }

    private function extractSourcekey(string $key, string $sourcePrefix): string
    {
        $source = '';
        if (preg_match($sourcePrefix, $key, $matches)) {
            $source = $matches[1];
        }

        return $source;
    }

    private function findKeyToEdit(array $oldContents, string $hash): ?int
    {
        foreach ($oldContents as $key => $oldContent) {
            if ($hash === $this->hash($oldContent)) {
                return $key;
            }
        }

        return null;
    }

    private function formatDataByType($type, $data)
    {
        $formatFunctions = [
            'boolean' => function ($data) {
                return $data ? 'true' : 'false';
            },
            'array' => function ($data) {
                return is_array($data) ? implode(\PHP_EOL, $data) : $data;
            },
            'default' => function ($data) {
                return $data;
            },
        ];

        return ($formatFunctions[$type] ?? $formatFunctions['default'])($data);
    }

    private function formatValue($value, string $configType = 'string')
    {
        switch ($configType) {
            case 'boolean':
                return 'true' === $value;
            case 'integer':
                return (int) $value;
            case 'array':
                return explode(\PHP_EOL, $value);
            default:
                return $value;
        }
    }

    private function getConfig(): array
    {
        if (!$this->configContent) {
            $this->configContent = $this->getYamlContent($this->configPath);
        }

        return $this->configContent;
    }

    private function getOverrideAcquisFiles(): array
    {
        $acquisDir = $this->getAcquisDir();
        $foundFiles = glob($acquisDir . '*yaml');
        $acquisFiles = [];

        foreach ($foundFiles as $filePath) {
            $acquisFiles[] = ['filepath' => $filePath, 'content' => $this->getMultiYamlContent($filePath)];
        }

        return $acquisFiles;
    }

    /**
     * Retrieve yaml content as an array.
     *
     * @return array|mixed
     */
    private function getYamlContent($filepath): array
    {
        $result = [];

        try {
            $result = Yaml::parseFile($filepath);
        } catch (ParseException $exception) {
            $this->error('Unable to parse ' . $filepath . ': ' . $exception->getMessage());
        }

        return $result;
    }

    private function getYamlContentFromString($value)
    {
        $result = [];

        try {
            $parser = new Parser();
            $result = $parser->parse($value);
        } catch (ParseException $exception) {
            $this->error('Unable to parse string' . $value . ': ' . $exception->getMessage());
        }

        return $result;
    }

    private function handleDataByType(array $acquisitionData, array $configs, string $type, array &$formData)
    {
        $config = new Config($this->getAcquisitionVersion());
        $mapNames = $config->getMapNames();

        foreach ($acquisitionData as $key => $data) {
            foreach ($configs as $source => $sourceConfigs) {
                $this->processKeyData($key, $data, $type, $source, $sourceConfigs, $formData, $mapNames);
            }
        }
    }

    private function overwriteYamlMultipleContents(array $contents, string $filepath): bool
    {
        try {
            $folder = pathinfo($filepath, \PATHINFO_DIRNAME);
            if (!is_dir($folder)) {
                mkdir($folder, 0755, true);
            }
            $i = 0;
            $count = count($contents);
            $yaml = '';
            foreach ($contents as $content) {
                if ($i > 0 && $i < $count + 1) {
                    $yaml .= "---\n";
                }
                unset($content['filepath']);
                $yaml .= Yaml::dump($content, 4);
                ++$i;
            }

            return $yaml && file_put_contents($filepath, $yaml);
        } catch (\Exception $exception) {
            $this->error('Unable overwrite ' . $filepath . ': ' . $exception->getMessage());
        }

        return false;
    }

    private function prepareOldContents(array $yamlContents, string $filepath): array
    {
        return array_map(function ($yamlContent) use ($filepath) {
            $content = $this->getYamlContentFromString($yamlContent);
            $content['filepath'] = $filepath;

            return $content;
        }, $yamlContents);
    }

    private function processArrayData($key, $data, $type, $source, $sourceConfigs, &$formData)
    {
        foreach ($data as $k => $v) {
            $compositeKey = $key . '_' . $k;
            if (in_array($compositeKey, $sourceConfigs)) {
                $formData[$source . '_' . $compositeKey] = $this->formatDataByType($type, $v);
            }
        }
    }

    private function processKeyData($key, $data, $type, $source, $sourceConfigs, &$formData, $mapNames)
    {
        if (in_array($key, $mapNames) && is_array($data)) {
            $this->processArrayData($key, $data, $type, $source, $sourceConfigs, $formData);
        }

        if (in_array($key, $sourceConfigs)) {
            $formData[$source . '_' . $key] = $this->formatDataByType($type, $data);
        }
    }

    private function processKeyValueForYaml(
        array $mapNames,
        string $key,
        $value,
        array &$acquisitionData
    ): void {
        $map = false;
        foreach ($mapNames as $mapName) {
            if (0 === strpos($key, $mapName . '_')) {
                $parts = explode('_', $key, 2);
                $acquisitionData[$mapName][$parts[1]] = $value;

                $map = true;
                break;
            }
        }
        if (!$map) {
            $acquisitionData[$key] = $value;
        }
    }

    private function processMultipleYamlContents(array $yamlContents, string $filepath, string $hash): bool
    {
        $oldContents = array_map(function ($yamlContent) use ($filepath) {
            $content = $this->getYamlContentFromString($yamlContent);
            $content['filepath'] = $filepath;

            return $content;
        }, $yamlContents);

        return $this->editMultiYamlContent($filepath, $hash, $oldContents);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function recursiveKsort(&$item)
    {
        if (is_array($item)) {
            ksort($item);
        }
    }

    private function validateFilepath(array $formData): void
    {
        if (empty($formData['filepath'])) {
            throw new Exception('Filepath is required');
        }
    }
}