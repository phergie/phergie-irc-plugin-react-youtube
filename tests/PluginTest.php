<?php
/**
 * Phergie plugin for providing information about YouTube videos (https://github.com/phergie/phergie-irc-plugin-react-youtube)
 *
 * @link https://github.com/phergie/phergie-irc-plugin-react-youtube for the canonical source repository
 * @copyright Copyright (c) 2008-2014 Phergie Development Team (http://phergie.org)
 * @license http://phergie.org/license Simplified BSD License
 * @package Phergie\Irc\Plugin\React\YouTube
 */

namespace Phergie\Irc\Tests\Plugin\React\YouTube;

use Phake;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;
use Phergie\Irc\Plugin\React\Command\CommandEvent as Event;
use Phergie\Irc\Plugin\React\YouTube\Plugin;

/**
 * Tests for the Plugin class.
 *
 * @category Phergie
 * @package Phergie\Irc\Plugin\React\YouTube
 */
class PluginTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Mock event emitter
     *
     * @var \Evenement\EventEmitterInterface
     */
    protected $emitter;

    /**
     * Mock logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Mock event
     *
     * @var \Phergie\Irc\Event\EventInterface
     */
    protected $event;

    /**
     * Mock event queue
     *
     * @var \Phergie\Irc\Bot\React\EventQueueInterface
     */
    protected $queue;

    /**
     * URL for an API request
     *
     * @var string
     */
    protected $requestUrl = 'https://www.googleapis.com/youtube/v3/videos?id=HFuTvTVAO-M&key=KEY&part=id%2C+snippet%2C+contentDetails%2C+statistics';

    /**
     * Creates mock instances for common parameters.
     */
    protected function setUp()
    {
        $this->event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        $this->queue = Phake::mock('\Phergie\Irc\Bot\React\EventQueueInterface');
        $this->emitter = Phake::mock('\Evenement\EventEmitterInterface');
        $this->logger = Phake::mock('\Psr\Log\LoggerInterface');
        $this->plugin = $this->getPlugin();

        date_default_timezone_set('America/Chicago');
    }

    /**
     * Returns a configured instance of the class under test.
     *
     * @param array $config
     * @return \Phergie\Irc\Plugin\React\YouTube\Plugin
     */
    protected function getPlugin(array $config = array())
    {
        $config['key'] = 'KEY';
        $plugin = new Plugin($config);
        $plugin->setEventEmitter($this->emitter);
        $plugin->setLogger($this->logger);
        return $plugin;
    }

    /**
     * Data provider for testInvalidConfiguration().
     *
     * @return array
     */
    public function dataProviderInvalidConfiguration()
    {
        $valid = array('key' => 'KEY');

        $data = array();

        $data[] = array(
            array('key' => 1),
            Plugin::ERR_INVALID_KEY,
        );

        $data[] = array(
            array_merge($valid, array('responseFormat' => 1)),
            Plugin::ERR_INVALID_RESPONSEFORMAT,
        );

        $data[] = array(
            array_merge($valid, array('publishedFormat' => 1)),
            Plugin::ERR_INVALID_PUBLISHEDFORMAT,
        );

        $data[] = array(
            array_merge($valid, array('durationFormat' => 1)),
            Plugin::ERR_INVALID_DURATIONFORMAT,
        );

        return $data;
    }

    /**
     * Tests that an exception is thrown when invalid configuration values are
     * used.
     *
     * @param array $config
     * @param int $error
     * @dataProvider dataProviderInvalidConfiguration
     */
    public function testInvalidConfiguration(array $config, $error)
    {
        try {
            $plugin = new Plugin($config);
        } catch (\DomainException $e) {
            $this->assertSame($error, $e->getCode());
        }
    }

    /**
     * Data provider for testHandleUrl().
     *
     * @return array
     */
    public function dataProviderHandleUrl()
    {
        return array(
            array('http://www.youtube.com/watch?v=HFuTvTVAO-M'),
            array('http://youtube.com/watch?v=HFuTvTVAO-M'),
            array('http://youtu.be/HFuTvTVAO-M'),
        );
    }

    /**
     * Tests handleUrl() with a valid URL for a single video.
     *
     * @param string $url
     * @return array Request configuration, used by other tests
     * @dataProvider dataProviderHandleUrl
     */
    public function testHandleUrlWithVideoUrl($url)
    {
        $this->plugin->handleUrl($url, $this->event, $this->queue);

        Phake::verify($this->emitter)->emit('http.request', Phake::capture($params));

        $this->assertInternalType('array', $params);
        $this->assertCount(1, $params);
        $request = reset($params);
        $this->assertInstanceOf('\WyriHaximus\Phergie\Plugin\Http\Request', $request);
        $this->assertSame($this->requestUrl, $request->getUrl());

        $config = $request->getConfig();
        $this->assertInternalType('array', $config);
        $this->assertArrayHasKey('resolveCallback', $config);
        $this->assertInternalType('callable', $config['resolveCallback']);
        $this->assertArrayHasKey('rejectCallback', $config);
        $this->assertInternalType('callable', $config['rejectCallback']);
        return $config;
    }

    /**
     * Data provider for testHandleUrlWithNonVideoUrl().
     *
     * @return array
     */
    public function dataProviderHandleUrlWithNonVideoUrl()
    {
        return array(
            array('https://www.youtube.com/channel/UCQzdMyuz0Lf4zo4uGcEujFw'),
            array('https://www.youtube.com/results?search_query=foo'),
        );
    }

    /**
     * Tests handleUrl() with a valid URL that is not for a single video.
     *
     * @param string $url
     * @dataProvider dataProviderHandleUrlWithNonVideoUrl
     */
    public function testHandleUrlWithNonVideoUrl($url)
    {
        Phake::verifyNoFurtherInteraction($this->emitter);
        $this->plugin->handleUrl($url, $this->event, $this->queue);
    }

    /**
     * Tests resolve() when an API error occurs.
     */
    public function testResolveWithApiError()
    {
        Phake::verifyNoFurtherInteraction($this->queue);
        $requestConfig = $this->testHandleUrlWithVideoUrl('http://youtu.be/HFuTvTVAO-M');
        $resolve = $requestConfig['resolveCallback'];
        $data = '{"error":"foo"}';
        $resolve($data, $this->event, $this->queue);
        Phake::verify($this->logger)->warning(
            'Query response contained an error',
            array(
                'url' => $this->requestUrl,
                'error' => 'foo',
            )
        );
    }

    /**
     * Tests resolve() when the query returns no results.
     */
    public function testResolveWithNoResults()
    {
        Phake::verifyNoFurtherInteraction($this->queue);
        $requestConfig = $this->testHandleUrlWithVideoUrl('http://youtu.be/HFuTvTVAO-M');
        $resolve = $requestConfig['resolveCallback'];
        $data = file_get_contents(__DIR__ . '/_files/empty.json');
        $resolve($data, $this->event, $this->queue);
        Phake::verify($this->logger)->warning(
            'Query returned no results',
            array(
                'url' => $this->requestUrl,
            )
        );
    }

    /**
     * Data provider for testResolveWithResults().
     *
     * @return array
     */
    public function dataProviderResolveWithResults()
    {
        $data = array();

        $results = array(
            '%link%' => 'https://youtu.be/HFuTvTVAO-M',
            '%title%' => 'Nick Motil - Butterflies (2010)',
            '%author%' => 'Nick Motil',
            '%published%' => '2/7/10 8:09 AM',
            '%views%' => '6,283',
            '%likes%' => '35',
            '%dislikes%' => '0',
            '%favorites%' => '0',
            '%comments%' => '27',
            '%duration%' => '3m30s',
        );

        foreach ($results as $format => $result) {
            $data[] = array(array('responseFormat' => $format), $result);
        }

        $data[] = array(
            array(
                'responseFormat' => '%published%',
                'publishedFormat' => 'Y-m-d H:i:s',
            ),
            '2010-02-07 08:09:51',
        );

        $data[] = array(
            array(
                'responseFormat' => '%duration%',
                'durationFormat' => '%IM%SS',
            ),
            '03M30S',
        );

        return $data;
    }

    /**
     * Tests resolve() when the query returns results.
     *
     * @param array $config
     * @param string $message
     * @dataProvider dataProviderResolveWithResults
     */
    public function testResolveWithResults(array $config, $message)
    {
        $this->plugin = $this->getPlugin($config);
        Phake::when($this->event)->getSource()->thenReturn('#channel');
        $requestConfig = $this->testHandleUrlWithVideoUrl('http://youtu.be/HFuTvTVAO-M');
        $resolve = $requestConfig['resolveCallback'];
        $data = file_get_contents(__DIR__ . '/_files/success.json');
        $resolve($data, $this->event, $this->queue);
        Phake::verify($this->queue)->ircPrivmsg('#channel', $message);
    }

    /**
     * Tests reject().
     */
    public function testReject()
    {
        Phake::verifyNoFurtherInteraction($this->queue);
        $error = 'foo';
        $requestConfig = $this->testHandleUrlWithVideoUrl('http://youtu.be/HFuTvTVAO-M');
        $reject = $requestConfig['rejectCallback'];
        $reject($error);
        Phake::verify($this->logger)->warning(
            'Request for video data failed',
            array(
                'url' => 'https://www.googleapis.com/youtube/v3/videos?id=HFuTvTVAO-M&key=KEY&part=id%2C+snippet%2C+contentDetails%2C+statistics',
                'error' => $error,
            )
        );
    }

    /**
     * Tests that getSubscribedEvents() returns an array.
     */
    public function testGetSubscribedEvents()
    {
        $this->assertInternalType('array', $this->plugin->getSubscribedEvents());
    }
}
