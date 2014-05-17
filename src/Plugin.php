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

use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;
use Phergie\Irc\Bot\React\EventInterface as Event;
use WyriHaximus\Phergie\Plugin\Http\Request as HttpRequest;

/**
 * Plugin class.
 *
 * @category Phergie
 * @package Phergie\Irc\Plugin\React\YouTube
 */
class Plugin extends AbstractPlugin
{
    /**
     * Accepts plugin configuration.
     *
     * Supported keys:
     *
     * format - optional pattern used to format video data before sending it
     *
     * dateFormat - optional date format used for video publish times
     *
     * @param array $config
     */
    public function __construct(array $config = array())
    {
        $this->format = $this->getFormat($config);
        $this->dateFormat = $this->getDateFormat($config);
    }

    /**
     * Indicates that the plugin monitors events for YouTube URLs and a command
     * to perform YouTube searches.
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            'url.host.youtu.be' => 'handleUrl',
            'url.host.youtube.com' => 'handleUrl',
        );
    }

    /**
     * Sends information about YouTube videos back to channels that receive
     * URLs to them.
     *
     * @param string $url
     * @param \Phergie\Irc\Bot\React\EventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleUrl($url, Event $event, Queue $queue)
    {
        $v = $this->getVideoId($url);
        if (!$v) {
            return;
        }
        $url = $this->getVideoUrl($v);
        $request = $this->getApiRequest($url, $event, $queue);
        $this->getEventEmitter()->emit('http.request', array($request));
    }

    /**
     * Extracts a video identifier from a YouTube URL.
     *
     * @param string $url
     * @return string|null Identifier or null if none is found
     */
    protected function getVideoId($url)
    {
        $parsed = parse_url($url);
        switch ($parsed['host']) {
            case 'youtu.be':
                return ltrim($parsed['path'], '/');
            case 'youtube.com':
                parse_str($parsed['query'], $query);
                if (!empty($query['v'])) {
                    return $query['v'];
                }
        }
        return null;
    }

    /**
     * Derives an API URL to get data for a specified video.
     *
     * @param string $query Video identifier or search phrase
     * @return string
     */
    protected function getVideoUrl($query)
    {
        return 'http://gdata.youtube.com/feeds/api/videos?' . http_build_query(array(
            'max-results' => '1',
            'alt' => 'json',
            'q' => $query,
        ));
    }

    /**
     * Returns an API request to get data for a video.
     *
     * @param string $url API request URL
     * @param \Phergie\Irc\Bot\React\EventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    protected function getApiRequest($url, Event $event, Queue $queue)
    {
        $self = $this;
        $request = new HttpRequest(array(
            'url' => $url,
            'resolveCallback' => function($data) use ($self, $event, $queue) {
                $self->resolve($url, $data, $event, $queue);
            },
            'rejectCallback' => function($error) use ($self, $url) {
                $self->reject($url, $error);
            }
        ));
        return $request;
    }

    /**
     * Handles a successful request for video data.
     *
     * @param string $url URL of the request
     * @param string $data Response body
     * @param \Phergie\Irc\EventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function resolve($url, $data, Event $event, Queue $queue)
    {
        $json = json_decode($data);
        $entries = $json->feed->entry;
        if (!$entries) {
            return $this->getLogger()->warning(
                'Query returned no results',
                array('url' => $url)
            );
        }
        $entry = reset($entries);
        $replacements = $this->getReplacements($entry);
        $message = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $this->format
        );
        $queue->ircPrivmsg($event->getSource(), $message);
    }

    /**
     * Returns replacements for pattern segments based on data from a given
     * video data object.
     *
     * @param object $entry
     * @return array
     */
    protected function getReplacements($entry)
    {
        $link = $entry->link[0]->href;
        $title = $entry->title->{'$t'};
        $author = $entry->author[0]->name->{'$t'};
        $seconds = $entry->{'media$group'}->{'yt$duration'}->seconds;
        $published = $entry->published->{'$t'};
        $views = $entry->{'yt$statistics'}->viewCount;
        $rating = $entry->{'gd$rating'}->average;

        $minutes = floor($seconds / 60);
        $seconds = str_pad($seconds % 60, 2, '0', STR_PAD_LEFT);
        $parsed_link = parse_url($link);
        parse_str($parsed_link['query'], $parsed_query);
        $link = 'http://youtu.be/' . $parsed_query['v'];
        $published = date($this->dateFormat, strtotime($published));
        $views = number_format($views, 0);
        $rating = round($rating, 2);

        return array(
            '%link%' => $link,
            '%title%' => $title,
            '%author%' => $author,
            '%minutes%' => $minutes,
            '%seconds%' => $seconds,
            '%published%' => $published,
            '%views%' => $views,
            '%rating%' => $rating,
        );
    }

    /**
     * Handles a failed request for video data.
     *
     * @param string $url URL of the failed request
     * @param string $error Error describing the failure
     */
    public function reject($url, $error)
    {
        $this->getLogger()->warning(
            'Request for video data failed',
            array(
                'url' => $url,
                'error' => $error,
            )
        );
    }

    /**
     * Extracts a pattern for formatting video data from configuration.
     *
     * @param array $config
     * @return string
     * @throws \DomainException if format setting is invalid
     */
    protected function getFormat(array $config)
    {
        if (isset($config['format'])) {
            if (!is_string($config['format'])) {
                throw new \DomainException('format must reference a string');
            }
            return $config['format'];
        }
        return '[ %link% ] "%title%" by %author%'
            . ', Length %minutes%m%seconds%s'
            . ', Published %published%'
            . ', Views %views%'
            . ', Rating %rating%';
    }

    /**
     * Extracts a pattern for formatting video publish times from
     * configuration.
     *
     * @param array $config
     * @return string
     * @throws \DomainException if dateFormat setting is invalid
     */
    protected function getDateFormat(array $config)
    {
        if (isset($config['dateFormat'])) {
            if (!is_string($config['dateFormat'])) {
                throw new \DomainException('dateFormat must reference a string');
            }
            return $config['dateFormat'];
        }
        return 'n/j/y g:i A';
    }
}
