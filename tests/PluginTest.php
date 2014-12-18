<?php
/**
 * Phergie plugin for returning the current or last played song for a user on last.fm (https://github.com/chrismou/phergie-irc-plugin-react-audioscrobbler)
 *
 * @link https://github.com/chrismou/phergie-irc-plugin-react-audioscrobbler for the canonical source repository
 * @copyright Copyright (c) 2014 Chris Chrisostomou (http://mou.me)
 * @license http://phergie.org/license New BSD License
 * @package Chrismou\Phergie\Plugin\Audioscrobbler
 */

namespace Chrismou\Phergie\Tests\Plugin\Audioscrobbler;

use Phake;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;
use Phergie\Irc\Plugin\React\Command\CommandEvent as Event;
use Chrismou\Phergie\Plugin\Audioscrobbler\Plugin;
use Chrismou\Phergie\Plugin\Audioscrobbler\Provider as Provider;

/**
 * Tests for the Plugin class.
 *
 * @category Chrismou
 * @package Chrismou\Phergie\Plugin\Audioscrobbler
 */
class PluginTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Chrismou\Phergie\Plugin\Audioscrobbler\Plugin
     */
    protected $plugin;

    /**
     * @var \Chrismou\Phergie\Plugin\Audioscrobbler\Provider\AudioscrobblerProviderInterface
     */
    protected $provider;

    /**
     * @var \Phergie\Irc\Plugin\React\Command\CommandEvent
     */
    protected $event;

    /**
     * @var \Phergie\Irc\Bot\React\EventQueueInterface
     */
    protected $queue;

    protected function setUp()
    {
        $this->event = $this->getMockEvent();
        $this->queue = $this->getMockQueue();
    }

    /*function testSomething()
    {
        $json = file_get_contents(__DIR__.'/_data/LastfmResults.json');
        $response = json_decode($json);
        var_dump($response->recenttracks->track[0]);
        exit;
    }*/

    /**
     * Tests that getSubscribedEvents() returns an array.
     */
    public function testGetSubscribedEvents()
    {
        $plugin = new Plugin;
        $this->assertInternalType('array', $plugin->getSubscribedEvents());
    }

    public function testLastFmCommand()
    {
        $this->plugin = $this->getPlugin(array('lastfmApiKey'=>'dummykey'));
        $this->provider = $this->plugin->getProvider("lastfm");

        $httpConfig = $this->doCommandTest();
        $this->doResolveTest(file_get_contents(__DIR__.'/_data/LastfmResults.json'), $httpConfig);
        $this->doResolveNoResultsTest(file_get_contents(__DIR__.'/_data/LastfmNoResults.json'), $httpConfig);
        $this->doRejectTest($httpConfig);
        $this->doCommandHelpTest();

    }

    /**
     * Tests handleCommand() is doing what it's supposed to
     * @return array $httpConfig
     */
    protected function doCommandTest()
    {
        Phake::when($this->event)->getCustomParams()->thenReturn(array("chrismou"));
        $this->plugin->handleCommand($this->event, $this->queue, $this->provider);
        Phake::verify($this->plugin->getEventEmitter())->emit('http.request', Phake::capture($httpConfig));
        $this->verifyHttpConfig($httpConfig);
        $request = reset($httpConfig);
        return $request->getConfig();
    }

    /**
     * Tests handleCommandHelp() is doing what it's supposed to
     */
    protected function doCommandHelpTest()
    {
        Phake::when($this->event)->getSource()->thenReturn('#channel');
        Phake::when($this->event)->getCommand()->thenReturn('PRIVMSG');
        Phake::when($this->event)->getCustomParams()->thenReturn(array("chrismou"));

        $this->plugin->handleCommandHelp($this->event, $this->queue, $this->provider);

        $helpLines = $this->provider->getHelpLines();
        $this->assertInternalType('array', $helpLines);

        foreach ((array)$helpLines as $responseLine) {
            Phake::verify($this->queue)->ircPrivmsg('#channel', $responseLine);
        }
    }

    /**
     * Tests handCommand() handles resolveCallback correctly
     *
     * @param string $command
     * @param array $httpConfig
     */
    protected function doResolveTest($data, array $httpConfig)
    {
        $this->doPreCallbackSetup();
        $callback = $httpConfig['resolveCallback'];
        $responseLines = $this->provider->getSuccessLines($this->event, $data);
        $this->doPostCallbackTests($data, $callback, $responseLines);
    }

    /**
     * Tests handCommand() handles resolveCallback correctly
     *
     * @param string $command
     * @param array $httpConfig
     */
    protected function doResolveNoResultsTest($data, array $httpConfig)
    {
        $this->doPreCallbackSetup();
        $callback = $httpConfig['resolveCallback'];
        $responseLines = $this->provider->getSuccessLines($this->event, $data);
        $this->doPostCallbackTests($data, $callback, $responseLines);
    }

    /**
     * Tests handCommand() handles rejectCallback correctly
     *
     * @param array $httpConfig
     */
    protected function doRejectTest(array $httpConfig)
    {
        $error = "Foobar";
        $this->doPreCallbackSetup();
        $callback = $httpConfig['rejectCallback'];
        $responseLines = $this->plugin->getRejectLines($this->event, $error);
        $this->doPostCallbackTests($error, $callback, $responseLines);
    }

    /**
     * Sets mocks pre-callback
     */

    protected function doPreCallbackSetup()
    {
        Phake::when($this->event)->getSource()->thenReturn('#channel');
        Phake::when($this->event)->getCommand()->thenReturn('PRIVMSG');
    }

    /**
     * Sets mocks in preparation for a callback test
     *
     * @param string $data
     * @param callable $callback
     * @param array $responseLines
     */

    protected function doPostCallbackTests($data, $callback, $responseLines)
    {
        // Test we've had an array back and it has at least one response message
        $this->assertInternalType('array', $responseLines);
        $this->assertArrayHasKey(0, $responseLines);

        $this->assertInternalType('callable', $callback);

        // Run the resolveCallback callback
        $callback($data, $this->event, $this->queue);

        // Verify if each expected line was sent
        foreach ($responseLines as $responseLine) {
            Phake::verify($this->queue)->ircPrivmsg($this->event->getSource(), $responseLine);
        }
    }

    /**
     * Verify the http object looks like what we're expecting
     *
     * @param array $httpConfig
     */
    protected function verifyHttpConfig(array $httpConfig)
    {
        // Check we have an array with one element
        $this->assertInternalType('array', $httpConfig);
        $this->assertCount(1, $httpConfig);

        $request = reset($httpConfig);

        // Check we have an instance of the http plugin
        $this->assertInstanceOf('\WyriHaximus\Phergie\Plugin\Http\Request', $request);

        // Check the url stored by http is the same as what we've called
        $this->assertSame($this->provider->getApiRequestUrl($this->event), $request->getUrl());

        // Grab the response config and check the required callbacks exist
        $config = $request->getConfig();
        $this->assertInternalType('array', $config);
        $this->assertArrayHasKey('resolveCallback', $config);
        $this->assertInternalType('callable', $config['resolveCallback']);
        $this->assertArrayHasKey('rejectCallback', $config);
        $this->assertInternalType('callable', $config['rejectCallback']);
    }

    /**
     * Returns a configured instance of the class under test.
     *
     * @param array $config
     *
     * @return \Chrismou\Phergie\Plugin\Audioscrobbler\Plugin
     */
    protected function getPlugin(array $config = array())
    {
        $plugin = new Plugin($config);
        $plugin->setEventEmitter(Phake::mock('\Evenement\EventEmitterInterface'));
        $plugin->setLogger(Phake::mock('\Psr\Log\LoggerInterface'));
        return $plugin;
    }

    /**
     * Returns a mock command event.
     *
     * @return \Phergie\Irc\Plugin\React\Command\CommandEvent
     */
    protected function getMockEvent()
    {
        return Phake::mock('\Phergie\Irc\Plugin\React\Command\CommandEvent');
    }

    /**
     * Returns a mock event queue.
     *
     * @return \Phergie\Irc\Bot\React\EventQueueInterface
     */
    protected function getMockQueue()
    {
        return Phake::mock('\Phergie\Irc\Bot\React\EventQueueInterface');
    }
}
