<?php

use Symfony\Component\Yaml\Parser;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

define('DS', DIRECTORY_SEPARATOR);
define('APPLICATION_SOURCE_DIR', __DIR__ . DS . 'src');
include_once __DIR__ . DS . 'vendor' . DS . 'autoload.php';

$deploymentDir = getenv('SPRYKER_DOCKER_SDK_DEPLOYMENT_DIR') ?: '/tmp';
$projectYaml = getenv('SPRYKER_DOCKER_SDK_PROJECT_YAML') ?: '';
$projectName = getenv('SPRYKER_DOCKER_SDK_PROJECT_NAME') ?: '';
$platform = getenv('SPRYKER_DOCKER_SDK_PLATFORM') ?: 'linux'; // Possible values: linux windows macos

$loader = new FilesystemLoader(APPLICATION_SOURCE_DIR . DS . 'templates');
$twig = new Environment($loader);
$yamlParser = new Parser();

$projectData = $yamlParser->parseFile($projectYaml);

$projectData['_knownHosts'] = buildKnownHosts($deploymentDir);
$projectData['_projectName'] = $projectName;
$projectData['tag'] = $projectData['tag'] ?? uniqid();
$projectData['_platform'] = $platform;
$mountMode = $projectData['_mountMode'] = retrieveMountMode($projectData, $platform);
$projectData['_ports'] = retrieveUniquePorts($projectData);
$defaultPort = $projectData['_defaultPort'] = getDefaultPort($projectData);
$endpointMap = $projectData['_endpointMap'] = buildEndpointMapByStore($projectData['groups']);
$projectData['_phpExtensions'] = buildPhpExtensionList($projectData);
$projectData['_phpIni'] = buildPhpIniAdditionalConfig($projectData);
$projectData['_envs'] = array_merge(
    getAdditionalEnvVariables($projectData),
    buildNewrelicEnvVariables($projectData)
);
$projectData['storageData'] = retrieveStorageData($projectData);
$projectData['composer']['autoload'] = buildComposerAutoloadConfig($projectData);
$isAutoloadCacheEnabled = $projectData['_isAutoloadCacheEnabled'] = isAutoloadCacheEnabled($projectData);
$projectData['_requirementAnalyzerData'] = buildDataForRequirementAnalyzer($projectData);

mkdir($deploymentDir . DS . 'env' . DS . 'cli', 0777, true);
mkdir($deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'conf.d', 0777, true);
mkdir($deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'vhost.d', 0777, true);
mkdir($deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'stream.d', 0777, true);
mkdir($deploymentDir . DS . 'images' . DS. 'main', 0777, true);

file_put_contents(
    $deploymentDir . DS . 'images' . DS. 'main' . DS .  'Dockerfile',
    $twig->render('images/main/Dockerfile.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'conf.d' . DS . 'front-end.default.conf',
    $twig->render('nginx/conf.d/front-end.default.conf.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'stream.d' . DS . 'front-end.default.conf',
    $twig->render('nginx/stream.d/front-end.default.conf.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'vhost.d' . DS . 'zed.default.conf',
    $twig->render('nginx/vhost.d/zed.default.conf.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'vhost.d' . DS . 'yves.default.conf',
    $twig->render('nginx/vhost.d/yves.default.conf.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'vhost.d' . DS . 'glue.default.conf',
    $twig->render('nginx/vhost.d/glue.default.conf.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'vhost.d' . DS . 'ssl.default.conf',
    $twig->render('nginx/vhost.d/ssl.default.conf.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'conf.d' . DS . 'zed-rpc.default.conf',
    $twig->render('nginx/conf.d/zed-rpc.default.conf.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'context' . DS . 'php' . DS . 'conf.d' . DS . '99-from-deploy-yaml-php.ini',
    $twig->render('php/conf.d/99-from-deploy-yaml-php.ini.twig', $projectData)
);
foreach ($projectData['groups'] ?? [] as $groupName => $groupData) {
    foreach ($groupData['applications'] ?? [] as $applicationName => $applicationData) {
        file_put_contents(
            $deploymentDir . DS . 'env' . DS . $applicationName . '.env',
            $twig->render(sprintf('env/application/%s.env.twig', $applicationData['application']), [
                'applicationName' => $applicationName,
                'applicationData' => $applicationData,
                'project' => $projectData,
                'regionName' => $groupData['region'],
                'regionData' => $projectData['regions'][$groupData['region']],
                'brokerConnections' => getBrokerConnections($projectData),
            ])
        );

        if ($applicationData['application'] === 'zed') {
            foreach ($applicationData['endpoints'] ?? [] as $endpoint => $endpointData) {
                file_put_contents(
                    $deploymentDir . DS . 'env' . DS . 'cli' . DS . strtolower($endpointData['store']) . '.env',
                    $twig->render('env/cli/store.env.twig', [
                        'applicationName' => $applicationName,
                        'applicationData' => $applicationData,
                        'project' => $projectData,
                        'regionName' => $groupData['region'],
                        'regionData' => $projectData['regions'][$groupData['region']],
                        'brokerConnections' => getBrokerConnections($projectData),
                        'storeName' => $endpointData['store'],
                        'services' => array_replace_recursive(
                            $projectData['regions'][$groupData['region']]['stores'][$endpointData['store']]['services'],
                            $endpointData['services'] ?? []
                        ),
                        'endpointMap' => $endpointMap,
                    ])
                );
            }
        }

        if ($applicationData['application'] === 'yves') {
            foreach ($applicationData['endpoints'] ?? [] as $endpoint => $endpointData) {
                if ($endpointData['store'] !== ($projectData['docker']['testing']['store'] ?? '')) {
                    continue;
                }
                file_put_contents(
                    $deploymentDir . DS . 'env' . DS . 'cli' . DS . 'testing.env',
                    $twig->render('env/cli/testing.env.twig', [
                        'applicationName' => $applicationName,
                        'applicationData' => $applicationData,
                        'project' => $projectData,
                        'host' => strtok($endpoint, ':'),
                        'port' => strtok($endpoint) ?: $defaultPort,
                        'regionName' => $groupData['region'],
                        'regionData' => $projectData['regions'][$groupData['region']],
                        'brokerConnections' => getBrokerConnections($projectData),
                        'storeName' => $endpointData['store'],
                        'services' => array_replace_recursive(
                            $projectData['regions'][$groupData['region']]['stores'][$endpointData['store']]['services'],
                            $endpointData['services'] ?? []
                        ),
                        'endpointMap' => $endpointMap,
                    ])
                );
            }
        }
    }
}
file_put_contents(
    $deploymentDir . DS . 'env' . DS . 'testing.env',
    $twig->render('env/testing.env.twig', [
        'project' => $projectData,
    ])
);

file_put_contents(
    $deploymentDir . DS . 'env' . DS . 'swagger.env',
    $twig->render('env/swagger/swagger-ui.env.twig', [
        'project' => $projectData,
        'endpointMap' => $endpointMap,
    ])
);

file_put_contents(
    $deploymentDir . DS . 'docker-compose.yml',
    $twig->render('docker-compose.yml.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'docker-compose.xdebug.yml',
    $twig->render('docker-compose.xdebug.yml.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'docker-compose.test.yml',
    $twig->render('docker-compose.test.yml.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'docker-compose.test.xdebug.yml',
    $twig->render('docker-compose.test.xdebug.yml.twig', $projectData)
);
file_put_contents(
    $deploymentDir . DS . 'deploy',
    $twig->render('deploy.bash.twig', $projectData)
);

switch ($mountMode) {
    case 'docker-sync':
        file_put_contents(
            $deploymentDir . DS . 'docker-sync.yml',
            $twig->render('docker-sync.yml.twig', $projectData)
        );
        break;
}

$sslDir = $deploymentDir . DS . 'context' . DS . 'nginx' . DS . 'ssl';
mkdir($sslDir);
echo shell_exec(sprintf(
    'PFX_PASSWORD="%s" DESTINATION=%s ./openssl/generate.sh %s',
    addslashes($projectData['docker']['ssl']['pfx-password'] ?? 'secret'),
    $sslDir,
    implode(' ', retrieveHostNames($projectData))
));

copy($sslDir . DS . 'ca.pfx', $deploymentDir . DS . 'spryker.pfx');

// -------------------------
/**
 * @param array $projectData
 * @param string $platform
 *
 * @throws \Exception
 *
 * @return string
 */
function retrieveMountMode(array $projectData, string $platform): string
{
    $mountMode = 'baked';
    foreach ($projectData['docker']['mount'] ?? [] as $engine => $configuration) {
        if (in_array($platform, $configuration['platforms'] ?? [$platform], true)) {
            $mountMode = $engine;
            break;
        }
        $mountMode = '';
    }

    if ($mountMode === '') {
        throw new Exception(sprintf('Mount mode cannot be determined for `%s` platform', $platform));
    }

    return $mountMode;
}

/**
 * @param array $projectData
 *
 * @return int[]
 */
function retrieveUniquePorts(array $projectData)
{
    $ports = [];

    foreach (retrieveEndpoints($projectData) as $endpoint => $endpointData) {
        $port = explode(':', $endpoint)[1];
        $ports[$port] = $port;
    }

    return $ports;
}

/**
 * @param array $projectData
 *
 * @throws \Exception
 *
 * @return array[]
 */
function retrieveEndpoints(array $projectData): array
{
    $defaultPort = getDefaultPort($projectData);

    $endpoints = [];

    foreach ($projectData['groups'] ?? [] as $groupName => $groupData) {
        foreach ($groupData['applications'] ?? [] as $applicationName => $applicationData) {
            foreach ($applicationData['endpoints'] ?? [] as $endpoint => $endpointData) {
                if (strpos($endpoint, ':') === false) {
                    $endpoint .= ':' . $defaultPort;
                }

                if (array_key_exists($endpoint, $endpoints)) {
                    throw new Exception(sprintf(
                        '`%s` endpoint is used for different applications. Please, make sure endpoints are unique',
                        $endpoint
                    ));
                }

                $endpointData['region'] = $groupData['region'];
                $endpointData['application'] = $applicationName;
                $endpoints[$endpoint] = $endpointData;
            }
        }
    }

    foreach ($projectData['services'] as $serviceName => $serviceData) {
        foreach ($serviceData['endpoints'] ?? [] as $endpoint => $endpointData) {
            if (strpos($endpoint, ':') === false) {
                $endpoint .= ':' . $defaultPort;
            }

            if (array_key_exists($endpoint, $endpoints)) {
                throw new Exception(sprintf(
                    '`%s` endpoint is used for different applications. Please, make sure endpoints are unique',
                    $endpoint
                ));
            }

            $endpointData['service'] = $serviceName;
            $endpoints[$endpoint] = $endpointData;
        }
    }

    return $endpoints;
}

/**
 * @param array $projectData
 *
 * @return string[]
 */
function retrieveHostNames(array $projectData): array
{
    $hosts = [];

    foreach (retrieveEndpoints($projectData) as $endpoint => $endpointData) {
        $host = strtok($endpoint, ':');
        $hosts[$host] = $host;
    }

    return $hosts;
}

/**
 * @param array $projectData
 *
 * @return int
 */
function getDefaultPort(array $projectData): int
{
    $sslEnabled = $projectData['docker']['ssl']['enabled'] ?? false;

    return $sslEnabled ? 443 : 80;
}

/**
 * @param array $projectData
 *
 * @return string
 */
function getBrokerConnections(array $projectData): string
{
    $brokerServiceData = $projectData['services']['broker'];

    $connections = [];
    foreach ($projectData['regions'] as $regionName => $regionData) {
        foreach ($regionData['stores'] ?? [] as $storeName => $storeData) {
            $localServiceData = array_replace($brokerServiceData, $storeData['services']['broker']);
            $connections[$storeName] = [
                'RABBITMQ_CONNECTION_NAME' => $storeName . '-connection',
                'RABBITMQ_HOST' => 'broker',
                'RABBITMQ_PORT' => $localServiceData['port'] ?? 5672,
                'RABBITMQ_USERNAME' => $localServiceData['api']['username'],
                'RABBITMQ_PASSWORD' => $localServiceData['api']['password'],
                'RABBITMQ_VIRTUAL_HOST' => $localServiceData['namespace'],
                'RABBITMQ_STORE_NAMES' => [$storeName], // check if connection is shared
            ];
        }
    }

    return json_encode($connections);
}

/**
 * @param array $projectGroups
 *
 * @return string[]
 */
function buildEndpointMapByStore(array $projectGroups): array
{
    $endpointMap = [];

    foreach ($projectGroups as $projectGroup) {
        $applicationsPerRegion = $projectGroup['applications'];

        foreach ($applicationsPerRegion as $application) {
            $applicationName = $application['application'];

            foreach ($application['endpoints'] as $endpoint => $endpointData) {
                $storeName = $endpointData['store'];
                $endpointMap[$storeName][$applicationName] = $endpoint;
            }
        }
    }

    return $endpointMap;
}

/**
 * @param string $deploymentDir
 *
 * @return string
 */
function buildKnownHosts(string $deploymentDir): string
{
    $knownHostsPath = $deploymentDir . DS . '.known_hosts';

    if (!file_exists($knownHostsPath)) {
        return '';
    }

    return implode(
        ' ',
        getKnownHosts($knownHostsPath)
    );
}

/**
 * @param string $knownHostsYamlPath
 *
 * @return array
 */
function getKnownHosts(string $knownHostsYamlPath): array
{
    $knownHosts = file_get_contents($knownHostsYamlPath);

    if (!$knownHosts) {
        return [];
    }

    return array_filter(
        preg_split('/[\s]+/', $knownHosts),
        function ($knownHost) {
            return $knownHost && isHostValid($knownHost);
        }
    );
}

/**
 * @param string $knownHost
 *
 * @return bool
 */
function isHostValid(string $knownHost): bool
{
    return isIp($knownHost) || isHost($knownHost);
}

/**
 * @param string $knownHost
 *
 * @return bool
 */
function isIp(string $knownHost): bool
{
    $validIpAddressPattern = "/^(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/";

    if (!preg_match($validIpAddressPattern, $knownHost)) {
        return false;
    }

    return true;
}

/**
 * @param string $knownHost
 *
 * @return bool
 */
function isHost(string $knownHost): bool
{
    $validHostnamePattern = "/^(([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9])\.)*([A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9\-]*[A-Za-z0-9])$/";

    if (!preg_match($validHostnamePattern, $knownHost)) {
        return false;
    }

    $ipAddress = gethostbyname($knownHost);

    if ($ipAddress === $knownHost) {
        return false;
    }

    return true;
}

/**
 * @param array $projectData
 *
 * @return string[]
 */
function buildNewrelicEnvVariables(array $projectData): array
{
    if (!in_array('newrelic', $projectData['_phpExtensions'])) {
        return [];
    }

    $newrelicEnvVariables = [
        'NEWRELIC_ENABLED' => 1,
        'NEWRELIC_LICENSE' => '',
    ];

    if (empty($projectData['docker']['newrelic'])) {
        return $newrelicEnvVariables;
    }

    foreach ($projectData['docker']['newrelic'] as $key => $value) {
        $newrelicEnvVariables['NEWRELIC_' . strtoupper($key)] = $value;
    }

    return $newrelicEnvVariables;
}

/**
 * @param array $projectData
 *
 * @return array
 */
function buildPhpIniAdditionalConfig(array $projectData): array
{
    $additionalPhpConfiguration = $projectData['image']['php']['ini'] ?? [];

    if (!$additionalPhpConfiguration) {
        return $additionalPhpConfiguration;
    }

    $formattedAdditionalPhpConfiguration = [];

    foreach ($additionalPhpConfiguration as $key => $value) {
        $formattedAdditionalPhpConfiguration[] = sprintf(
            '%s = %s',
            $key,
            toString($value)
        );
    }

    return $formattedAdditionalPhpConfiguration;
}

/**
 * @param array $projectData
 *
 * @return array
 */
function buildPhpExtensionList(array $projectData): array
{
    return $projectData['image']['php']['enabled-extensions'] ?? [];
}

/**
 * @param array $projectData
 *
 * @return array
 */
function getAdditionalEnvVariables(array $projectData): array
{
    return $projectData['image']['environment'] ?? [];
}

/**
 * @param $value
 *
 * @return string
 */
function toString($value): string
{
    if (!is_bool($value)) {
        return (string)$value;
    }

    return $value ? 'true' : 'false';
}

/**
 * @param array $projectData
 *
 * @return array
 */
function retrieveStorageData(array $projectData): array
{
    $storageServices = retrieveStorageServices($projectData['services']);
    $regionsStorageHosts = retrieveRegionsStorageHosts($projectData['regions'], $storageServices);
    $groupsStorageHosts = retrieveGroupsStorageHosts($projectData['groups'], $storageServices);

    return [
        'hosts' => array_merge($regionsStorageHosts, $groupsStorageHosts),
        'services' => $storageServices,
    ];
}

/**
 * @param array $services
 * @param string $engine
 *
 * @return string[]
 */
function retrieveStorageServices(array $services, string $engine = 'redis'): array
{
    $storageServices = [];
    foreach ($services as $serviceName => $serviceData) {
        if ($serviceData['engine'] === $engine) {
            $storageServices[] = $serviceName;
        }
    }

    return $storageServices;
}

/**
 * @param array $regions
 * @param string[] $storageServices
 * @param int $defaultPort
 *
 * @return array
 */
function retrieveRegionsStorageHosts(array $regions, array $storageServices, int $defaultPort = 6379): array
{
    $regionsStorageHosts = [];
    foreach ($regions ?? [] as $regionName => $regionData) {
        foreach ($regionData['stores'] as $storeData) {
            foreach ($storeData['services'] ?? [] as $serviceName => $serviceNamespace) {
                if (in_array($serviceName, $storageServices)) {
                    $regionsStorageHosts[] = sprintf('%s:%s:%s:%s', $serviceName, $serviceName, $defaultPort, $serviceNamespace['namespace']);
                }
            }
        }
    }

    return $regionsStorageHosts;
}

/**
 * @param array $groups
 * @param string[] $storageServices
 * @param int $defaultPort
 *
 * @return array
 */
function retrieveGroupsStorageHosts(array $groups, array $storageServices, int $defaultPort = 6379): array
{
    $groupsStorageHosts = [];
    foreach ($groups ?? [] as $groupName => $groupData) {
        foreach ($groupData['applications'] as $application) {
            foreach ($application['endpoints'] as $endpoint => $endpointData) {
                foreach ($endpointData['services'] ?? [] as $serviceName => $serviceData) {
                    if (in_array($serviceName, $storageServices)) {
                        $groupsStorageHosts[] = sprintf('%s:%s:%s:%s', $serviceName, $serviceName, $defaultPort, $serviceData['namespace']);
                    }
                }
            }
        }
    }

    return $groupsStorageHosts;
}

/**
 * @param array $projectData
 *
 * @return bool
 */
function isAutoloadCacheEnabled(array $projectData): bool
{
    if ($projectData['composer']['autoload'] !== '') {
        return false;
    }

    return $projectData['docker']['cache']['autoload']['enabled'] ?? false;
}

/**
 * @param array $projectData
 *
 * @return string
 */
function buildComposerAutoloadConfig(array $projectData): string
{
    return trim($projectData['composer']['autoload'] ?? '--optimize');
}

/**
 * @param array $projectData
 *
 * @return array
 */
function buildUniqueEndpointMap(array $projectData): array
{
    $endpointMap = [];
    $projectEndpointMap = $projectData['_endpointMap'] ?? buildEndpointMapByStore($projectData['groups']);
    $services = $projectData['services'] ?? [];

    if (empty($projectEndpointMap) && empty($services)) {
        return $endpointMap;
    }

    foreach ($projectEndpointMap as $storeName => $endpointMapPerStore) {
        $endpointMap[] = array_values($endpointMapPerStore);
    }

    foreach ($services as $serviceName => $serviceConfig) {
        if (!isset($serviceConfig['endpoints'])) {
            continue;
        }

        foreach ($serviceConfig['endpoints'] as $endpoint => $endpointConfig) {
            if (strpos($endpoint, 'localhost') === false) {
                $endpointMap[] = [$endpoint];
            }
        }
    }

    return array_unique(array_merge(...$endpointMap));
}

function buildDataForRequirementAnalyzer(array $projectData): array
{
    $endpointMap = buildUniqueEndpointMap($projectData);
    sort($endpointMap);

    return [
        'hosts' => implode(' ', $endpointMap),
        'ipAddress' => '127.0.0.1',
    ];
}
