<?php declare(strict_types=1);

namespace OpenSearch\Migrations\Tests\Integration;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Config\Repository;
use OpenSearch\Client;
use OpenSearch\EndpointFactory;
use OpenSearch\Laravel\Client\ServiceProvider as ClientServiceProvider;
use OpenSearch\Migrations\ServiceProvider as MigrationsServiceProvider;
use OpenSearch\RequestFactory;
use OpenSearch\Serializers\SmartSerializer;
use OpenSearch\TransportFactory;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use Psr\Http\Client\ClientInterface;

class TestCase extends TestbenchTestCase
{
    protected Repository $config;

    protected function getPackageProviders($app): array
    {
        return [
            MigrationsServiceProvider::class,
            ClientServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $this->config = $app['config'];
        $this->config->set('opensearch.migrations.database.table', 'test_opensearch_migrations');
        $this->config->set('opensearch.migrations.storage.default_path', realpath(__DIR__ . '/../migrations'));

        $app->singleton(Client::class, function () {
            $httpClient = $this->createStub(ClientInterface::class);
            $serializer = new SmartSerializer();
            $httpFactory = new HttpFactory();

            $transport = (new TransportFactory())
                ->setHttpClient($httpClient)
                ->setRequestFactory(new RequestFactory($httpFactory, $httpFactory, $httpFactory, $serializer))
                ->create();

            return new Client($transport, new EndpointFactory($serializer), []);
        });
    }
}
