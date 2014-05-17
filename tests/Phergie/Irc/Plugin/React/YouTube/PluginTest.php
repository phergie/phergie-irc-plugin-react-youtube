<?php
/**
 * Phergie plugin for providing information about YouTube videos (https://github.com/phergie/phergie-irc-plugin-react-youtube)
 *
 * @link https://github.com/phergie/phergie-irc-plugin-react-youtube for the canonical source repository
 * @copyright Copyright (c) 2008-2014 Phergie Development Team (http://phergie.org)
 * @license http://phergie.org/license New BSD License
 * @package Phergie\Irc\Plugin\React\YouTube
 */

namespace Phergie\Irc\Plugin\React\YouTube;

use Phake;
use Phergie\Irc\Plugin\React\Command\CommandEvent as Event;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;

/**
 * Tests for the Plugin class.
 *
 * @category Phergie
 * @package Phergie\Irc\Plugin\React\YouTube
 */
class PluginTest extends \PHPUnit_Framework_TestCase
{


    /**
     * Tests that getSubscribedEvents() returns an array.
     */
    public function testGetSubscribedEvents()
    {
        $plugin = new Plugin;
        $this->assertInternalType('array', $plugin->getSubscribedEvents());
    }
}
