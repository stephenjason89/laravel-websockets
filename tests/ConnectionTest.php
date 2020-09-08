<?php

namespace BeyondCode\LaravelWebSockets\Tests;

use BeyondCode\LaravelWebSockets\Apps\App;
use BeyondCode\LaravelWebSockets\Tests\Mocks\Message;
use BeyondCode\LaravelWebSockets\WebSockets\Exceptions\ConnectionsOverCapacity;
use BeyondCode\LaravelWebSockets\WebSockets\Exceptions\OriginNotAllowed;
use BeyondCode\LaravelWebSockets\WebSockets\Exceptions\UnknownAppKey;

class ConnectionTest extends TestCase
{
    /** @test */
    public function unknown_app_keys_can_not_connect()
    {
        $this->expectException(UnknownAppKey::class);

        $this->pusherServer->onOpen($this->getWebSocketConnection('test'));
    }

    /** @test */
    public function known_app_keys_can_connect()
    {
        $connection = $this->getWebSocketConnection();

        $this->pusherServer->onOpen($connection);

        $connection->assertSentEvent('pusher:connection_established');
    }

    /** @test */
    public function known_app_keys_can_connect_on_redis_replication()
    {
        $this->runOnlyOnRedisReplication();

        $this->redis->hdel('laravel_database_1234', 'connections');

        $this->app['config']->set('websockets.apps.0.capacity', 2);

        $this->getConnectedWebSocketConnection(['test-channel']);
        $this->getConnectedWebSocketConnection(['test-channel']);

        $this->getSubscribeClient()
            ->assertCalledWithArgs('subscribe', [$this->replicator->getTopicName('1234')])
            ->assertCalledWithArgs('subscribe', [$this->replicator->getTopicName('1234', 'test-channel')]);

        $this->getPublishClient()
            ->hget($this->replicator->getTopicName('1234'), 'connections')
            ->then(function ($count) {
                $this->assertEquals(2, $count);
            });

        $this->getPublishClient()
            ->hget($this->replicator->getTopicName('1234'), 'peak_connection_count')
            ->then(function ($count) {
                $this->assertEquals(2, $count);
            });

        $this->getPublishClient()
            ->hget($this->replicator->getTopicName('1234'), 'websocket_message_count')
            ->then(function ($count) {
                $this->assertEquals(2, $count);
            });

        $this->getPublishClient()
            ->smembers('laravel-websockets:apps')
            ->then(function ($members) {
                $this->assertEquals(['1234'], $members);
            });

        $failedConnection = $this->getConnectedWebSocketConnection(['test-channel']);

        $failedConnection
            ->assertSentEvent('pusher:error', ['data' => ['message' => 'Over capacity', 'code' => 4100]])
            ->assertClosed();
    }

    /** @test */
    public function redis_tracks_app_connections_count_on_disconnect()
    {
        $this->runOnlyOnRedisReplication();

        $connection = $this->getWebSocketConnection();

        $this->pusherServer->onOpen($connection);

        $this->getPublishClient()
            ->hget($this->replicator->getTopicName('1234'), 'connections')
            ->then(function ($count) {
                $this->assertEquals(1, $count);
            });

        $this->getPublishClient()
            ->hget($this->replicator->getTopicName('1234'), 'peak_connection_count')
            ->then(function ($count) {
                $this->assertEquals(1, $count);
            });

        $this->getPublishClient()
            ->hget($this->replicator->getTopicName('1234'), 'websocket_message_count')
            ->then(function ($count) {
                $this->assertEquals(null, $count);
            });

        $this->pusherServer->onClose($connection);

        $this->getPublishClient()
            ->hget($this->replicator->getTopicName('1234'), 'connections')
            ->then(function ($count) {
                $this->assertEquals(0, $count);
            });

        $this->getPublishClient()
            ->hget($this->replicator->getTopicName('1234'), 'peak_connection_count')
            ->then(function ($count) {
                $this->assertEquals(1, $count);
            });

        $this->getPublishClient()
            ->hget($this->replicator->getTopicName('1234'), 'websocket_message_count')
            ->then(function ($count) {
                $this->assertEquals(null, $count);
            });
    }

    /** @test */
    public function app_can_not_exceed_maximum_capacity()
    {
        $this->runOnlyOnLocalReplication();

        $this->app['config']->set('websockets.apps.0.capacity', 2);

        $this->getConnectedWebSocketConnection(['test-channel']);
        $this->getConnectedWebSocketConnection(['test-channel']);
        $this->expectException(ConnectionsOverCapacity::class);
        $this->getConnectedWebSocketConnection(['test-channel']);
    }

    /** @test */
    public function app_can_not_exceed_maximum_capacity_on_redis_replication()
    {
        $this->runOnlyOnRedisReplication();

        $this->redis->hdel('laravel_database_1234', 'connections');

        $this->app['config']->set('websockets.apps.0.capacity', 2);

        $this->getConnectedWebSocketConnection(['test-channel']);
        $this->getConnectedWebSocketConnection(['test-channel']);

        $failedConnection = $this->getConnectedWebSocketConnection(['test-channel']);

        $failedConnection
            ->assertSentEvent('pusher:error', ['data' => ['message' => 'Over capacity', 'code' => 4100]])
            ->assertClosed();
    }

    /** @test */
    public function successful_connections_have_the_app_attached()
    {
        $connection = $this->getWebSocketConnection();

        $this->pusherServer->onOpen($connection);

        $this->assertInstanceOf(App::class, $connection->app);
        $this->assertSame('1234', $connection->app->id);
        $this->assertSame('TestKey', $connection->app->key);
        $this->assertSame('TestSecret', $connection->app->secret);
        $this->assertSame('Test App', $connection->app->name);
    }

    /** @test */
    public function ping_returns_pong()
    {
        $connection = $this->getWebSocketConnection();

        $message = new Message(['event' => 'pusher:ping']);

        $this->pusherServer->onOpen($connection);

        $this->pusherServer->onMessage($connection, $message);

        $connection->assertSentEvent('pusher:pong');
    }

    /** @test */
    public function origin_validation_should_fail_for_no_origin()
    {
        $this->expectException(OriginNotAllowed::class);

        $connection = $this->getWebSocketConnection('TestOrigin');

        $this->pusherServer->onOpen($connection);

        $connection->assertSentEvent('pusher:connection_established');
    }

    /** @test */
    public function origin_validation_should_fail_for_wrong_origin()
    {
        $this->expectException(OriginNotAllowed::class);

        $connection = $this->getWebSocketConnection('TestOrigin', ['Origin' => 'https://google.ro']);

        $this->pusherServer->onOpen($connection);

        $connection->assertSentEvent('pusher:connection_established');
    }

    /** @test */
    public function origin_validation_should_pass_for_the_right_origin()
    {
        $connection = $this->getWebSocketConnection('TestOrigin', ['Origin' => 'https://test.origin.com']);

        $this->pusherServer->onOpen($connection);

        $connection->assertSentEvent('pusher:connection_established');
    }
}
