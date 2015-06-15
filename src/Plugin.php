<?php
/**
 * Phergie plugin for providing information about YouTube videos (https://github.com/phergie/phergie-irc-plugin-react-youtube)
 *
 * @link https://github.com/phergie/phergie-irc-plugin-react-youtube for the canonical source repository
 * @copyright Copyright (c) 2008-2014 Phergie Development Team (http://phergie.org)
 * @license http://phergie.org/license Simplified BSD License
 * @package Phergie\Irc\Plugin\React\YouTube
 */

namespace Phergie\Irc\Plugin\React\YouTube;

use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;
use Phergie\Irc\Event\EventInterface as Event;
use WyriHaximus\Phergie\Plugin\Http\Request as HttpRequest;

/**
 * Plugin class.
 *
 * @category Phergie
 * @package Phergie\Irc\Plugin\React\YouTube
 */
class Plugin extends AbstractPlugin
{
    const ERR_INVALID_KEY = 1;
    const ERR_INVALID_RESPONSEFORMAT = 2;
    const ERR_INVALID_PUBLISHEDFORMAT = 3;
    const ERR_INVALID_DURATIONFORMAT = 4;

    /**
     * Accepts plugin configuration.
     *
     * Supported keys:
     *
     * key - required Google API key
     *
     * responseFormat - optional pattern used to format video data before sending it
     *
     * publishedFormat - optional date format used for video publish timestamps
     *
     * durationFormat - optional interval format used for video durations
     *
     * @param array $config
     * @throws \DomainException if any settings are invalid
     */
    public function __construct(array $config = array())
    {
        $this->key = $this->getKey($config);
        $this->responseFormat = $this->getResponseFormat($config);
        $this->publishedFormat = $this->getPublishedFormat($config);
        $this->durationFormat = $this->getDurationFormat($config);
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
     * @param \Phergie\Irc\Event\EventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleUrl($url, Event $event, Queue $queue)
    {
        $logger = $this->getLogger();
        $logger->info('handleUrl', array('url' => $url));
        $v = $this->getVideoId($url);
        $logger->info('getVideoId', array('url' => $url, 'v' => $v));
        if (!$v) {
            return;
        }
        $apiUrl = $this->getApiUrl($v);
        $request = $this->getApiRequest($apiUrl, $event, $queue);
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
        $logger = $this->getLogger();
        $parsed = parse_url($url);
        $logger->debug('getVideoId', array('url' => $url, 'parsed' => $parsed));
        switch ($parsed['host']) {
            case 'youtu.be':
                return ltrim($parsed['path'], '/');
            case 'www.youtube.com':
            case 'youtube.com':
                if (!empty($parsed['query'])) {
                    parse_str($parsed['query'], $query);
                    $logger->debug('getVideoId', array('url' => $url, 'query' => $query));
                    if (!empty($query['v'])) {
                        return $query['v'];
                    }
                } elseif (isset($parsed['path']) && substr($parsed['path'], 0, 7) == '/embed/') {
                    $logger->debug('getVideoId', array('url' => $url, 'path' => $parsed['path']));
                    $vId = substr($parsed['path'], 7);
                    if (!empty($vId)) {
                        return $vId;
                    }
                }
        }
        return null;
    }

    /**
     * Derives an API URL to get data for a specified video.
     *
     * @param string $id Video identifier
     * @return string
     */
    protected function getApiUrl($id)
    {
        return 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query(array(
            'id' => $id,
            'key' => $this->key,
            'part' => 'id, snippet, contentDetails, statistics',
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
            'resolveCallback' => function($data) use ($self, $url, $event, $queue) {
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
        $logger = $this->getLogger();
        $json = json_decode($data);
        $logger->info('resolve', array('url' => $url, 'json' => $json));

        if (isset($json->error)) {
            return $logger->warning(
                'Query response contained an error',
                array(
                    'url' => $url,
                    'error' => $json->error,
                )
            );
        }

        $entries = $json->items;
        if (!is_array($entries) || !$entries) {
            return $logger->warning(
                'Query returned no results',
                array('url' => $url)
            );
        }

        $entry = reset($entries);
        $replacements = $this->getReplacements($entry);
        $message = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $this->responseFormat
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
        $link = 'https://youtu.be/' . $entry->id;
        $title = $entry->snippet->title;
        $author = $entry->snippet->channelTitle;
        $published = date($this->publishedFormat, strtotime($entry->snippet->publishedAt));
        $views = number_format($entry->statistics->viewCount, 0);
        $likes = number_format($entry->statistics->likeCount, 0);
        $dislikes = number_format($entry->statistics->dislikeCount, 0);
        $favorites = number_format($entry->statistics->favoriteCount, 0);
        $comments = number_format($entry->statistics->commentCount, 0);
        $durationInterval = new \DateInterval($entry->contentDetails->duration);
        $duration = $durationInterval->format($this->durationFormat);

        return array(
            '%link%' => $link,
            '%title%' => $title,
            '%author%' => $author,
            '%published%' => $published,
            '%views%' => $views,
            '%likes%' => $likes,
            '%dislikes%' => $dislikes,
            '%favorites%' => $favorites,
            '%comments%' => $comments,
            '%duration%' => $duration,
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
     * Extracts a Google API key for interacting with the YouTube API from
     * configuration.
     *
     * @param array $config
     * @return string
     * @throws \DomainException if key setting is invalid
     */
    protected function getKey(array $config)
    {
        if (!isset($config['key']) || !is_string($config['key'])) {
            throw new \DomainException(
                'key must reference a string',
                self::ERR_INVALID_KEY
            );
        }
        return $config['key'];
    }

    /**
     * Extracts a pattern for formatting video data from configuration.
     *
     * @param array $config
     * @return string
     * @throws \DomainException if format setting is invalid
     */
    protected function getResponseFormat(array $config)
    {
        if (isset($config['responseFormat'])) {
            if (!is_string($config['responseFormat'])) {
                throw new \DomainException(
                    'responseFormat must reference a string',
                    self::ERR_INVALID_RESPONSEFORMAT
                );
            }
            return $config['responseFormat'];
        }
        return '[ %link% ] "%title%" by %author%'
            . '; Length %duration%'
            . '; Published %published%'
            . '; Views %views%'
            . '; Likes %likes%';
    }

    /**
     * Extracts a pattern for formatting video publish times from
     * configuration.
     *
     * @param array $config
     * @return string
     * @throws \DomainException if publishedFormat setting is invalid
     */
    protected function getPublishedFormat(array $config)
    {
        if (isset($config['publishedFormat'])) {
            if (!is_string($config['publishedFormat'])) {
                throw new \DomainException(
                    'publishedFormat must reference a string',
                    self::ERR_INVALID_PUBLISHEDFORMAT
                );
            }
            return $config['publishedFormat'];
        }
        return 'n/j/y g:i A';
    }

    /**
     * Extracts a pattern for formatting video durations from configuration.
     *
     * @param array $config
     * @return string
     * @throws \DomainException if durationFormat setting is invalid
     */
    protected function getDurationFormat(array $config)
    {
        if (isset($config['durationFormat'])) {
            if (!is_string($config['durationFormat'])) {
                throw new \DomainException(
                    'durationFormat must reference a string',
                    self::ERR_INVALID_DURATIONFORMAT
                );
            }
            return $config['durationFormat'];
        }
        return '%im%ss';
    }
}
