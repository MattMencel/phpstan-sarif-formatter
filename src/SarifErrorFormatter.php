<?php

declare(strict_types=1);

namespace PHPStanSarifErrorFormatter;

use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use Nette\Utils\Json;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use PHPStan\File\RelativePathHelper;
use PHPStan\Internal\ComposerHelper;

class SarifErrorFormatter implements ErrorFormatter
{
    private const URI_BASE_ID = 'WORKINGDIR';

    // Declare properties
    private RelativePathHelper $relativePathHelper;
    private string $currentWorkingDirectory;
    private bool $pretty;

    public function __construct(
        RelativePathHelper $relativePathHelper,
        string $currentWorkingDirectory,
        bool $pretty
    ) {
        // Initialize properties in the constructor
        $this->relativePathHelper = $relativePathHelper;
        $this->currentWorkingDirectory = $currentWorkingDirectory;
        $this->pretty = $pretty;
    }

    // Check for PHP version and modify constructor accordingly
    public function __construct(...$args)
    {
        if (version_compare(PHP_VERSION, '8.0.0') >= 0) {
            // PHP 8.0 or higher
            [$this->relativePathHelper, $this->currentWorkingDirectory, $this->pretty] = $args;
        } else {
            // PHP 7.x
            [$relativePathHelper, $currentWorkingDirectory, $pretty] = $args;
            $this->relativePathHelper = $relativePathHelper;
            $this->currentWorkingDirectory = $currentWorkingDirectory;
            $this->pretty = $pretty;
        }
    }

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $phpstanVersion = ComposerHelper::getPhpStanVersion();

        $tool = [
            'driver' => [
                'name' => 'PHPStan',
                'fullName' => 'PHP Static Analysis Tool',
                'informationUri' => 'https://phpstan.org',
                'version' => $phpstanVersion,
                'semanticVersion' => $phpstanVersion,
                'rules' => [],
            ],
        ];

        $originalUriBaseIds = [
            self::URI_BASE_ID => [
                'uri' => 'file://' . $this->currentWorkingDirectory . '/',
            ],
        ];

        $results = [];

        foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
            $result = [
                'level' => 'error',
                'message' => [
                    'text' => $fileSpecificError->getMessage(),
                ],
                'locations' => [
                    [
                        'physicalLocation' => [
                            'artifactLocation' => [
                                'uri' => $this->relativePathHelper->getRelativePath($fileSpecificError->getFile()),
                                'uriBaseId' => self::URI_BASE_ID,
                            ],
                            'region' => [
                                'startLine' => $fileSpecificError->getLine(),
                            ],
                        ],
                    ],
                ],
                'properties' => [
                    'ignorable' => $fileSpecificError->canBeIgnored(),
                    // 'identifier' => $fileSpecificError->getIdentifier(),
                    // 'metadata' => $fileSpecificError->getMetadata(),
                ],
            ];

            if ($fileSpecificError->getTip() !== null) {
                $result['properties']['tip'] = $fileSpecificError->getTip();
            }

            $results[] = $result;
        }

        foreach ($analysisResult->getNotFileSpecificErrors() as $notFileSpecificError) {
            $results[] = [
                'level' => 'error',
                'message' => [
                    'text' => $notFileSpecificError,
                ],
            ];
        }

        foreach ($analysisResult->getWarnings() as $warning) {
            $results[] = [
                'level' => 'warning',
                'message' => [
                    'text' => $warning,
                ],
            ];
        }

        $sarif = [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [
                [
                    'tool' => $tool,
                    'originalUriBaseIds' => $originalUriBaseIds,
                    'results' => $results,
                ],
            ],
        ];

        $json = Json::encode($sarif, $this->pretty ? Json::PRETTY : 0);

        $output->writeRaw($json);

        return $analysisResult->hasErrors() ? 1 : 0;
    }
}
